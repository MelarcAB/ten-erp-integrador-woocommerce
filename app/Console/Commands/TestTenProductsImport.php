<?php

namespace App\Console\Commands;

use App\Integrations\TenClient;
use App\Integrations\Mappers\TenProductMapper;
use App\Models\Producto;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TestTenProductsImport extends Command
{
    protected $signature = 'app:test-ten-products-import
        {--modified-after= : Fecha "YYYY-MM-DD HH:MM:SS" (default: hoy - 2 semanas)}
        {--page=0 : Página (default 0)}
        {--items=100000 : Items por página (default 100000)}
        {--dry-run : No escribe en DB}
        {--chunk=0 : Tamaño de chunk fijo (0 = auto)}
    ';

    protected $description = 'Import masivo productos desde TEN (/Products/Get) con upsert por chunks (evita error 1390)';

    public function handle(): int
    {
        $marker = '[TEN_IMPORT_MARKER v4]';
        $this->line($marker . ' start');
        Log::info($marker . ' start');

        $client = app(TenClient::class);

        $modifiedAfterOpt = $this->option('modified-after');
        $page  = (int) $this->option('page');
        $items = (int) $this->option('items');
        $dryRun = (bool) $this->option('dry-run');
        $chunkOpt = (int) $this->option('chunk');

        $modifiedAfter = null;
        if ($modifiedAfterOpt) {
            try {
                $modifiedAfter = Carbon::createFromFormat('Y-m-d H:i:s', $modifiedAfterOpt);
            } catch (Throwable) {
                $this->error('Formato inválido para --modified-after. Usa "YYYY-MM-DD HH:MM:SS"');
                Log::error($marker . ' invalid modified-after', ['value' => $modifiedAfterOpt]);
                return self::FAILURE;
            }
        }

        try {
            $this->info('Llamando a TEN /Products/Get ...');
            $tenProducts = $client->getProducts($modifiedAfter, $items, $page);
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

        $dbCols = $this->dbColumns();
        $dbColsFlip = array_flip($dbCols);

        foreach ($tenProducts as $tenRow) {
            if (!is_array($tenRow)) continue;

            $attrs = TenProductMapper::toProductoAttributes($tenRow);

            if (empty($attrs['ten_id'])) {
                $skippedNoTenId++;
                continue;
            }

            // TEN NO trae Woo => NULL SIEMPRE
            $attrs['woocommerce_id'] = null;
            $attrs['woocommerce_sku'] = null;
            $attrs['woocommerce_ean'] = null;
            $attrs['woocommerce_upc'] = null;

            $attrs['ten_hash'] = TenProductMapper::hashFromAttributes($attrs);
            $attrs['sync_status'] = 'synced';
            $attrs['last_error'] = null;
            $attrs['ten_last_fetched_at'] = $now;

            $attrs['created_at'] = $now;
            $attrs['updated_at'] = $now;

            $rows[] = array_intersect_key($attrs, $dbColsFlip);
        }

        $this->line("Mapeados: " . count($rows) . " | sin ten_id: {$skippedNoTenId}");
        Log::info($marker . ' mapped', ['valid_rows' => count($rows), 'skipped_no_ten_id' => $skippedNoTenId]);

        if (count($rows) === 0) return self::SUCCESS;

        // Dedup por ten_id (por si TEN repite)
        $before = count($rows);
        $rows = collect($rows)->keyBy('ten_id')->values()->all();
        $after = count($rows);
        if ($after !== $before) {
            $this->warn("Dedup: {$before} -> {$after} (quitados " . ($before - $after) . ")");
            Log::warning($marker . ' dedup', ['before' => $before, 'after' => $after]);
        }

        if ($dryRun) {
            $this->warn('DRY RUN: no se escribirá en DB.');
            $this->line('Ejemplo: ' . json_encode($rows[0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        // Columnas que se actualizan (no tocar created_at ni ten_id)
        $updateColumns = array_values(array_diff(array_keys($rows[0]), ['ten_id', 'created_at']));

        // --- CHUNK SIZE AUTO para evitar "too many placeholders" ---
        // MySQL placeholders ~ 65535; dejamos margen.
        $colsPerRow = count($dbCols); // aprox, suficiente para el cálculo
        $maxPlaceholders = 60000;     // margen
        $autoChunk = max(200, (int) floor($maxPlaceholders / max(1, $colsPerRow)));

        $chunkSize = $chunkOpt > 0 ? $chunkOpt : $autoChunk;

        $this->info("Upsert en chunks: {$chunkSize} filas/chunk (cols={$colsPerRow}, auto={$autoChunk})");
        Log::info($marker . ' chunking', ['chunk_size' => $chunkSize, 'cols_per_row' => $colsPerRow, 'auto' => $autoChunk]);

        $total = count($rows);
        $chunks = array_chunk($rows, $chunkSize);
        $this->info("Total filas: {$total} | chunks: " . count($chunks));

        $done = 0;

        foreach ($chunks as $i => $chunk) {
            $chunkNum = $i + 1;

            try {
                DB::transaction(function () use ($chunk, $updateColumns) {
                    Producto::upsert($chunk, ['ten_id'], $updateColumns);
                });
            } catch (QueryException $e) {
                $this->error("Chunk {$chunkNum} petó: " . $e->getMessage());
                Log::error($marker . ' chunk failed', [
                    'chunk' => $chunkNum,
                    'chunk_size' => count($chunk),
                    'message' => $e->getMessage(),
                    'sql' => $e->getSql(),
                ]);
                return self::FAILURE;
            } catch (Throwable $e) {
                $this->error("Chunk {$chunkNum} petó: " . $e->getMessage());
                Log::error($marker . ' chunk failed (throwable)', [
                    'chunk' => $chunkNum,
                    'chunk_size' => count($chunk),
                    'message' => $e->getMessage(),
                ]);
                return self::FAILURE;
            }

            $done += count($chunk);
            $this->line("OK chunk {$chunkNum}/" . count($chunks) . " | {$done}/{$total}");
        }

        $this->info("OK: import completado ({$done} filas).");
        Log::info($marker . ' success', ['rows' => $done]);

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
