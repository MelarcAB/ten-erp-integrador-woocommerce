<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Integrations\WooCommerceClient;
use App\Models\Categoria;
use App\Models\ProductoCategoriaTen;
use App\Models\Producto;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProdSyncProductos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:prod-sync-productos';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sincroniza productos con WooCommerce: enlaza por SKU o crea si no existe. Actualiza woocommerce_id y sync_status.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $marker = '[PROD_SYNC_PRODUCTOS v1]';
        $this->line($marker . ' start');
        Log::info($marker . ' start');

        $productos = Producto::query()
            ->where('sync_status', 'pending')
            ->get();
        $total = $productos->count();
        $this->info("Seleccionados: {$total}");
        Log::info($marker . ' selected', ['count' => $total]);

        if ($total > 0) {
            /** @var WooCommerceClient $client */
            $client = app(WooCommerceClient::class);

            $synced = 0;
            $linked = 0;
            $created = 0;
            $updated = 0;
            $skipped = 0;
            $errors = 0;
            $remoteByWooId = [];
            $processedSync = 0;

            foreach ($productos as $p) {
                $processedSync++;
                $progress = $this->syncProgress($processedSync, $total);
                /** @var Producto $p */
                $sku = trim((string) ($p->ten_codigo ?? ''));
                if ($sku === '') {
                    $errors++;
                    $msg = 'Producto sin ten_codigo (SKU)';
                    $this->warn("[{$p->ten_id}] ERROR: {$msg}{$progress}");
                    Log::warning($marker . ' product missing sku', ['ten_id' => $p->ten_id]);
                    $p->sync_status = 'error';
                    $p->last_error = $msg;
                    $p->save();
                    continue;
                }
                $payload = $this->toWooPayload($p);
                try {
                    // Si ya tiene woo id -> update
                    if (!empty($p->woocommerce_id)) {
                        $wooId = (int) $p->woocommerce_id;
                        $remote = $client->getProductoById($wooId);
                        if (is_array($remote)) {
                            $remoteByWooId[$wooId] = $remote;
                        }
                        $remoteDesc = is_array($remote) ? trim((string)($remote['description'] ?? '')) : '';
                        $remoteShort = is_array($remote) ? trim((string)($remote['short_description'] ?? '')) : '';
                        // Descripción larga
                        if (mb_strlen($remoteDesc) > mb_strlen($payload['description'] ?? '')) {
                            unset($payload['description']);
                        }
                        // Descripción corta
                        if (mb_strlen($remoteShort) > mb_strlen($payload['short_description'] ?? '')) {
                            unset($payload['short_description']);
                        }
                        $resp = $client->updateProducto($wooId, $payload);
                        $wcId = (int)($resp['id'] ?? $wooId);
                        $wcSku = (string)($resp['sku'] ?? $sku);
                        $p->woocommerce_id = $wcId;
                        $p->woocommerce_sku = $wcSku !== '' ? $wcSku : $sku;
                        $p->sync_status = 'synced';
                        $p->last_error = null;
                        $p->save();
                        if (is_array($resp)) {
                            $remoteByWooId[$wcId] = $resp;
                        }
                        $updated++;
                        $synced++;
                        $this->line("[{$p->ten_id}] UPDATE Woo #{$wcId} sku={$wcSku}{$progress}");
                        continue;
                    }
                    // Buscar por SKU en Woo
                    $found = $client->getProductosBySku($sku, 100, 1);
                    $first = $found[0] ?? null;
                    if (is_array($first) && !empty($first['id'])) {
                        $wcId = (int) $first['id'];
                        $wcSku = (string)($first['sku'] ?? $sku);
                        $remoteByWooId[$wcId] = $first;
                        $remoteDesc = trim((string)($first['description'] ?? ''));
                        $remoteShort = trim((string)($first['short_description'] ?? ''));
                        if (mb_strlen($remoteDesc) > mb_strlen($payload['description'] ?? '')) {
                            unset($payload['description']);
                        }
                        if (mb_strlen($remoteShort) > mb_strlen($payload['short_description'] ?? '')) {
                            unset($payload['short_description']);
                        }
                        $resp = $client->updateProducto($wcId, $payload);
                        $wcId = (int)($resp['id'] ?? $wcId);
                        $wcSku = (string)($resp['sku'] ?? $wcSku);
                        $p->woocommerce_id = $wcId;
                        $p->woocommerce_sku = $wcSku !== '' ? $wcSku : $sku;
                        $p->sync_status = 'synced';
                        $p->last_error = null;
                        $p->save();
                        if (is_array($resp)) {
                            $remoteByWooId[$wcId] = $resp;
                        }
                        $linked++;
                        $updated++;
                        $synced++;
                        $this->line("[{$p->ten_id}] LINK Woo #{$wcId} sku={$wcSku}{$progress}");
                        continue;
                    }
                    // No existe -> crear
                    $resp = $client->createProducto($payload);
                    $wcId = (int)($resp['id'] ?? 0);
                    $wcSku = (string)($resp['sku'] ?? $sku);
                    if ($wcId <= 0) {
                        throw new \RuntimeException('Respuesta Woo sin id al crear producto');
                    }
                    $p->woocommerce_id = $wcId;
                    $p->woocommerce_sku = $wcSku !== '' ? $wcSku : $sku;
                    $p->sync_status = 'synced';
                    $p->last_error = null;
                    $p->save();
                    if (is_array($resp)) {
                        $remoteByWooId[$wcId] = $resp;
                    }
                    $created++;
                    $synced++;
                    $this->line("[{$p->ten_id}] CREATE Woo #{$wcId} sku={$wcSku}{$progress}");
                } catch (Throwable $e) {
                    $errors++;
                    $err = $e->getMessage();
                    $this->warn("[{$p->ten_id}] ERROR sku={$sku}: {$err}{$progress}");
                    Log::error($marker . ' product sync failed', ['ten_id' => $p->ten_id, 'sku' => $sku, 'error' => $err]);
                    $p->sync_status = 'error';
                    $p->last_error = $err;
                    $p->save();
                }
            }
            $this->info("OK fin. synced={$synced} | created={$created} | linked={$linked} | updated={$updated} | errors={$errors}");
            Log::info($marker . ' done', compact('synced','created','linked','updated','errors'));
        } else {
            $client = app(WooCommerceClient::class);
        }

        $this->info("Validando categorías de productos en Woo...");
        static $catWooByTenId = null;
        if ($catWooByTenId === null) {
            $catWooByTenId = Categoria::query()
                ->whereNotNull('woocommerce_categoria_id')
                ->whereNotNull('ten_id_numero')
                ->pluck('woocommerce_categoria_id', 'ten_id_numero')
                ->map(fn($v) => (int) $v)
                ->all();
        }
        $productosTodos = Producto::query()
            ->whereNotNull('woocommerce_id')
            ->where('woocommerce_id', '!=', '')
            ->get();
        $totalValidacion = $productosTodos->count();
        $processedValidacion = 0;
        foreach ($productosTodos as $p) {
            $processedValidacion++;
            $wooId = (int) $p->woocommerce_id;
            if (!$wooId) continue;
            $wooCatIds = $this->desiredWooCategoryIdsForProduct($p, $catWooByTenId);
            if (empty($wooCatIds)) continue;
            try {
                if (!isset($remoteByWooId[$wooId])) {
                    $remoteByWooId[$wooId] = $client->getProductoById($wooId);
                }
                $remote = $remoteByWooId[$wooId];
                $wooCats = is_array($remote) && isset($remote['categories']) ? $remote['categories'] : [];
                $currentWooCatIds = [];
                foreach ($wooCats as $c) {
                    $catId = (int) ($c['id'] ?? 0);
                    if ($catId > 0) {
                        $currentWooCatIds[] = $catId;
                    }
                }
                $currentWooCatIds = array_values(array_unique($currentWooCatIds));
                sort($currentWooCatIds);
                $expectedWooCatIds = $wooCatIds;
                sort($expectedWooCatIds);
                if ($currentWooCatIds !== $expectedWooCatIds) {
                    $payload = ['categories' => array_map(static fn ($id) => ['id' => $id], $wooCatIds)];
                    $client->updateProducto($wooId, $payload);
                    $this->line("[{$p->ten_id}] CATEGORÍAS ACTUALIZADAS en Woo #{$wooId} -> " . json_encode($wooCatIds));
                }
            } catch (Throwable $e) {
                $this->warn("[{$p->ten_id}] ERROR al validar categoría Woo: " . $e->getMessage());
                Log::error($marker . ' categoria sync failed', ['ten_id' => $p->ten_id, 'woo_id' => $wooId, 'cat_ids' => $wooCatIds, 'error' => $e->getMessage()]);
            }
            if (($processedValidacion % 500) === 0 || $processedValidacion === $totalValidacion) {
                $this->line("Validación categorías: {$processedValidacion}/{$totalValidacion}");
            }
        }
        $this->info("Validación de categorías finalizada.");

        // --- Sincronización directa de categorías desde TEN para todos los productos ---
        $this->info("Sincronizando categorías de productos desde TEN...");
        $tenClient = app(\App\Integrations\TenClient::class);
        try {
            $tenProducts = $tenClient->getProducts(\Carbon\Carbon::create(2022, 1, 1, 0, 0, 0), 100000, 0);
        } catch (Throwable $e) {
            $this->warn('No se pudo obtener productos de TEN para sincronizar categorías: ' . $e->getMessage());
            Log::warning($marker . ' ten getProducts failed', ['error' => $e->getMessage()]);
            $tenProducts = [];
        }
        $catMap = \App\Models\Categoria::query()
            ->whereNotNull('woocommerce_categoria_id')
            ->pluck('woocommerce_categoria_id', 'ten_id_numero')
            ->map(fn($v) => (int)$v)
            ->all();
        $wooIdByTenId = Producto::query()
            ->whereNotNull('woocommerce_id')
            ->where('woocommerce_id', '!=', '')
            ->pluck('woocommerce_id', 'ten_id')
            ->map(fn($v) => (int)$v)
            ->all();
        $actualizados = 0;
        $processedTen = 0;
        $tenTotal = count($tenProducts);
        foreach ($tenProducts as $tenRow) {
            $processedTen++;
            $tenId = isset($tenRow['Id']) ? (int)$tenRow['Id'] : (isset($tenRow['IdProducto']) ? (int)$tenRow['IdProducto'] : null);
            if (!$tenId || empty($tenRow['Categorias']) || !is_array($tenRow['Categorias'])) continue;
            $wooCatIds = [];
            foreach ($tenRow['Categorias'] as $catArr) {
                $catTenId = isset($catArr['IdCategoria']) ? (int)$catArr['IdCategoria'] : null;
                if ($catTenId && isset($catMap[$catTenId])) {
                    $wooCatIds[] = ['id' => $catMap[$catTenId]];
                }
            }
            if (empty($wooCatIds)) continue;
            $wooId = (int) ($wooIdByTenId[$tenId] ?? 0);
            if ($wooId <= 0) continue;
            try {
                $client->updateProducto($wooId, ['categories' => $wooCatIds]);
                $this->line("[{$tenId}] CATEGORÍAS ACTUALIZADAS en Woo -> " . json_encode($wooCatIds));
                $actualizados++;
            } catch (Throwable $e) {
                $this->warn("[{$tenId}] ERROR al actualizar categorías desde TEN: " . $e->getMessage());
                Log::error($marker . ' categoria sync from ten failed', ['ten_id' => $tenId, 'woo_id' => $wooId, 'cat_ids' => $wooCatIds, 'error' => $e->getMessage()]);
            }
            if (($processedTen % 500) === 0 || $processedTen === $tenTotal) {
                $this->line("TEN categorías: {$processedTen}/{$tenTotal}");
            }
        }
        $this->info("Sincronización de categorías desde TEN finalizada. Productos actualizados: {$actualizados}");

        return self::SUCCESS;
    }

    private function toWooPayload(Producto $p): array
    {
        $sku = trim((string)($p->ten_codigo ?? ''));
        $name = trim((string)($p->ten_web_nombre ?? ''));
        if ($name === '') {
            $name = $sku !== '' ? $sku : ('Producto ' . $p->ten_id);
        }
        $short = trim((string)($p->ten_web_descripcion_corta ?? ''));
        $long  = (string)($p->ten_web_descripcion_larga ?? '');
        $price = $p->ten_precio;
        $regularPrice = $price === null ? null : rtrim(rtrim(number_format((float)$price, 2, '.', ''), '0'), '.');
        $status = !empty($p->ten_bloqueado) ? 'draft' : 'publish';
        $payload = [
            'name' => $name,
            'type' => 'simple',
            'status' => $status,
            'sku' => $sku,
            'description' => $long !== '' ? $long : null,
            'short_description' => $short !== '' ? $short : null,
            'regular_price' => $regularPrice,
            'manage_stock' => (bool)($p->ten_web_control_stock ?? false),
            'weight' => $p->ten_peso === null ? null : (string) $p->ten_peso,
        ];
        // Categorías Woo (múltiples desde pivote; fallback a categoria_ten_id)
        static $catWooByTenId = null;
        if ($catWooByTenId === null) {
            $catWooByTenId = Categoria::query()
                ->whereNotNull('woocommerce_categoria_id')
                ->whereNotNull('ten_id_numero')
                ->pluck('woocommerce_categoria_id', 'ten_id_numero')
                ->map(fn($v) => (int) $v)
                ->all();
        }
        $wooCatIds = $this->desiredWooCategoryIdsForProduct($p, $catWooByTenId);
        if (!empty($wooCatIds)) {
            $payload['categories'] = array_map(static fn ($id) => ['id' => $id], $wooCatIds);
        }
        return array_filter($payload, fn($v) => $v !== null);
    }

    /**
     * @param array<int,int> $catWooByTenId [ten_id_numero => woo_category_id]
     * @return array<int,int>
     */
    private function desiredWooCategoryIdsForProduct(Producto $p, array $catWooByTenId): array
    {
        $tenCategoryIds = [];
        $tenProductId = (int) ($p->ten_id ?? 0);

        if ($tenProductId > 0) {
            static $pivotByProductoTenId = [];
            if (!array_key_exists($tenProductId, $pivotByProductoTenId)) {
                $pivotByProductoTenId[$tenProductId] = ProductoCategoriaTen::query()
                    ->where('producto_ten_id', $tenProductId)
                    ->orderBy('orden')
                    ->pluck('categoria_ten_id')
                    ->map(static fn ($v) => (int) $v)
                    ->filter(static fn ($v) => $v > 0)
                    ->values()
                    ->all();
            }
            $tenCategoryIds = $pivotByProductoTenId[$tenProductId];
        }

        if (empty($tenCategoryIds)) {
            $fallback = (int) ($p->categoria_ten_id ?? 0);
            if ($fallback > 0) {
                $tenCategoryIds = [$fallback];
            }
        }

        $wooCategoryIds = [];
        foreach ($tenCategoryIds as $tenCatId) {
            $wooCatId = (int) ($catWooByTenId[$tenCatId] ?? 0);
            if ($wooCatId > 0) {
                $wooCategoryIds[] = $wooCatId;
            }
        }

        return array_values(array_unique($wooCategoryIds));
    }

    private function syncProgress(int $processed, int $total): string
    {
        if ($total <= 0) {
            return '';
        }

        $percent = ($processed / $total) * 100;

        return sprintf(' | %d/%d (%.2f%%)', $processed, $total, $percent);
    }
}
