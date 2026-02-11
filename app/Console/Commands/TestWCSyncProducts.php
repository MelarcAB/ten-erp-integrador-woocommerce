<?php

namespace App\Console\Commands;

use App\Integrations\WooCommerceClient;
use App\Models\Producto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class TestWCSyncProducts extends Command
{
    protected $signature = 'app:test-wc-sync-products
        {--only=pending : pending|error|all}
        {--limit=0 : Límite de productos a procesar (0 = sin límite)}
        {--dry-run : No llama a Woo ni escribe en DB}
    ';

    protected $description = 'Sync productos APP->WooCommerce (alta/enlace/actualización) usando ten_codigo como SKU';

    public function handle(): int
    {
        $marker = '[WC_PRODUCTS_SYNC v1]';
        $this->line($marker . ' start');
        Log::info($marker . ' start');

        $only = (string) $this->option('only');
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        if (!in_array($only, ['pending', 'error', 'all'], true)) {
            $this->error('Valor inválido para --only. Usa: pending|error|all');
            return self::FAILURE;
        }

        $q = Producto::query();

        if ($only === 'pending') {
            $q->where('sync_status', 'pending');
        } elseif ($only === 'error') {
            $q->where('sync_status', 'error');
        }

        // No sincronizar bloqueados
        $q->where(function ($sub) {
            $sub->whereNull('ten_bloqueado')->orWhere('ten_bloqueado', false)->orWhere('ten_bloqueado', 0);
        });

        // Necesitamos SKU (ten_codigo) para enlace/alta (si no -> error)
        $q->orderByDesc('ten_last_fetched_at')->orderByDesc('id');

        if ($limit > 0) {
            $q->limit($limit);
        }

        $productos = $q->get();

        $total = $productos->count();
        $this->info("Seleccionados: {$total} (only={$only}, limit={$limit}, dry-run=" . ($dryRun ? '1' : '0') . ")");
        Log::info($marker . ' selected', ['count' => $total, 'only' => $only, 'limit' => $limit, 'dry_run' => $dryRun]);

        if ($total === 0) return self::SUCCESS;

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
                $this->warn("[{$p->id}] ERROR: {$msg}");
                Log::warning($marker . ' product missing sku', ['id' => $p->id]);

                if (!$dryRun) {
                    $p->sync_status = 'error';
                    $p->last_error = $msg;
                    $p->save();
                }
                continue;
            }

            // Payload base (sirve tanto para alta como update)
            $payload = $this->toWooPayload($p);

            try {
                // 1) Si ya tiene woo id -> update
                if (!empty($p->woocommerce_id)) {
                    $wooId = (int) $p->woocommerce_id;

                    if ($dryRun) {
                        $this->line("[{$p->id}] UPDATE Woo #{$wooId} sku={$sku}");
                        $updated++;
                        $synced++;
                        continue;
                    }

                    // Si en Woo ya hay description, la preservamos (no la sobreescribimos)
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
                    continue;
                }

                // 2) No tiene woo id -> probar enlace por SKU
                if ($dryRun) {
                    $this->line("[{$p->id}] LINK/CREATE by sku={$sku}");
                    $synced++;
                    continue;
                }

                $found = $client->getProductosBySku($sku, 100, 1);
                $first = $found[0] ?? null;

                if (is_array($first) && !empty($first['id'])) {
                    // Encontrado -> enlazar (y opcionalmente actualizar)
                    $wcId = (int) $first['id'];
                    $wcSku = (string)($first['sku'] ?? $sku);

                    // Si en Woo ya hay description, la preservamos (no la sobreescribimos)
                    $remoteDesc = trim((string)($first['description'] ?? ''));
                    if ($remoteDesc !== '') {
                        unset($payload['description']);
                    }

                    // Actualiza WC para asegurar que queda alineado con TEN
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
                    continue;
                }

                // 3) No existe -> crear
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
            } catch (Throwable $e) {
                $errors++;
                $err = $e->getMessage();
                $this->warn("[{$p->id}] ERROR sku={$sku}: {$err}");
                Log::error($marker . ' product sync failed', ['id' => $p->id, 'sku' => $sku, 'error' => $err]);

                if (!$dryRun) {
                    $p->sync_status = 'error';
                    $p->last_error = $err;
                    $p->save();
                }
            }
        }

        $this->info("OK fin. synced={$synced} | created={$created} | linked={$linked} | updated={$updated} | skipped={$skipped} | errors={$errors}");
        Log::info($marker . ' done', compact('synced','created','linked','updated','skipped','errors'));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Producto(APP) -> payload Woo (/products)
     *
     * @return array<string,mixed>
     */
    private function toWooPayload(Producto $p): array
    {
        $sku = trim((string)($p->ten_codigo ?? ''));

        $name = trim((string)($p->ten_web_nombre ?? ''));
        if ($name === '') {
            // fallback estable
            $name = $sku !== '' ? $sku : ('Producto ' . $p->id);
        }

        $short = trim((string)($p->ten_web_descripcion_corta ?? ''));
        $long  = (string)($p->ten_web_descripcion_larga ?? '');

        // Woo espera strings en precios
        $price = $p->ten_precio;
        $regularPrice = $price === null ? null : rtrim(rtrim(number_format((float)$price, 2, '.', ''), '0'), '.');

        $payload = [
            'name' => $name,
            'type' => 'simple',
            'status' => 'publish',
            'sku' => $sku,

            'description' => $long !== '' ? $long : null,
            'short_description' => $short !== '' ? $short : null,

            // precio
            'regular_price' => $regularPrice,

            // stock: por ahora solo flag de control (sin qty)
            'manage_stock' => (bool)($p->ten_web_control_stock ?? false),

            // peso si existe
            'weight' => $p->ten_peso === null ? null : (string) $p->ten_peso,
        ];

        // Limpieza nulls para no mandar basura
        return array_filter($payload, fn($v) => $v !== null);
    }
}
