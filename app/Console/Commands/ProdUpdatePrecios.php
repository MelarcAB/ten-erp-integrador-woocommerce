<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesDailyEntityLog;
use App\Helpers\ProveedorStockHelper;
use App\Integrations\TenClient;
use App\Integrations\WooCommerceClient;
use App\Models\Producto;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProdUpdatePrecios extends Command
{
    use WritesDailyEntityLog;
    protected $signature = 'app:prod-update-precios
        {--modified-after=all : Fecha "YYYY-MM-DD HH:MM:SS" o "all"}
        {--items=100000 : Items por página en TEN}
        {--page=0 : Página en TEN}
        {--limit=0 : Límite de productos TEN a procesar (0 = sin límite)}
        {--chunk-size=1000 : Tamaño de chunk para lecturas locales}
        {--batch-size=100 : Tamaño de batch para Woo /products/batch}
        {--enteros : Redondea el precio final al entero superior en vez de acabar en 5 o 9}
        {--dry-run : No actualiza WooCommerce}
    ';

    protected $description = 'Actualiza precios WooCommerce desde TEN o CSV proveedores según el origen del stock, respetando bloqueo remoto.';

    public function handle(): int
    {
        $marker = '[PROD_UPDATE_PRECIOS v6]';
        $dryRun = (bool) $this->option('dry-run');
        $enteros = (bool) $this->option('enteros');
        $items = max(1, (int) $this->option('items'));
        $page = max(0, (int) $this->option('page'));
        $limit = max(0, (int) $this->option('limit'));
        $chunkSize = max(100, (int) $this->option('chunk-size'));
        $batchSize = max(1, min(100, (int) $this->option('batch-size')));

        $this->initDailyEntityLog('precios');
        $this->writeDailyEntityLog($marker . ' start');
        $this->line($marker . ' start');
        Log::info($marker . ' start', [
            'dry_run' => $dryRun,
            'enteros' => $enteros,
            'items' => $items,
            'page' => $page,
            'limit' => $limit,
            'chunk_size' => $chunkSize,
            'batch_size' => $batchSize,
            'modified_after' => $this->option('modified-after'),
        ]);

        try {
            $modifiedAfter = $this->parseModifiedAfter((string) ($this->option('modified-after') ?? 'all'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            $this->writeDailyEntityLog($marker . ' invalid modified-after value=' . $this->option('modified-after'));
            Log::error($marker . ' invalid modified-after', ['value' => $this->option('modified-after')]);
            return self::FAILURE;
        }

        /** @var TenClient $tenClient */
        $tenClient = app(TenClient::class);
        /** @var WooCommerceClient $wooClient */
        $wooClient = app(WooCommerceClient::class);

        try {
            $this->info('Cargando CSV de proveedores para precios...');
            $providerStock = ProveedorStockHelper::load('https://tests.takeoffcomunicacion.es/stock_proveedor.csv');
        } catch (Throwable $e) {
            $this->error('Error proveedor: ' . $e->getMessage());
            $this->writeDailyEntityLog($marker . ' provider failed: ' . $e->getMessage());
            Log::error($marker . ' provider failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        $this->info(
            'Proveedor: filas=' . $providerStock['processed']
            . ' | sku=' . count($providerStock['by_sku'])
            . ' | ean=' . count($providerStock['by_ean'])
            . ' | invalid=' . $providerStock['invalid']
        );

        try {
            $this->info('Llamando a TEN /Stocks/Get ...');
            $tenStocks = $tenClient->getStocks();
        } catch (Throwable $e) {
            $this->error('Error TEN Stocks/Get: ' . $e->getMessage());
            $this->writeDailyEntityLog($marker . ' ten stocks failed: ' . $e->getMessage());
            Log::error($marker . ' ten stocks failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        $tenStockById = [];
        foreach ($tenStocks as $row) {
            if (!is_array($row)) {
                continue;
            }

            $tenId = (int) ($row['IdProducto'] ?? $row['Id'] ?? 0);
            if ($tenId <= 0) {
                continue;
            }

            $tenStockById[$tenId] = max(0, (int) ($row['Stock'] ?? 0));
        }
        $this->info('Stocks TEN cargados: ' . count($tenStockById));

        try {
            $this->info('Llamando a TEN /Products/Get ...');
            $tenProducts = $tenClient->getProducts($modifiedAfter, $items, $page);
        } catch (Throwable $e) {
            $this->error('Error TEN: ' . $e->getMessage());
            $this->writeDailyEntityLog($marker . ' ten failed: ' . $e->getMessage());
            Log::error($marker . ' ten failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        if ($limit > 0) {
            $tenProducts = array_slice($tenProducts, 0, $limit);
        }

        $total = count($tenProducts);
        $this->info("Productos TEN recibidos: {$total}");
        if ($total === 0) {
            return self::SUCCESS;
        }

        $processed = 0;
        $invalidRows = 0;
        $notFoundLocal = 0;
        $noWooId = 0;
        $invalidPrice = 0;
        $remoteReadErrors = 0;
        $priceLocked = 0;
        $queued = 0;
        $tenStockZero = 0;
        $tenStockPositive = 0;
        $priceSourceTen = 0;
        $priceSourceProvider = 0;
        $providerPriceMissing = 0;
        $wooUpdated = 0;
        $wooErrors = 0;
        $batchCount = 0;

        $chunks = array_chunk($tenProducts, $chunkSize);
        $totalChunks = count($chunks);
        $pendingBatch = [];
        $pendingBatchDebug = [];

        foreach ($chunks as $chunkIndex => $chunk) {
            $chunkNum = $chunkIndex + 1;

            $skus = [];
            foreach ($chunk as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $sku = trim((string) ($row['Codigo'] ?? ''));
                if ($sku !== '') {
                    $skus[] = $sku;
                }
            }

            $productosBySku = [];
            foreach (array_chunk(array_values(array_unique($skus)), 1000) as $skuChunk) {
                $productos = Producto::query()
                    ->whereIn('ten_codigo', $skuChunk)
                    ->get(['id', 'ten_codigo', 'woocommerce_id', 'woocommerce_sku', 'ten_ean', 'woocommerce_ean']);

                foreach ($productos as $producto) {
                    $sku = trim((string) ($producto->ten_codigo ?? ''));
                    if ($sku !== '' && !isset($productosBySku[$sku])) {
                        $productosBySku[$sku] = $producto;
                    }
                }
            }

            $wooIds = [];
            foreach ($productosBySku as $producto) {
                $wooId = (int) ($producto->woocommerce_id ?? 0);
                if ($wooId > 0) {
                    $wooIds[] = $wooId;
                }
            }

            $remoteStateByWooId = $this->loadRemoteProductStateMap(
                $wooClient,
                $wooIds,
                $marker,
                $remoteReadErrors
            );

            foreach ($chunk as $row) {
                $processed++;

                if (!is_array($row)) {
                    $invalidRows++;
                    $this->printProgress($processed, $total, $chunkNum, $totalChunks);
                    continue;
                }

                $sku = trim((string) ($row['Codigo'] ?? ''));
                if ($sku === '') {
                    $invalidRows++;
                    $this->printProgress($processed, $total, $chunkNum, $totalChunks);
                    continue;
                }

                $precioBase = $this->toNullableFloat($row['Precio'] ?? null);
                $porcImpost = $this->toNullableFloat($row['PorcImpost'] ?? null);
                if ($precioBase === null || $porcImpost === null) {
                    $invalidPrice++;
                    Log::warning($marker . ' invalid price data', [
                        'sku' => $sku,
                        'precio' => $row['Precio'] ?? null,
                        'porc_impost' => $row['PorcImpost'] ?? null,
                    ]);
                    $this->printProgress($processed, $total, $chunkNum, $totalChunks);
                    continue;
                }

                $productoLocal = $productosBySku[$sku] ?? null;
                if (!$productoLocal) {
                    $notFoundLocal++;
                    $this->printProgress($processed, $total, $chunkNum, $totalChunks);
                    continue;
                }

                $wooId = (int) ($productoLocal->woocommerce_id ?? 0);
                if ($wooId <= 0) {
                    $noWooId++;
                    $this->printProgress($processed, $total, $chunkNum, $totalChunks);
                    continue;
                }

                $remoteState = $remoteStateByWooId[$wooId] ?? null;
                if (!is_array($remoteState)) {
                    Log::warning($marker . ' remote product state missing', [
                        'sku' => $sku,
                        'woo_id' => $wooId,
                    ]);
                    $this->printProgress($processed, $total, $chunkNum, $totalChunks);
                    continue;
                }

                $priceBlocked = (bool) ($remoteState['price_blocked'] ?? false);
                if ($priceBlocked) {
                    $priceLocked++;
                    Log::info($marker . ' price locked, skipping', [
                        'sku' => $sku,
                        'woo_id' => $wooId,
                    ]);
                    $this->printProgress($processed, $total, $chunkNum, $totalChunks);
                    continue;
                }

                $precioConIva = $precioBase * (1 + ($porcImpost / 100));
                $tenId = (int) ($row['Id'] ?? 0);
                $tenStock = $tenStockById[$tenId] ?? 0;
                $stockSource = (string) ($remoteState['stock_source'] ?? '');
                $providerLookupSku = trim((string) ($remoteState['sku'] ?? ''));
                $providerLookupEan = trim((string) ($remoteState['ean'] ?? ''));
                $providerMatch = $this->resolveProviderMatchCandidates(
                    [
                        $providerLookupSku,
                        $providerLookupEan,
                        trim((string) ($productoLocal->woocommerce_sku ?? '')),
                        trim((string) ($productoLocal->ten_codigo ?? '')),
                        trim((string) ($productoLocal->woocommerce_ean ?? '')),
                        trim((string) ($productoLocal->ten_ean ?? '')),
                    ],
                    $providerStock['by_sku'],
                    $providerStock['by_ean']
                );

                $salePrice = '';
                $priceSource = 'ten';
                $priceSourceLabel = 'PRECIO DE TEN';
                $regularPrice = $this->formatTenIntegerPrice($precioConIva);

                if ($tenStock > 0) {
                    $tenStockPositive++;
                } else {
                    $tenStockZero++;
                }

                if ($stockSource === 'provider_csv') {
                    $providerPrice = isset($providerMatch['price']) ? (float) $providerMatch['price'] : null;
                    if ($providerPrice !== null && $providerPrice > 0) {
                        $regularPrice = $this->formatProviderIntegerPrice($providerPrice * 0.9);
                        $priceSource = 'provider_csv';
                        $priceSourceLabel = 'PRECIO DE CSV PROVEEDORES';
                        $priceSourceProvider++;
                    } else {
                        $providerPriceMissing++;
                        Log::warning($marker . ' provider price missing for provider-stocked product', [
                            'sku' => $sku,
                            'woo_id' => $wooId,
                            'stock_source' => $stockSource,
                            'provider_lookup_sku' => $providerLookupSku,
                            'provider_lookup_ean' => $providerLookupEan,
                        ]);
                        $this->printProgress($processed, $total, $chunkNum, $totalChunks);
                        continue;
                    }
                } else {
                    $priceSourceTen++;
                }

                $pendingBatch[] = [
                    'id' => $wooId,
                    'regular_price' => $regularPrice,
                    'sale_price' => $salePrice,
                    'meta_data' => $this->buildInfoMetaData($remoteState['meta_data'] ?? [], [
                        '_takeoff_price_source' => $priceSource,
                        '_takeoff_price_source_label' => $priceSourceLabel,
                    ]),
                ];
                $pendingBatchDebug[] = [
                    'woo_id' => $wooId,
                    'sku' => $sku,
                    'precio_base' => $this->toDecimalString($precioBase),
                    'iva_percent' => $this->toDecimalString($porcImpost),
                    'regular_price' => $regularPrice,
                    'sale_price' => $salePrice,
                    'ten_stock' => $tenStock,
                    'stock_source' => $stockSource,
                    'provider_price' => $providerMatch['price_string'] ?? null,
                    'price_source' => $priceSource,
                    'price_blocked' => $priceBlocked,
                ];
                $queued++;

                if ($dryRun && count($pendingBatchDebug) <= 10) {
                    $this->line(
                        "DRY sku={$sku} woo_id={$wooId} base={$this->toDecimalString($precioBase)} iva={$this->toDecimalString($porcImpost)}"
                        . " regular={$regularPrice} sale=" . ($salePrice !== '' ? $salePrice : '-')
                        . " ten_stock={$tenStock}"
                        . " stock_source=" . ($stockSource !== '' ? $stockSource : '-')
                        . " provider_lookup_sku=" . ($providerLookupSku !== '' ? $providerLookupSku : '-')
                        . " provider_lookup_ean=" . ($providerLookupEan !== '' ? $providerLookupEan : '-')
                        . " provider_price=" . (($providerMatch['price_string'] ?? null) !== null ? $providerMatch['price_string'] : '-')
                        . " price_source={$priceSource}"
                    );
                }

                if (count($pendingBatch) >= $batchSize) {
                    [$ok, $fail] = $this->flushBatch($wooClient, $pendingBatch, $pendingBatchDebug, $marker, $dryRun);
                    $wooUpdated += $ok;
                    $wooErrors += $fail;
                    $batchCount++;
                    $pendingBatch = [];
                    $pendingBatchDebug = [];
                }

                $this->printProgress($processed, $total, $chunkNum, $totalChunks);
            }

            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        if (!empty($pendingBatch)) {
            [$ok, $fail] = $this->flushBatch($wooClient, $pendingBatch, $pendingBatchDebug, $marker, $dryRun);
            $wooUpdated += $ok;
            $wooErrors += $fail;
            $batchCount++;
        }

        $this->info(
            "OK fin. processed={$processed} | queued={$queued} | updated={$wooUpdated}"
            . " | ten_stock_zero={$tenStockZero} | ten_stock_positive={$tenStockPositive}"
            . " | price_source_ten={$priceSourceTen} | price_source_provider={$priceSourceProvider}"
            . " | provider_price_missing={$providerPriceMissing}"
            . " | price_locked={$priceLocked} | remote_read_errors={$remoteReadErrors}"
            . " | local_missing={$notFoundLocal} | no_woo_id={$noWooId}"
            . " | invalid_rows={$invalidRows} | invalid_price={$invalidPrice}"
            . " | batches={$batchCount} | woo_errors={$wooErrors}"
            . ($dryRun ? ' | dry-run=1' : '')
        );

        Log::info($marker . ' done', [
            'processed' => $processed,
            'queued' => $queued,
            'updated' => $wooUpdated,
            'ten_stock_zero' => $tenStockZero,
            'ten_stock_positive' => $tenStockPositive,
            'price_source_ten' => $priceSourceTen,
            'price_source_provider' => $priceSourceProvider,
            'provider_price_missing' => $providerPriceMissing,
            'price_locked' => $priceLocked,
            'remote_read_errors' => $remoteReadErrors,
            'not_found_local' => $notFoundLocal,
            'no_woo_id' => $noWooId,
            'invalid_rows' => $invalidRows,
            'invalid_price' => $invalidPrice,
            'batches' => $batchCount,
            'woo_errors' => $wooErrors,
            'dry_run' => $dryRun,
        ]);
        $this->writeDailyEntityLog(
            "END processed={$processed} queued={$queued} updated={$wooUpdated} ten_stock_zero={$tenStockZero} ten_stock_positive={$tenStockPositive} price_source_ten={$priceSourceTen} price_source_provider={$priceSourceProvider} provider_price_missing={$providerPriceMissing} price_locked={$priceLocked} remote_read_errors={$remoteReadErrors} not_found_local={$notFoundLocal} no_woo_id={$noWooId} invalid_rows={$invalidRows} invalid_price={$invalidPrice} batches={$batchCount} woo_errors={$wooErrors} dry_run=" . ($dryRun ? '1' : '0')
        );

        return $wooErrors > 0 ? self::FAILURE : self::SUCCESS;
    }

    public function __destruct()
    {
        $this->closeDailyEntityLog();
    }

    private function parseModifiedAfter(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '' || strtolower($value) === 'default') {
            return null;
        }

        if (strtolower($value) === 'all') {
            return Carbon::create(2020, 1, 1, 0, 0, 0);
        }

        try {
            return Carbon::createFromFormat('Y-m-d H:i:s', $value);
        } catch (Throwable) {
            throw new \RuntimeException('Formato inválido para --modified-after. Usa "YYYY-MM-DD HH:MM:SS" o "all"');
        }
    }

    private function printProgress(int $processed, int $total, int $chunkNum, int $totalChunks): void
    {
        if ($processed <= 0) {
            return;
        }

        if (($processed % 250) !== 0 && $processed !== $total) {
            return;
        }

        $percent = $total > 0 ? number_format(($processed / $total) * 100, 2, '.', '') : '0.00';
        $this->line("Progreso: {$processed}/{$total} ({$percent}%) | chunk {$chunkNum}/{$totalChunks}");
    }

    /**
     * @param array<int,int> $wooIds
     * @return array<int,array{stock_is_zero:bool,price_blocked:bool,stock_source:string|null,meta_data:array<int,array<string,mixed>>,sku:string,ean:string}>
     */
    private function loadRemoteProductStateMap(
        WooCommerceClient $wooClient,
        array $wooIds,
        string $marker,
        int &$remoteReadErrors
    ): array {
        $stateByWooId = [];
        $wooIds = array_values(array_unique(array_filter(array_map('intval', $wooIds), static fn ($id) => $id > 0)));

        foreach (array_chunk($wooIds, 100) as $wooIdChunk) {
            $foundInBatch = [];

            try {
                $rows = $wooClient->getProductos(count($wooIdChunk), 1, [
                    'include' => implode(',', $wooIdChunk),
                    '_fields' => 'id,sku,global_unique_id,stock_quantity,stock_status,meta_data,precio_bloqueado_ten',
                ]);

                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $wooId = (int) ($row['id'] ?? 0);
                    if ($wooId <= 0) {
                        continue;
                    }

                    $foundInBatch[$wooId] = true;
                    $stateByWooId[$wooId] = [
                        'stock_is_zero' => $this->isRemoteStockZero($row),
                        'price_blocked' => $this->isRemotePriceBlocked($row),
                        'stock_source' => $this->getRemoteStockSource($row),
                        'meta_data' => is_array($row['meta_data'] ?? null) ? $row['meta_data'] : [],
                        'sku' => trim((string) ($row['sku'] ?? '')),
                        'ean' => trim((string) ($row['global_unique_id'] ?? '')),
                    ];
                }
            } catch (Throwable $e) {
                Log::warning($marker . ' remote batch read failed', [
                    'count' => count($wooIdChunk),
                    'error' => $e->getMessage(),
                ]);
            }

            $missingIds = array_values(array_filter(
                $wooIdChunk,
                static fn ($wooId) => !isset($foundInBatch[$wooId])
            ));

            foreach ($missingIds as $wooId) {
                try {
                    $row = $wooClient->getProductoById($wooId);
                    $stateByWooId[$wooId] = [
                        'stock_is_zero' => $this->isRemoteStockZero($row),
                        'price_blocked' => $this->isRemotePriceBlocked($row),
                        'stock_source' => $this->getRemoteStockSource($row),
                        'meta_data' => is_array($row['meta_data'] ?? null) ? $row['meta_data'] : [],
                        'sku' => trim((string) ($row['sku'] ?? '')),
                        'ean' => trim((string) ($row['global_unique_id'] ?? '')),
                    ];
                } catch (Throwable $e) {
                    $remoteReadErrors++;
                    Log::warning($marker . ' remote product fallback read failed', [
                        'woo_id' => $wooId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        return $stateByWooId;
    }

    /**
     * @param array<string,mixed> $remoteProduct
     */
    private function isRemoteStockZero(array $remoteProduct): bool
    {
        $stockQuantity = $remoteProduct['stock_quantity'] ?? null;
        if ($stockQuantity !== null && $stockQuantity !== '') {
            return (float) $stockQuantity <= 0;
        }

        $stockStatus = strtolower(trim((string) ($remoteProduct['stock_status'] ?? '')));
        if ($stockStatus === 'outofstock') {
            return true;
        }

        return false;
    }

    /**
     * @param array<string,mixed> $remoteProduct
     */
    private function isRemotePriceBlocked(array $remoteProduct): bool
    {
        $directValue = $remoteProduct['precio_bloqueado_ten'] ?? null;
        if ($directValue !== null) {
            return $this->toBoolFromMixed($directValue);
        }

        $meta = $remoteProduct['meta_data'] ?? null;
        if (!is_array($meta)) {
            return false;
        }

        foreach ($meta as $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = (string) ($item['key'] ?? '');
            if (!in_array($key, ['precio_bloqueado_ten', '_precio_bloqueado_ten'], true)) {
                continue;
            }

            return $this->toBoolFromMixed($item['value'] ?? null);
        }

        return false;
    }

    /**
     * @param array<string,mixed> $remoteProduct
     */
    private function getRemoteStockSource(array $remoteProduct): ?string
    {
        $meta = $remoteProduct['meta_data'] ?? null;
        if (!is_array($meta)) {
            return null;
        }

        foreach ($meta as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ((string) ($item['key'] ?? '') !== '_takeoff_stock_source') {
                continue;
            }

            $value = trim((string) ($item['value'] ?? ''));
            return $value !== '' ? $value : null;
        }

        return null;
    }

    /**
     * @param array<string,array{stock:int,price:float|null,price_string:string|null}> $bySku
     * @param array<string,array{stock:int,price:float|null,price_string:string|null}> $byEan
     * @return array{found:bool,stock:int|null,price:float|null,price_string:string|null,match:string|null}
     */
    private function resolveProviderMatch(string $sku, string $ean, array $bySku, array $byEan): array
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
                $value = $bySku[$candidate];
                return [
                    'found' => true,
                    'stock' => (int) ($value['stock'] ?? 0),
                    'price' => isset($value['price']) ? (float) $value['price'] : null,
                    'price_string' => $value['price_string'] ?? null,
                    'match' => 'sku',
                ];
            }
        }

        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $byEan)) {
                $value = $byEan[$candidate];
                return [
                    'found' => true,
                    'stock' => (int) ($value['stock'] ?? 0),
                    'price' => isset($value['price']) ? (float) $value['price'] : null,
                    'price_string' => $value['price_string'] ?? null,
                    'match' => 'ean',
                ];
            }
        }

        return [
            'found' => false,
            'stock' => null,
            'price' => null,
            'price_string' => null,
            'match' => null,
        ];
    }

    /**
     * @param array<int,string> $candidates
     * @param array<string,array{stock:int,price:float|null,price_string:string|null}> $bySku
     * @param array<string,array{stock:int,price:float|null,price_string:string|null}> $byEan
     * @return array{found:bool,stock:int|null,price:float|null,price_string:string|null,match:string|null}
     */
    private function resolveProviderMatchCandidates(array $candidates, array $bySku, array $byEan): array
    {
        $normalized = [];
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '' || in_array($candidate, $normalized, true)) {
                continue;
            }

            $normalized[] = $candidate;
        }

        foreach ($normalized as $candidate) {
            if (array_key_exists($candidate, $bySku)) {
                $value = $bySku[$candidate];
                return [
                    'found' => true,
                    'stock' => (int) ($value['stock'] ?? 0),
                    'price' => isset($value['price']) ? (float) $value['price'] : null,
                    'price_string' => $value['price_string'] ?? null,
                    'match' => 'sku',
                ];
            }
        }

        foreach ($normalized as $candidate) {
            if (array_key_exists($candidate, $byEan)) {
                $value = $byEan[$candidate];
                return [
                    'found' => true,
                    'stock' => (int) ($value['stock'] ?? 0),
                    'price' => isset($value['price']) ? (float) $value['price'] : null,
                    'price_string' => $value['price_string'] ?? null,
                    'match' => 'ean',
                ];
            }
        }

        return [
            'found' => false,
            'stock' => null,
            'price' => null,
            'price_string' => null,
            'match' => null,
        ];
    }

    /**
     * @param mixed $metaData
     * @param array<string,string> $values
     * @return array<int,array<string,mixed>>
     */
    private function buildInfoMetaData(mixed $metaData, array $values): array
    {
        $entries = [];
        foreach ($values as $key => $value) {
            $entry = [
                'key' => $key,
                'value' => $value,
            ];

            $existingId = $this->findMetaIdByKey($metaData, $key);
            if ($existingId !== null) {
                $entry['id'] = $existingId;
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @param mixed $metaData
     */
    private function findMetaIdByKey(mixed $metaData, string $key): ?int
    {
        if (!is_array($metaData)) {
            return null;
        }

        foreach ($metaData as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ((string) ($item['key'] ?? '') !== $key) {
                continue;
            }

            $id = (int) ($item['id'] ?? 0);
            return $id > 0 ? $id : null;
        }

        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $batch
     * @param array<int,array<string,mixed>> $batchDebug
     * @return array{0:int,1:int}
     */
    private function flushBatch(
        WooCommerceClient $wooClient,
        array $batch,
        array $batchDebug,
        string $marker,
        bool $dryRun
    ): array {
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
            Log::info($marker . ' batch ok', ['count' => count($batch)]);
            return [count($batch), 0];
        } catch (Throwable $e) {
            $this->warn('Batch falló completo: ' . $e->getMessage());
            Log::warning($marker . ' batch failed', [
                'count' => count($batch),
                'error' => $e->getMessage(),
                'sample' => array_slice($batchDebug, 0, 5),
            ]);
            return [0, count($batch)];
        }
    }

    private function toNullableFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $normalized = $this->normalizeNumberString($value);
        if (!is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    private function toFloat(mixed $value): float
    {
        return $this->toNullableFloat($value) ?? 0.0;
    }

    private function toBoolFromMixed(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
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

    private function toDecimalString(float $value): string
    {
        $formatted = number_format($value, 6, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');
        return $formatted === '' ? '0' : $formatted;
    }

    private function formatTenIntegerPrice(float $value): string
    {
        $integer = (int) ceil($value);
        $lastDigit = $integer % 10;

        if ($lastDigit !== 5 && $lastDigit !== 9) {
            if ($lastDigit < 5) {
                $integer += (5 - $lastDigit);
            } elseif ($lastDigit < 9) {
                $integer += (9 - $lastDigit);
            } else {
                $integer += 5;
            }
        }

        return number_format((float) $integer, 2, '.', '');
    }

    private function formatProviderIntegerPrice(float $value): string
    {
        return number_format((float) round($value, 0, PHP_ROUND_HALF_UP), 2, '.', '');
    }
}
