<?php

namespace App\Console\Commands;

use App\Helpers\DescuentosMarcaHelper;
use App\Integrations\TenClient;
use App\Integrations\WooCommerceClient;
use App\Models\Fabricante;
use App\Models\Producto;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProdUpdatePrecios extends Command
{
    protected $signature = 'app:prod-update-precios
        {--modified-after=all : Fecha "YYYY-MM-DD HH:MM:SS" o "all"}
        {--items=100000 : Items por página en TEN}
        {--page=0 : Página en TEN}
        {--limit=0 : Límite de productos TEN a procesar (0 = sin límite)}
        {--chunk-size=1000 : Tamaño de chunk para lecturas locales}
        {--batch-size=100 : Tamaño de batch para Woo /products/batch}
        {--dry-run : No actualiza WooCommerce}
    ';

    protected $description = 'Actualiza precios WooCommerce desde TEN aplicando IVA y descuentos por marca según stock real y bloqueo remoto.';

    public function handle(): int
    {
        $marker = '[PROD_UPDATE_PRECIOS v2]';
        $dryRun = (bool) $this->option('dry-run');
        $items = max(1, (int) $this->option('items'));
        $page = max(0, (int) $this->option('page'));
        $limit = max(0, (int) $this->option('limit'));
        $chunkSize = max(100, (int) $this->option('chunk-size'));
        $batchSize = max(1, min(100, (int) $this->option('batch-size')));

        $this->line($marker . ' start');
        Log::info($marker . ' start', [
            'dry_run' => $dryRun,
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
            Log::error($marker . ' invalid modified-after', ['value' => $this->option('modified-after')]);
            return self::FAILURE;
        }

        try {
            $this->info('Cargando descuentos por marca...');
            $descuentos = DescuentosMarcaHelper::getDescuentos();
        } catch (Throwable $e) {
            $this->error('Error obteniendo descuentos por marca: ' . $e->getMessage());
            Log::error($marker . ' descuentos failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        $descuentoByWooBrandId = [];
        foreach ($descuentos as $row) {
            if (!is_array($row)) {
                continue;
            }

            $wooBrandId = (int) ($row['id'] ?? 0);
            if ($wooBrandId <= 0) {
                continue;
            }

            $descuentoByWooBrandId[$wooBrandId] = [
                'name' => trim((string) ($row['name'] ?? '')),
                'percent' => $this->toFloat($row['percent'] ?? 0),
            ];
        }
        $this->info('Descuentos cargados: ' . count($descuentoByWooBrandId));

        /** @var TenClient $tenClient */
        $tenClient = app(TenClient::class);
        /** @var WooCommerceClient $wooClient */
        $wooClient = app(WooCommerceClient::class);

        try {
            $this->info('Llamando a TEN /Products/Get ...');
            $tenProducts = $tenClient->getProducts($modifiedAfter, $items, $page);
        } catch (Throwable $e) {
            $this->error('Error TEN: ' . $e->getMessage());
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
        $discountApplied = 0;
        $withoutDiscount = 0;
        $stockZero = 0;
        $stockPositive = 0;
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
            $fabricanteTenIds = [];
            foreach ($chunk as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $sku = trim((string) ($row['Codigo'] ?? ''));
                if ($sku !== '') {
                    $skus[] = $sku;
                }
                $fabricanteTenId = (int) ($row['Fabricante'] ?? 0);
                if ($fabricanteTenId > 0) {
                    $fabricanteTenIds[] = $fabricanteTenId;
                }
            }

            $productosBySku = [];
            foreach (array_chunk(array_values(array_unique($skus)), 1000) as $skuChunk) {
                $productos = Producto::query()
                    ->whereIn('ten_codigo', $skuChunk)
                    ->get(['id', 'ten_codigo', 'woocommerce_id']);

                foreach ($productos as $producto) {
                    $sku = trim((string) ($producto->ten_codigo ?? ''));
                    if ($sku !== '' && !isset($productosBySku[$sku])) {
                        $productosBySku[$sku] = $producto;
                    }
                }
            }

            $fabricantesByTenId = [];
            foreach (array_chunk(array_values(array_unique($fabricanteTenIds)), 1000) as $fabricanteChunk) {
                $fabricantes = Fabricante::query()
                    ->whereIn('ten_id_numero', $fabricanteChunk)
                    ->get(['ten_id_numero', 'ten_nombre', 'woocommerce_marca_id']);

                foreach ($fabricantes as $fabricante) {
                    $fabricantesByTenId[(int) $fabricante->ten_id_numero] = $fabricante;
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

                $stockIsZero = (bool) ($remoteState['stock_is_zero'] ?? false);
                if ($stockIsZero) {
                    $stockZero++;
                } else {
                    $stockPositive++;
                }

                $precioConIva = $precioBase * (1 + ($porcImpost / 100));
                $regularPrice = $this->toDecimalString($precioConIva);

                $fabricanteTenId = (int) ($row['Fabricante'] ?? 0);
                $fabricante = $fabricantesByTenId[$fabricanteTenId] ?? null;
                $wooBrandId = (int) ($fabricante?->woocommerce_marca_id ?? 0);
                $descuentoMarca = $descuentoByWooBrandId[$wooBrandId] ?? null;
                $discountPercent = $descuentoMarca['percent'] ?? 0.0;

                $salePrice = '';
                if ($discountPercent > 0 && $stockIsZero) {
                    $precioDescuento = max(0.0, $precioConIva * (1 - ($discountPercent / 100)));
                    $salePrice = $this->toDecimalString($precioDescuento);
                    $discountApplied++;
                } else {
                    $withoutDiscount++;
                }

                $pendingBatch[] = [
                    'id' => $wooId,
                    'regular_price' => $regularPrice,
                    'sale_price' => $salePrice,
                ];
                $pendingBatchDebug[] = [
                    'woo_id' => $wooId,
                    'sku' => $sku,
                    'precio_base' => $this->toDecimalString($precioBase),
                    'iva_percent' => $this->toDecimalString($porcImpost),
                    'regular_price' => $regularPrice,
                    'sale_price' => $salePrice,
                    'stock_is_zero' => $stockIsZero,
                    'woo_brand_id' => $wooBrandId,
                    'brand_name' => $descuentoMarca['name'] ?? ($fabricante?->ten_nombre ?? ''),
                    'discount_percent' => $discountPercent,
                    'price_blocked' => $priceBlocked,
                ];
                $queued++;

                if ($dryRun && count($pendingBatchDebug) <= 10) {
                    $this->line(
                        "DRY sku={$sku} woo_id={$wooId} base={$this->toDecimalString($precioBase)} iva={$this->toDecimalString($porcImpost)}"
                        . " regular={$regularPrice} sale=" . ($salePrice !== '' ? $salePrice : '-')
                        . " stock_zero=" . ($stockIsZero ? '1' : '0')
                        . " brand=" . ($descuentoMarca['name'] ?? ($fabricante?->ten_nombre ?? '-'))
                        . " dto=" . ($discountPercent > 0 ? $this->toDecimalString($discountPercent) : '0')
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
            . " | descuentos={$discountApplied} | sin_descuento={$withoutDiscount}"
            . " | stock_zero={$stockZero} | stock_positive={$stockPositive}"
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
            'discount_applied' => $discountApplied,
            'without_discount' => $withoutDiscount,
            'stock_zero' => $stockZero,
            'stock_positive' => $stockPositive,
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

        return $wooErrors > 0 ? self::FAILURE : self::SUCCESS;
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
     * @return array<int,array{stock_is_zero:bool,price_blocked:bool}>
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
                    '_fields' => 'id,stock_quantity,stock_status,meta_data,precio_bloqueado_ten',
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
}
