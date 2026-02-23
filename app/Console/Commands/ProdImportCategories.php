<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Integrations\TenClient;
use App\Integrations\Mappers\TenCategoryMapper;
use App\Models\Categoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProdImportCategories extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:prod-import-categories';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Importa categorías desde TEN y las integra en la base de datos (producción)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $marker = '[TEN_CAT_PROD_IMPORT v1]';
        $this->line($marker . ' INICIO');
        Log::info($marker . ' INICIO');

        $client = app(TenClient::class);
        try {
            $this->info('Llamando a TEN para obtener categorías...');
            $tenCats = $client->getCategorias(100000); // Límite alto por seguridad
        } catch (Throwable $e) {
            $this->error('Error al llamar a TEN: ' . $e->getMessage());
            Log::error($marker . ' Error TEN', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        $totalFetched = count($tenCats);
        $this->info("Recibidas: {$totalFetched} categorías");
        Log::info($marker . ' recibidas', ['count' => $totalFetched]);
        if ($totalFetched === 0) return self::SUCCESS;

        $now = now();
        $rows = [];
        $skippedNoTenId = 0;
        $dbCols = $this->dbColumns();
        $dbColsFlip = array_flip($dbCols);

        foreach ($tenCats as $tenRow) {
            if (!is_array($tenRow)) continue;
            $attrs = TenCategoryMapper::toCategoriaAttributes($tenRow);
            if (empty($attrs['ten_id_numero'])) {
                $skippedNoTenId++;
                continue;
            }
            unset($attrs['woocommerce_categoria_id'], $attrs['woocommerce_categoria_padre_id']);
            $attrs['ten_hash'] = TenCategoryMapper::hashFromAttributes($attrs);
            $attrs['sync_status'] = 'pending';
            $attrs['last_error'] = null;
            $attrs['ten_last_fetched_at'] = $now;
            $attrs['created_at'] = $now;
            $attrs['updated_at'] = $now;
            $row = array_intersect_key($attrs, $dbColsFlip);
            unset($row['enable_sync']);
            $rows[] = $row;
        }

        $this->line("Mapeadas: " . count($rows) . " | sin ten_id_numero: {$skippedNoTenId}");
        Log::info($marker . ' mapeadas', ['valid_rows' => count($rows), 'skipped_no_ten_id_numero' => $skippedNoTenId]);
        if (count($rows) === 0) return self::SUCCESS;

        // Deduplicar por ten_id_numero
        $rows = collect($rows)->keyBy('ten_id_numero')->values()->all();

        // Buscar existentes
        $tenIds = array_map(fn ($r) => (int) $r['ten_id_numero'], $rows);
        $existing = [];
        foreach (array_chunk($tenIds, 1000) as $idsChunk) {
            $dbRows = Categoria::query()
                ->whereIn('ten_id_numero', $idsChunk)
                ->get(['ten_id_numero', 'ten_hash', 'sync_status'])
                ->all();
            foreach ($dbRows as $c) {
                $existing[(int) $c->ten_id_numero] = [
                    'ten_hash' => (string) ($c->ten_hash ?? ''),
                    'sync_status' => (string) ($c->sync_status ?? 'pending'),
                ];
            }
        }

        $toUpsert = [];
        $insertCount = 0;
        $updateCount = 0;
        $skipCount = 0;
        $requeuedCount = 0;

        foreach ($rows as $r) {
            $id = (int) $r['ten_id_numero'];
            $newHash = (string) $r['ten_hash'];
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
            $r['sync_status'] = 'pending';
            if ($oldStatus === 'synced') {
                $requeuedCount++;
            } else {
                // ya está pending o error, solo actualiza
            }
            $toUpsert[] = $r;
            $updateCount++;
        }

        $this->info("Insertados: {$insertCount} | Actualizados: {$updateCount} | Omitidos: {$skipCount} | Requeued: {$requeuedCount}");
        Log::info($marker . ' diff', compact('insertCount', 'updateCount', 'skipCount', 'requeuedCount'));
        if (empty($toUpsert)) {
            $this->info('Nada que insertar/actualizar.');
            return self::SUCCESS;
        }

        $updateColumns = array_values(array_diff(array_keys($toUpsert[0]), ['ten_id_numero', 'created_at']));
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
                    Categoria::upsert($chunk, ['ten_id_numero'], $updateColumns);
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
            'ten_id_numero',
            'ten_codigo',
            'woocommerce_categoria_id',
            'woocommerce_categoria_padre_id',
            'sync_status',
            'enable_sync',
            'ten_nombre',
            'ten_web_nombre',
            'ten_categoria_padre',
            'ten_ultimo_usuario',
            'ten_ultimo_cambio',
            'ten_alta_usuario',
            'ten_alta_fecha',
            'ten_web_sincronizar',
            'ten_bloqueado',
            'ten_usr_peso',
            'ten_last_fetched_at',
            'ten_hash',
            'last_error',
            'created_at',
            'updated_at',
            'deleted_at',
        ];
    }
}
