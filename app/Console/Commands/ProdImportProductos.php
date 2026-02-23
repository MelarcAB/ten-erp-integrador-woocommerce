<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Integrations\TenClient;
use App\Integrations\Mappers\TenProductMapper;
use App\Models\Producto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProdImportProductos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:prod-import-productos {--modified-after=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Importa productos desde TEN y los integra en la base de datos (producción)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $marker = '[TEN_IMPORT_PROD_PROD v1]';
        $this->line($marker . ' INICIO');
        Log::info($marker . ' INICIO');

        $client = app(TenClient::class);
        $modifiedAfterOpt = $this->option('modified-after') ?? null;
        $modifiedAfter = null;
        if ($modifiedAfterOpt) {
            if ($modifiedAfterOpt === 'all') {
                $modifiedAfter = \Carbon\Carbon::create(2020, 1, 1, 0, 0, 0);
            } else {
                try {
                    $modifiedAfter = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $modifiedAfterOpt);
                } catch (\Throwable) {
                    $this->error('Formato inválido para --modified-after. Usa "YYYY-MM-DD HH:MM:SS" o "all"');
                    Log::error($marker . ' invalid modified-after', ['value' => $modifiedAfterOpt]);
                    return self::FAILURE;
                }
            }
        }
        try {
            $this->info('Llamando a TEN /Products/Get ...');
            $tenProducts = $client->getProducts($modifiedAfter, 100000, 0);
        } catch (Throwable $e) {
            $this->error('Error TEN: ' . $e->getMessage());
            Log::error($marker . ' TEN call failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        $totalFetched = count($tenProducts);
        $this->info("Recibidos: {$totalFetched}");
        Log::info($marker . ' fetched', ['count' => $totalFetched]);
        if ($totalFetched === 0) return self::SUCCESS;

        $now = now();
        $rows = [];
        $skippedNoTenId = 0;
        $skippedBlocked = 0;
        $skippedNoCategoria = 0;
        $categoriaErrors = 0;

        $dbCols = $this->dbColumns();
        $dbColsFlip = array_flip($dbCols);

        foreach ($tenProducts as $tenRow) {
            if (!is_array($tenRow)) continue;
            $attrs = TenProductMapper::toProductoAttributes($tenRow);
            if (!empty($attrs['ten_bloqueado'])) {
                $skippedBlocked++;
                continue;
            }
            if (empty($attrs['ten_id'])) {
                $skippedNoTenId++;
                continue;
            }
            try {
                $catId = $client->getCategoryFromProduct((int) $attrs['ten_id']);
                $attrs['categoria_ten_id'] = $catId;
                if ($catId === null) {
                    $skippedNoCategoria++;
                }
            } catch (Throwable $e) {
                $categoriaErrors++;
                $attrs['categoria_ten_id'] = null;
                Log::warning($marker . ' categoria fetch failed', [
                    'ten_id' => $attrs['ten_id'] ?? null,
                    'message' => $e->getMessage(),
                ]);
            }
            $attrs['woocommerce_id'] = null;
            $attrs['woocommerce_sku'] = null;
            $attrs['woocommerce_ean'] = null;
            $attrs['woocommerce_upc'] = null;
            $attrs['ten_hash'] = TenProductMapper::hashFromAttributes($attrs);
            $attrs['sync_status'] = 'pending';
            $attrs['last_error'] = null;
            $attrs['ten_last_fetched_at'] = $now;
            $attrs['created_at'] = $now;
            $attrs['updated_at'] = $now;
            $rows[] = array_intersect_key($attrs, $dbColsFlip);
        }

        $this->line(
            "Mapeados: " . count($rows)
            . " | sin ten_id: {$skippedNoTenId}"
            . " | bloqueados: {$skippedBlocked}"
            . " | sin categoria: {$skippedNoCategoria}"
            . " | errores categoria: {$categoriaErrors}"
        );
        Log::info($marker . ' mapped', [
            'valid_rows' => count($rows),
            'skipped_no_ten_id' => $skippedNoTenId,
            'skipped_blocked' => $skippedBlocked,
            'skipped_no_categoria' => $skippedNoCategoria,
            'categoria_errors' => $categoriaErrors,
        ]);
        if (count($rows) === 0) return self::SUCCESS;

        // Dedup por ten_id
        $before = count($rows);
        $rows = collect($rows)->keyBy('ten_id')->values()->all();
        $after = count($rows);
        if ($after !== $before) {
            $this->warn("Dedup: {$before} -> {$after} (quitados " . ($before - $after) . ")");
            Log::warning($marker . ' dedup', ['before' => $before, 'after' => $after]);
        }

        // Buscar existentes
        $tenIds = array_map(fn($r) => (int)$r['ten_id'], $rows);
        $existing = [];
        foreach (array_chunk($tenIds, 1000) as $idsChunk) {
            $dbRows = Producto::query()
                ->whereIn('ten_id', $idsChunk)
                ->get(['ten_id', 'ten_hash', 'sync_status'])
                ->all();
            foreach ($dbRows as $p) {
                $existing[(int)$p->ten_id] = [
                    'ten_hash' => (string)($p->ten_hash ?? ''),
                    'sync_status' => (string)($p->sync_status ?? 'pending'),
                ];
            }
        }

        $toUpsert = [];
        $insertCount = 0;
        $updateCount = 0;
        $skipCount = 0;
        $requeuedCount = 0;

        foreach ($rows as $r) {
            $id = (int)$r['ten_id'];
            $newHash = (string)$r['ten_hash'];
            if (!isset($existing[$id])) {
                $toUpsert[] = $r;
                $insertCount++;
                continue;
            }
            $oldHash = $existing[$id]['ten_hash'];
            $oldStatus = $existing[$id]['sync_status'];
            if ($oldHash === $newHash) {
                $skipCount++;
                continue;
            }
            if ($oldStatus === 'synced') {
                $r['sync_status'] = 'pending';
                $requeuedCount++;
            } else {
                $r['sync_status'] = 'pending';
            }
            $toUpsert[] = $r;
            $updateCount++;
        }

        $this->info("Insert: {$insertCount} | Update: {$updateCount} | Skip: {$skipCount} | Requeued(synced->pending): {$requeuedCount}");
        Log::info($marker . ' diff', compact('insertCount','updateCount','skipCount','requeuedCount'));
        if (empty($toUpsert)) {
            $this->info('Nada que insertar/actualizar.');
            return self::SUCCESS;
        }

        $updateColumns = array_values(array_diff(array_keys($toUpsert[0]), ['ten_id', 'created_at']));
        $colsPerRow = count($dbCols);
        $maxPlaceholders = 60000;
        $autoChunk = max(200, (int) floor($maxPlaceholders / max(1, $colsPerRow)));
        $chunkSize = $autoChunk;
        $this->info("Upsert en chunks: {$chunkSize} filas/chunk");
        Log::info($marker . ' chunking', ['chunk_size' => $chunkSize, 'cols_per_row' => $colsPerRow]);
        $total = count($toUpsert);
        $chunks = array_chunk($toUpsert, $chunkSize);
        $this->info("Total a escribir: {$total} | chunks: " . count($chunks));
        $done = 0;
        foreach ($chunks as $i => $chunk) {
            $chunkNum = $i + 1;
            try {
                DB::transaction(function () use ($chunk, $updateColumns) {
                    Producto::upsert($chunk, ['ten_id'], $updateColumns);
                });
            } catch (\Throwable $e) {
                $this->error("Chunk {$chunkNum} falló: " . $e->getMessage());
                Log::error($marker . ' chunk failed', [
                    'chunk' => $chunkNum,
                    'chunk_size' => count($chunk),
                    'message' => $e->getMessage(),
                ]);
                return self::FAILURE;
            }
            $done += count($chunk);
            $this->line("OK chunk {$chunkNum}/" . count($chunks) . " | {$done}/{$total}");
        }
        $this->info("OK: import completado ({$done} escritos).");
        Log::info($marker . ' success', ['written' => $done]);
        return self::SUCCESS;
    }

    private function dbColumns(): array
    {
        return [
            'ten_id',
            'ten_codigo',
            'woocommerce_id',
            'woocommerce_sku',
            'ten_ean',
            'ten_upc',
            'woocommerce_ean',
            'woocommerce_upc',
            'ten_id_grupo_productos',
            'ten_web_nombre',
            'ten_web_descripcion_corta',
            'ten_web_descripcion_larga',
            'ten_web_control_stock',
            'ten_precio',
            'ten_bloqueado',
            'ten_fabricante',
            'ten_referencia',
            'ten_catalogo',
            'ten_prioridad',
            'ten_fraccionar_formato_venta',
            'ten_peso',
            'ten_porc_impost',
            'ten_porc_recargo',
            'categoria_ten_id',
            'ten_last_fetched_at',
            'ten_hash',
            'sync_status',
            'last_error',
            'created_at',
            'updated_at',
            'deleted_at',
        ];
    }
}
