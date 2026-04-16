<?php

namespace App\Console\Commands;

use App\Integrations\TenClient;
use App\Integrations\WooCommerceClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProdSyncStocks extends Command
{
    protected $signature = 'app:prod-sync-stocks
        {--dry-run : No escribe cambios en Woo}
        {--chunk-size=1000 : Tamaño de chunk interno para construir mapas TEN}
        {--batch-size=100 : Tamaño de batch para Woo /products/batch (1-100)}
        {--woo-per-page=100 : Tamaño de página al leer productos de Woo (1-100)}
        {--ten-only : Solo aplica TEN, sin validar stock de proveedores}
        {--provider-url=https://tests.takeoffcomunicacion.es/stock_proveedor.csv : URL del CSV/API de proveedores}
    ';

    protected $description = 'Sincroniza stock final en WooCommerce con prioridad TEN y fallback proveedor, dejando intactos los productos gestionados solo en Woo.';

    public function handle(): int
    {
        $marker = '[PROD_SYNC_STOCKS v4]';
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(100, (int) $this->option('chunk-size'));
        $batchSize = max(1, min(100, (int) $this->option('batch-size')));
        $wooPerPage = max(1, min(100, (int) $this->option('woo-per-page')));
        $tenOnly = (bool) $this->option('ten-only');
        $providerUrl = trim((string) $this->option('provider-url'));

        $this->line($marker . ' start');
        Log::info($marker . ' start', [
            'dry_run' => $dryRun,
            'chunk_size' => $chunkSize,
            'batch_size' => $batchSize,
            'woo_per_page' => $wooPerPage,
            'ten_only' => $tenOnly,
            'provider_url' => $providerUrl,
        ]);

        /** @var TenClient $tenClient */
        $tenClient = app(TenClient::class);
        /** @var WooCommerceClient $wooClient */
        $wooClient = app(WooCommerceClient::class);

        try {
            $this->info('Paso 1/3: Llamando a TEN /Stocks/Get ...');
            $stocks = $tenClient->getStocks();
        } catch (Throwable $e) {
            $this->error('Error TEN Stocks/Get: ' . $e->getMessage());
            Log::error($marker . ' ten stocks failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        if (!is_array($stocks) || empty($stocks)) {
            $this->info('No hay filas de stock en TEN.');
            return self::SUCCESS;
        }

        $stockByTenId = [];
        $invalidTenStockRows = 0;
        foreach ($stocks as $row) {
            if (!is_array($row)) {
                $invalidTenStockRows++;
                continue;
            }

            $tenId = trim((string) ($row['IdProducto'] ?? $row['Id'] ?? ''));
            if ($tenId === '' || !is_numeric($tenId)) {
                $invalidTenStockRows++;
                continue;
            }

            $stockByTenId[(int) $tenId] = max(0, (int) ($row['Stock'] ?? 0));
        }

        $this->info('Stocks TEN válidos: ' . count($stockByTenId) . ' | inválidos: ' . $invalidTenStockRows);
        if (empty($stockByTenId)) {
            return self::SUCCESS;
        }

        try {
            $this->info('Paso 2/3: Llamando a TEN /Products/Get para mapear Id -> SKU/EAN ...');
            $tenProducts = $tenClient->getProducts(Carbon::create(2020, 1, 1, 0, 0, 0), 100000, 0);
        } catch (Throwable $e) {
            $this->error('Error TEN Products/Get: ' . $e->getMessage());
            Log::error($marker . ' ten products failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        $tenStockBySku = [];
        $tenStockByEan = [];
        $tenMappedProducts = 0;
        $tenProductsInvalid = 0;

        foreach (array_chunk($tenProducts, $chunkSize) as $chunk) {
            foreach ($chunk as $row) {
                if (!is_array($row)) {
                    $tenProductsInvalid++;
                    continue;
                }

                $tenId = (int) ($row['Id'] ?? 0);
                if ($tenId <= 0 || !array_key_exists($tenId, $stockByTenId)) {
                    continue;
                }

                $stock = (int) $stockByTenId[$tenId];
                $sku = trim((string) ($row['Codigo'] ?? ''));
                $ean = trim((string) ($row['EAN'] ?? $row['Ean'] ?? ''));

                if ($sku !== '') {
                    $tenStockBySku[$sku] = $stock;
                }
                if ($ean !== '') {
                    $tenStockByEan[$ean] = $stock;
                }
                if ($sku !== '' || $ean !== '') {
                    $tenMappedProducts++;
                }
            }

            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        $this->info(
            'TEN mapa: sku=' . count($tenStockBySku)
            . ' | ean=' . count($tenStockByEan)
            . ' | productos_mapeados=' . $tenMappedProducts
            . ' | invalid_rows=' . $tenProductsInvalid
        );

        $providerStock = [
            'by_sku' => [],
            'by_ean' => [],
            'processed' => 0,
            'invalid' => 0,
        ];

        if (!$tenOnly) {
            try {
                $this->info('Paso 3/3: Descargando y normalizando stock de proveedores...');
                $providerStock = $this->loadProviderStockMaps($providerUrl);
                $this->info(
                    'Proveedor: filas=' . $providerStock['processed']
                    . ' | sku=' . count($providerStock['by_sku'])
                    . ' | ean=' . count($providerStock['by_ean'])
                    . ' | invalid=' . $providerStock['invalid']
                );
            } catch (Throwable $e) {
                $this->error('Error proveedor: ' . $e->getMessage());
                Log::error($marker . ' provider failed', ['error' => $e->getMessage(), 'url' => $providerUrl]);
                return self::FAILURE;
            }
        }

        $processedWoo = 0;
        $queued = 0;
        $updated = 0;
        $wooErrors = 0;
        $tenPositive = 0;
        $tenZeroProviderPositive = 0;
        $tenZeroBlocked = 0;
        $providerOnlyPositive = 0;
        $providerOnlyZeroBlocked = 0;
        $untouchedWooOnly = 0;
        $matchedBySku = 0;
        $matchedByEan = 0;
        $page = 1;
        $pendingBatch = [];

        while (true) {
            try {
                $wooProducts = $wooClient->getProductos($wooPerPage, $page, [
                    '_fields' => 'id,sku,global_unique_id',
                ]);
            } catch (Throwable $e) {
                $this->error('Error Woo al listar productos: ' . $e->getMessage());
                Log::error($marker . ' woo list failed', ['page' => $page, 'error' => $e->getMessage()]);
                return self::FAILURE;
            }

            if (empty($wooProducts)) {
                break;
            }

            foreach ($wooProducts as $wooProduct) {
                if (!is_array($wooProduct)) {
                    continue;
                }

                $processedWoo++;
                $wooId = (int) ($wooProduct['id'] ?? 0);
                if ($wooId <= 0) {
                    continue;
                }

                $sku = trim((string) ($wooProduct['sku'] ?? ''));
                $ean = trim((string) ($wooProduct['global_unique_id'] ?? ''));

                $tenMatch = $this->resolveStockMatch($sku, $ean, $tenStockBySku, $tenStockByEan);
                $providerMatch = $tenOnly
                    ? ['found' => false, 'stock' => null, 'match' => null]
                    : $this->resolveStockMatch($sku, $ean, $providerStock['by_sku'], $providerStock['by_ean']);

                $payload = null;
                $decision = null;

                if ($tenMatch['found']) {
                    if ($tenMatch['match'] === 'sku') {
                        $matchedBySku++;
                    } else {
                        $matchedByEan++;
                    }

                    $tenStock = (int) ($tenMatch['stock'] ?? 0);
                    if ($tenStock > 0) {
                        $tenPositive++;
                        $decision = 'ten_positive';
                        $payload = $this->buildWooStockPayload($wooId, $tenStock, 'instock', 'no');
                    } elseif ($providerMatch['found'] && (int) ($providerMatch['stock'] ?? 0) > 0) {
                        if ($providerMatch['match'] === 'sku') {
                            $matchedBySku++;
                        } else {
                            $matchedByEan++;
                        }
                        $tenZeroProviderPositive++;
                        $decision = 'ten_zero_provider_positive';
                        $payload = $this->buildWooStockPayload($wooId, 0, 'onbackorder', 'yes');
                    } else {
                        if ($providerMatch['found']) {
                            if ($providerMatch['match'] === 'sku') {
                                $matchedBySku++;
                            } else {
                                $matchedByEan++;
                            }
                        }
                        $tenZeroBlocked++;
                        $decision = 'ten_zero_blocked';
                        $payload = $this->buildWooStockPayload($wooId, 0, 'outofstock', 'no');
                    }
                } elseif ($providerMatch['found']) {
                    if ($providerMatch['match'] === 'sku') {
                        $matchedBySku++;
                    } else {
                        $matchedByEan++;
                    }

                    $providerStockValue = (int) ($providerMatch['stock'] ?? 0);
                    if ($providerStockValue > 0) {
                        $providerOnlyPositive++;
                        $decision = 'provider_only_positive';
                        $payload = $this->buildWooStockPayload($wooId, 0, 'onbackorder', 'yes');
                    } else {
                        $providerOnlyZeroBlocked++;
                        $decision = 'provider_only_zero_blocked';
                        $payload = $this->buildWooStockPayload($wooId, 0, 'outofstock', 'no');
                    }
                } else {
                    $untouchedWooOnly++;
                    $decision = 'untouched_woo_only';
                }

                if ($payload === null) {
                    if ($processedWoo <= 20 || ($processedWoo % 500) === 0) {
                        $this->line("SKIP woo_id={$wooId} sku={$sku} ean={$ean} decision={$decision}");
                    }
                    continue;
                }

                $pendingBatch[] = $payload;
                $queued++;

                if ($dryRun && ($queued <= 20 || ($queued % 500) === 0)) {
                    $this->line(
                        "DRY woo_id={$wooId} sku={$sku} ean={$ean}"
                        . " decision={$decision}"
                        . " qty={$payload['stock_quantity']}"
                        . " status={$payload['stock_status']}"
                        . " backorders={$payload['backorders']}"
                    );
                }

                if (count($pendingBatch) >= $batchSize) {
                    [$ok, $fail] = $this->flushBatch($wooClient, $pendingBatch, $dryRun, $marker);
                    $updated += $ok;
                    $wooErrors += $fail;
                    $pendingBatch = [];
                }
            }

            $this->line(
                "Woo page {$page} | procesados={$processedWoo}"
                . " | queued={$queued}"
                . " | ten>0={$tenPositive}"
                . " | ten0+prov>0={$tenZeroProviderPositive}"
                . " | ten0_block={$tenZeroBlocked}"
                . " | prov_only>0={$providerOnlyPositive}"
                . " | prov_only_block={$providerOnlyZeroBlocked}"
                . " | untouched={$untouchedWooOnly}"
            );

            $page++;
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        if (!empty($pendingBatch)) {
            [$ok, $fail] = $this->flushBatch($wooClient, $pendingBatch, $dryRun, $marker);
            $updated += $ok;
            $wooErrors += $fail;
        }

        $this->info(
            "OK fin. woo_processed={$processedWoo} | queued={$queued} | updated={$updated} | woo_errors={$wooErrors}"
            . " | ten_positive={$tenPositive} | ten_zero_provider_positive={$tenZeroProviderPositive}"
            . " | ten_zero_blocked={$tenZeroBlocked} | provider_only_positive={$providerOnlyPositive}"
            . " | provider_only_zero_blocked={$providerOnlyZeroBlocked} | untouched_woo_only={$untouchedWooOnly}"
            . ($dryRun ? ' | dry-run=1' : '')
        );

        Log::info($marker . ' done', [
            'woo_processed' => $processedWoo,
            'queued' => $queued,
            'updated' => $updated,
            'woo_errors' => $wooErrors,
            'ten_positive' => $tenPositive,
            'ten_zero_provider_positive' => $tenZeroProviderPositive,
            'ten_zero_blocked' => $tenZeroBlocked,
            'provider_only_positive' => $providerOnlyPositive,
            'provider_only_zero_blocked' => $providerOnlyZeroBlocked,
            'untouched_woo_only' => $untouchedWooOnly,
            'matched_by_sku' => $matchedBySku,
            'matched_by_ean' => $matchedByEan,
            'dry_run' => $dryRun,
            'invalid_ten_stock_rows' => $invalidTenStockRows,
            'ten_products_invalid' => $tenProductsInvalid,
            'ten_only' => $tenOnly,
            'provider_url' => $providerUrl,
        ]);

        return $wooErrors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Busca los identificadores candidatos en ambos mapas.
     * Regla: tanto SKU como EAN del producto pueden resolver contra
     * "modelo" (bySku) o "ean" (byEan) del origen.
     *
     * @return array{found:bool,stock:int|null,match:string|null}
     */
    private function resolveStockMatch(string $sku, string $ean, array $bySku, array $byEan): array
    {
        $candidates = [];
        if ($sku !== '') {
            $candidates[] = $sku;
        }
        if ($ean !== '' && $ean !== $sku) {
            $candidates[] = $ean;
        }

        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $bySku)) {
                return [
                    'found' => true,
                    'stock' => (int) $bySku[$candidate],
                    'match' => 'sku',
                ];
            }
        }

        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $byEan)) {
                return [
                    'found' => true,
                    'stock' => (int) $byEan[$candidate],
                    'match' => 'ean',
                ];
            }
        }

        return [
            'found' => false,
            'stock' => null,
            'match' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildWooStockPayload(int $wooId, int $stockQuantity, string $stockStatus, string $backorders): array
    {
        return [
            'id' => $wooId,
            'manage_stock' => true,
            'stock_quantity' => max(0, $stockQuantity),
            'stock_status' => $stockStatus,
            'backorders' => $backorders,
        ];
    }

    /**
     * @return array{by_sku:array<string,int>,by_ean:array<string,int>,processed:int,invalid:int}
     */
    private function loadProviderStockMaps(string $url): array
    {
        if ($url === '') {
            throw new \RuntimeException('La URL del proveedor está vacía');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'stock_prov_');
        if ($tmp === false) {
            throw new \RuntimeException('No se pudo crear archivo temporal para proveedor');
        }

        try {
            $response = Http::timeout(60)->get($url);
            if (!$response->successful()) {
                throw new \RuntimeException('Error al descargar CSV proveedor. HTTP ' . $response->status());
            }

            if (file_put_contents($tmp, $response->body()) === false) {
                throw new \RuntimeException('No se pudo escribir el CSV proveedor en disco');
            }

            $handle = fopen($tmp, 'r');
            if ($handle === false) {
                throw new \RuntimeException('No se pudo abrir el CSV proveedor');
            }

            try {
                $header = fgetcsv($handle, 0, ';');
                if (!is_array($header)) {
                    throw new \RuntimeException('No se pudieron leer los headers del proveedor');
                }

                $map = $this->mapHeaders($header);
                if (!isset($map['MODELO'], $map['STOCK'])) {
                    throw new \RuntimeException('Faltan columnas obligatorias proveedor: MODELO, STOCK');
                }

                $hasEan = isset($map['EAN']);
                $bySku = [];
                $byEan = [];
                $processed = 0;
                $invalid = 0;

                while (($row = fgetcsv($handle, 0, ';')) !== false) {
                    $processed++;
                    if (!is_array($row)) {
                        $invalid++;
                        continue;
                    }

                    $sku = $this->getCol($row, $map['MODELO']);
                    $ean = $hasEan ? $this->getCol($row, $map['EAN']) : '';
                    $stock = $this->toInt($this->getCol($row, $map['STOCK']));

                    if ($sku === '' && $ean === '') {
                        $invalid++;
                        continue;
                    }

                    if ($sku !== '') {
                        $bySku[$sku] = $stock;
                    }
                    if ($ean !== '') {
                        $byEan[$ean] = $stock;
                    }
                }
            } finally {
                fclose($handle);
            }

            return [
                'by_sku' => $bySku,
                'by_ean' => $byEan,
                'processed' => $processed,
                'invalid' => $invalid,
            ];
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @param array<int,string> $header
     * @return array<string,int>
     */
    private function mapHeaders(array $header): array
    {
        $map = [];
        foreach ($header as $i => $h) {
            $key = strtoupper(trim((string) $h));
            if ($key !== '') {
                $map[$key] = (int) $i;
            }
        }
        return $map;
    }

    private function getCol(array $row, int $idx): string
    {
        $val = $row[$idx] ?? '';
        return trim((string) $val);
    }

    private function toInt(string $val): int
    {
        $val = trim($val);
        if ($val === '') {
            return 0;
        }

        $norm = $this->normalizeNumberString($val);
        return max(0, (int) round((float) $norm));
    }

    private function normalizeNumberString(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '0';
        }

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
            return $value;
        }

        if (str_contains($value, ',')) {
            return str_replace(',', '.', $value);
        }

        return $value;
    }

    /**
     * @param array<int,array<string,mixed>> $batch
     * @return array{0:int,1:int}
     */
    private function flushBatch(WooCommerceClient $wooClient, array $batch, bool $dryRun, string $marker): array
    {
        if (empty($batch)) {
            return [0, 0];
        }

        if ($dryRun) {
            $this->line('Batch dry-run: ' . count($batch) . ' productos');
            return [count($batch), 0];
        }

        try {
            $wooClient->updateProductosBatch($batch, false);
            $this->line('Batch enviado: ' . count($batch) . ' productos');
            return [count($batch), 0];
        } catch (Throwable $e) {
            Log::warning($marker . ' batch request failed', [
                'count' => count($batch),
                'error' => $e->getMessage(),
            ]);
            $this->warn('Batch falló completo: ' . $e->getMessage());
            return [0, count($batch)];
        }
    }
}
