<?php

namespace App\Console\Commands;

use App\Integrations\TenClient;
use App\Integrations\WooCommerceClient;
use App\Models\Producto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProdSyncStocks extends Command
{
    protected $signature = 'app:prod-sync-stocks
        {--dry-run : No escribe cambios en BD ni Woo}
        {--chunk-size=1000 : Tamaño de chunk para lectura/actualización local}
        {--batch-size=100 : Tamaño de batch para Woo /products/batch (1-100)}
    ';

    protected $description = 'Sincroniza stock desde TEN (/Stocks/Get) hacia BD local y WooCommerce en batch.';

    public function handle(): int
    {
        $marker = '[PROD_SYNC_STOCKS v2]';
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(100, (int) $this->option('chunk-size'));
        $batchSize = max(1, min(100, (int) $this->option('batch-size')));

        $this->line($marker . ' start');
        Log::info($marker . ' start', [
            'dry_run' => $dryRun,
            'chunk_size' => $chunkSize,
            'batch_size' => $batchSize,
        ]);

        /** @var TenClient $tenClient */
        $tenClient = app(TenClient::class);
        /** @var WooCommerceClient $wooClient */
        $wooClient = app(WooCommerceClient::class);

        try {
            $this->info('Llamando a TEN /Stocks/Get ...');
            $stocks = $tenClient->getStocks();
        } catch (Throwable $e) {
            $this->error('Error TEN: ' . $e->getMessage());
            Log::error($marker . ' ten failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        if (!is_array($stocks) || empty($stocks)) {
            $this->info('No hay filas de stock en TEN.');
            return self::SUCCESS;
        }

        $stockByTenId = [];
        $invalidRows = 0;
        foreach ($stocks as $row) {
            if (!is_array($row)) {
                $invalidRows++;
                continue;
            }
            $tenId = trim((string) ($row['IdProducto'] ?? $row['Id'] ?? ''));
            if ($tenId === '' || !is_numeric($tenId)) {
                $invalidRows++;
                continue;
            }
            $stock = max(0, (int) ($row['Stock'] ?? 0));
            // Si TEN devuelve repetidos, prevalece el último valor.
            $stockByTenId[(string) ((int) $tenId)] = $stock;
        }

        $tenIds = array_keys($stockByTenId);
        $totalTenIds = count($tenIds);
        $this->info("Stocks TEN válidos: {$totalTenIds} | inválidos: {$invalidRows}");
        if ($totalTenIds === 0) {
            return self::SUCCESS;
        }

        $now = now();
        $localUpdated = 0;
        $same = 0;
        $notFound = 0;
        $wooQueued = 0;
        $wooUpdated = 0;
        $wooErrors = 0;
        $processed = 0;
        $chunks = array_chunk($tenIds, $chunkSize);
        $totalChunks = count($chunks);

        foreach ($chunks as $chunkIdx => $idsChunk) {
            $chunkNum = $chunkIdx + 1;
            $productos = Producto::query()
                ->whereIn('ten_id', $idsChunk)
                ->get(['id', 'ten_id', 'stock', 'ten_web_control_stock', 'woocommerce_id']);

            $byTenId = [];
            foreach ($productos as $p) {
                $byTenId[(string) ((int) $p->ten_id)] = $p;
            }

            $dbUpdates = [];
            $wooUpdates = [];

            foreach ($idsChunk as $tenId) {
                $processed++;
                $product = $byTenId[$tenId] ?? null;
                if (!$product) {
                    $notFound++;
                    continue;
                }

                $newStock = (int) ($stockByTenId[$tenId] ?? 0);
                $oldStock = (int) ($product->stock ?? 0);
                $oldControl = (bool) ($product->ten_web_control_stock ?? false);
                $needsLocalUpdate = ($oldStock !== $newStock) || !$oldControl;

                if ($needsLocalUpdate) {
                    $dbUpdates[] = [
                        'id' => (int) $product->id,
                        'stock' => $newStock,
                        'ten_web_control_stock' => true,
                        'updated_at' => $now,
                    ];
                    $localUpdated++;
                } else {
                    $same++;
                }

                $wooId = (int) ($product->woocommerce_id ?? 0);
                if ($wooId > 0 && $needsLocalUpdate) {
                    $wooUpdates[] = [
                        'id' => $wooId,
                        'manage_stock' => true,
                        'stock_quantity' => $newStock,
                    ];
                    $wooQueued++;
                }
            }

            if (!$dryRun && !empty($dbUpdates)) {
                Producto::query()->upsert(
                    $dbUpdates,
                    ['id'],
                    ['stock', 'ten_web_control_stock', 'updated_at']
                );
            }

            if (!$dryRun && !empty($wooUpdates)) {
                foreach (array_chunk($wooUpdates, $batchSize) as $batch) {
                    try {
                        $wooClient->updateProductosBatch($batch, false);
                        $wooUpdated += count($batch);
                    } catch (Throwable $e) {
                        $wooErrors += count($batch);
                        $this->warn("Batch Woo falló (size=" . count($batch) . "): " . $e->getMessage());
                        Log::warning($marker . ' woo batch failed', [
                            'batch_size' => count($batch),
                            'error' => $e->getMessage(),
                        ]);
                    }
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }
            }

            $this->line("Chunk {$chunkNum}/{$totalChunks} | procesados {$processed}/{$totalTenIds}");
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        $this->info(
            "OK fin. local_updated={$localUpdated} | same={$same} | not_found={$notFound}"
            . " | woo_queued={$wooQueued} | woo_updated={$wooUpdated} | woo_errors={$wooErrors}"
            . ($dryRun ? ' | dry-run=1' : '')
        );
        Log::info($marker . ' done', [
            'local_updated' => $localUpdated,
            'same' => $same,
            'not_found' => $notFound,
            'woo_queued' => $wooQueued,
            'woo_updated' => $wooUpdated,
            'woo_errors' => $wooErrors,
            'dry_run' => $dryRun,
            'invalid_rows' => $invalidRows,
        ]);

        return $wooErrors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
