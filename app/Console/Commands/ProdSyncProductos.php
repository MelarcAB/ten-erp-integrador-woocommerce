<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Integrations\WooCommerceClient;
use App\Models\Categoria;
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
            ->where(function ($sub) {
                $sub->whereNull('ten_bloqueado')->orWhere('ten_bloqueado', false)->orWhere('ten_bloqueado', 0);
            })
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

            foreach ($productos as $p) {
                /** @var Producto $p */
                $sku = trim((string) ($p->ten_codigo ?? ''));
                if ($sku === '') {
                    $errors++;
                    $msg = 'Producto sin ten_codigo (SKU)';
                    $this->warn("[{$p->ten_id}] ERROR: {$msg}");
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
                        $remoteDesc = is_array($remote) ? trim((string)($remote['description'] ?? '')) : '';
                        if ($remoteDesc !== '') {
                            unset($payload['description']);
                        }
                        $resp = $client->updateProducto($wooId, $payload);
                        $wcId = (int)($resp['id'] ?? $wooId);
                        $wcSku = (string)($resp['sku'] ?? $sku);
                        $p->woocommerce_id = $wcId;
                        $p->woocommerce_sku = $wcSku !== '' ? $wcSku : $sku;
                        $p->sync_status = 'synced';
                        $p->last_error = null;
                        $p->save();
                        $updated++;
                        $synced++;
                        $this->line("[{$p->ten_id}] UPDATE Woo #{$wcId} sku={$wcSku}");
                        continue;
                    }
                    // Buscar por SKU en Woo
                    $found = $client->getProductosBySku($sku, 100, 1);
                    $first = $found[0] ?? null;
                    if (is_array($first) && !empty($first['id'])) {
                        $wcId = (int) $first['id'];
                        $wcSku = (string)($first['sku'] ?? $sku);
                        $remoteDesc = trim((string)($first['description'] ?? ''));
                        if ($remoteDesc !== '') {
                            unset($payload['description']);
                        }
                        $resp = $client->updateProducto($wcId, $payload);
                        $wcId = (int)($resp['id'] ?? $wcId);
                        $wcSku = (string)($resp['sku'] ?? $wcSku);
                        $p->woocommerce_id = $wcId;
                        $p->woocommerce_sku = $wcSku !== '' ? $wcSku : $sku;
                        $p->sync_status = 'synced';
                        $p->last_error = null;
                        $p->save();
                        $linked++;
                        $updated++;
                        $synced++;
                        $this->line("[{$p->ten_id}] LINK Woo #{$wcId} sku={$wcSku}");
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
                    $created++;
                    $synced++;
                    $this->line("[{$p->ten_id}] CREATE Woo #{$wcId} sku={$wcSku}");
                } catch (Throwable $e) {
                    $errors++;
                    $err = $e->getMessage();
                    $this->warn("[{$p->ten_id}] ERROR sku={$sku}: {$err}");
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
        $productosTodos = Producto::query()
            ->whereNotNull('woocommerce_id')
            ->where('woocommerce_id', '!=', '')
            ->get();
        foreach ($productosTodos as $p) {
            $wooId = (int) $p->woocommerce_id;
            $tenCatId = $p->categoria_ten_id;
            if (!$wooId || !$tenCatId) continue;
            $cat = Categoria::query()->where('ten_id_numero', (int)$tenCatId)->first(['woocommerce_categoria_id']);
            if (!$cat || !(int)$cat->woocommerce_categoria_id) continue;
            $wooCatId = (int)$cat->woocommerce_categoria_id;
            try {
                $remote = $client->getProductoById($wooId);
                $wooCats = is_array($remote) && isset($remote['categories']) ? $remote['categories'] : [];
                $hasCat = false;
                foreach ($wooCats as $c) {
                    if ((int)($c['id'] ?? 0) === $wooCatId) {
                        $hasCat = true;
                        break;
                    }
                }
                if (!$hasCat) {
                    $payload = ['categories' => [ ['id' => $wooCatId] ]];
                    $client->updateProducto($wooId, $payload);
                    $this->line("[{$p->ten_id}] CATEGORÍA ACTUALIZADA en Woo #{$wooId} -> Cat#{$wooCatId}");
                }
            } catch (Throwable $e) {
                $this->warn("[{$p->ten_id}] ERROR al validar categoría Woo: " . $e->getMessage());
                Log::error($marker . ' categoria sync failed', ['ten_id' => $p->ten_id, 'woo_id' => $wooId, 'cat_id' => $wooCatId, 'error' => $e->getMessage()]);
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
        $actualizados = 0;
        foreach ($tenProducts as $tenRow) {
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
            $producto = \App\Models\Producto::query()->where('ten_id', $tenId)->whereNotNull('woocommerce_id')->first();
            if (!$producto) continue;
            try {
                $client->updateProducto((int)$producto->woocommerce_id, ['categories' => $wooCatIds]);
                $this->line("[{$tenId}] CATEGORÍAS ACTUALIZADAS en Woo -> " . json_encode($wooCatIds));
                $actualizados++;
            } catch (Throwable $e) {
                $this->warn("[{$tenId}] ERROR al actualizar categorías desde TEN: " . $e->getMessage());
                Log::error($marker . ' categoria sync from ten failed', ['ten_id' => $tenId, 'woo_id' => $producto->woocommerce_id, 'cat_ids' => $wooCatIds, 'error' => $e->getMessage()]);
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
        $payload = [
            'name' => $name,
            'type' => 'simple',
            'status' => 'publish',
            'sku' => $sku,
            'description' => $long !== '' ? $long : null,
            'short_description' => $short !== '' ? $short : null,
            'regular_price' => $regularPrice,
            'manage_stock' => (bool)($p->ten_web_control_stock ?? false),
            'weight' => $p->ten_peso === null ? null : (string) $p->ten_peso,
        ];
        // Categoría Woo
        $tenCatId = $p->categoria_ten_id;
        if ($tenCatId !== null && $tenCatId !== '') {
            $cat = Categoria::query()->where('ten_id_numero', (int)$tenCatId)->first(['woocommerce_categoria_id']);
            if ($cat && (int)($cat->woocommerce_categoria_id ?? 0) > 0) {
                $payload['categories'] = [ ['id' => (int)$cat->woocommerce_categoria_id] ];
            }
        }
        return array_filter($payload, fn($v) => $v !== null);
    }
}
