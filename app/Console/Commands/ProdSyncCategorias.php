<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Categoria;
use Illuminate\Support\Facades\Log;
use Throwable;
use App\Integrations\WooCommerceClient;

class ProdSyncCategorias extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:prod-sync-categorias {--no_create : No crear nuevas categorías en WooCommerce}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $marker = '[PROD_SYNC_CATEGORIAS v2]';
        $this->line($marker . ' start');
        Log::info($marker . ' start');
        $noCreate = (bool) $this->option('no_create');
        Log::info($marker . ' options', ['no_create' => $noCreate]);

        $cats = Categoria::query()
            ->where(function ($sub) {
                $sub->whereNull('ten_bloqueado')->orWhere('ten_bloqueado', false)->orWhere('ten_bloqueado', 0);
            })
            ->where('sync_status', 'pending')
            ->get();
        $total = $cats->count();
        $this->info("Seleccionadas: {$total}");
        Log::info($marker . ' selected', ['count' => $total]);
        if ($total === 0) return self::SUCCESS;

        /** @var WooCommerceClient $client */
        $client = app(WooCommerceClient::class);

        $synced = 0;
        $created = 0;
        $updated = 0;
        $linked  = 0;
        $skipped = 0;
        $errors  = 0;

        $wooIdBySlugParent = [];
        $wooIdByTenId = Categoria::query()
            ->whereNotNull('woocommerce_categoria_id')
            ->pluck('woocommerce_categoria_id', 'ten_id_numero')
            ->map(fn($v) => (int) $v)
            ->all();

        $pending = $cats->values()->all();
        $maxPasses = 10;
        $pass = 0;
        $remaining = count($pending);

        while ($remaining > 0 && $pass < $maxPasses) {
            $pass++;
            $this->line("--- Pass {$pass} | pendientes: {$remaining} ---");
            Log::info($marker . ' pass start', ['pass' => $pass, 'remaining' => $remaining]);
            $nextPending = [];
            $progressThisPass = 0;
            foreach ($pending as $c) {
                /** @var Categoria $c */
                $tenId = (int)($c->ten_id_numero ?? 0);
                $name = $this->categoriaNombre($c);
                $slug = $this->slugify($name);
                $tenParentId = (int)($c->ten_categoria_padre ?? 0);
                if ($tenId <= 0 || $slug === '') {
                    $errors++;
                    $msg = 'Categoría sin ten_id_numero o sin nombre usable';
                    $this->warn("[TEN#{$tenId}] ERROR: {$msg}");
                    Log::warning($marker . ' item error', ['ten_id' => $tenId, 'reason' => $msg]);
                    $c->markError($msg);
                    continue;
                }
                if ($tenParentId > 0 && $tenParentId === $tenId) {
                    $errors++;
                    $msg = 'Categoría con parent igual a sí misma (ciclo)';
                    $this->warn("[TEN#{$tenId}] ERROR: {$msg}");
                    Log::warning($marker . ' item error', ['ten_id' => $tenId, 'slug' => $slug, 'reason' => $msg]);
                    $c->markError($msg);
                    continue;
                }
                $wooParentId = 0;
                if ($tenParentId > 0 && isset($wooIdByTenId[$tenParentId])) {
                    $wooParentId = (int) $wooIdByTenId[$tenParentId];
                }
                $payload = [
                    'name' => $name,
                    'slug' => $slug,
                    'parent' => $wooParentId,
                ];
                try {
                    if (!empty($c->woocommerce_categoria_id)) {
                        $wooId = (int) $c->woocommerce_categoria_id;
                        $resp = $client->updateCategoriaProducto($wooId, $payload);
                        $wcId = (int)($resp['id'] ?? $wooId);
                        $wcParent = (int)($resp['parent'] ?? $wooParentId);
                        $c->woocommerce_categoria_id = $wcId;
                        $c->woocommerce_categoria_padre_id = $wcParent > 0 ? $wcParent : null;
                        $c->markSynced();
                        $wooIdByTenId[$tenId] = $wcId;
                        $wooIdBySlugParent[$this->slugParentKey($slug, $wcParent)] = $wcId;
                        $this->line("[TEN#{$tenId}] UPDATE WooCat#{$wcId} slug={$slug} parent={$wcParent}");
                        $updated++;
                        $synced++;
                        $progressThisPass++;
                        continue;
                    }
                    // Buscar por slug + parent esperado
                    $wooId = $this->findWooCategoryIdBySlugAndParent(
                        $client, $slug, $wooParentId, $wooIdBySlugParent, $marker, ['ten_id' => $tenId]
                    );
                    // Si no hay match por parent, buscar por slug ignorando parent y enlazar aunque no coincida el parent
                    if ($wooId <= 0) {
                        $wooId = $this->findWooCategoryIdBySlugAndParent(
                            $client, $slug, null, $wooIdBySlugParent, $marker, ['ten_id' => $tenId]
                        );
                        if ($wooId > 0) {
                            $foundParent = null;
                            $found = $client->getCategoriasProductosBySlug($slug, 100, 1);
                            if (is_array($found) && count($found) > 0) {
                                foreach ($found as $row) {
                                    if ((int)($row['id'] ?? 0) === $wooId) {
                                        $foundParent = (int)($row['parent'] ?? 0);
                                        break;
                                    }
                                }
                            }
                            $c->woocommerce_categoria_id = $wooId;
                            $c->woocommerce_categoria_padre_id = $foundParent > 0 ? $foundParent : null;
                            $c->markSynced();
                            $wooIdByTenId[$tenId] = $wooId;
                            $wooIdBySlugParent[$this->slugParentKey($slug, $foundParent)] = $wooId;
                            $this->line("[TEN#{$tenId}] LINK (por slug, parent ignorado) WooCat#{$wooId} slug={$slug} parent={$foundParent}");
                            $linked++;
                            $synced++;
                            $progressThisPass++;
                            continue;
                        }
                    }
                    if ($wooId > 0) {
                        $resp = $client->updateCategoriaProducto($wooId, $payload);
                        $wcId = (int)($resp['id'] ?? $wooId);
                        $wcParent = (int)($resp['parent'] ?? $wooParentId);
                        $c->woocommerce_categoria_id = $wcId;
                        $c->woocommerce_categoria_padre_id = $wcParent > 0 ? $wcParent : null;
                        $c->markSynced();
                        $wooIdByTenId[$tenId] = $wcId;
                        $wooIdBySlugParent[$this->slugParentKey($slug, $wcParent)] = $wcId;
                        $this->line("[TEN#{$tenId}] LINK WooCat#{$wcId} slug={$slug} parent={$wcParent}");
                        $linked++;
                        $updated++;
                        $synced++;
                        $progressThisPass++;
                        continue;
                    }
                    // Crear
                    if ($noCreate) {
                        $this->line("[TEN#{$tenId}] SKIP create (no_create) slug={$slug} parent={$wooParentId}");
                        Log::info($marker . ' skip create', [
                            'ten_id' => $tenId,
                            'slug' => $slug,
                            'woo_parent_id' => $wooParentId,
                            'pass' => $pass,
                        ]);
                        $skipped++;
                        continue;
                    }
                    $resp = $client->createCategoriaProducto($payload);
                    $wcId = (int)($resp['id'] ?? 0);
                    $wcParent = (int)($resp['parent'] ?? $wooParentId);
                    if ($wcId <= 0) {
                        throw new \RuntimeException('Respuesta Woo sin id al crear categoría');
                    }
                    $c->woocommerce_categoria_id = $wcId;
                    $c->woocommerce_categoria_padre_id = $wcParent > 0 ? $wcParent : null;
                    $c->markSynced();
                    $wooIdByTenId[$tenId] = $wcId;
                    $wooIdBySlugParent[$this->slugParentKey($slug, $wcParent)] = $wcId;
                    $this->line("[TEN#{$tenId}] CREATE WooCat#{$wcId} slug={$slug} parent={$wcParent}");
                    $created++;
                    $synced++;
                    $progressThisPass++;
                } catch (Throwable $e) {
                    $errors++;
                    $err = $e->getMessage();
                    $this->warn("[TEN#{$tenId}] ERROR slug={$slug}: {$err}");
                    Log::error($marker . ' item error (exception)', [
                        'ten_id' => $tenId,
                        'name' => $name,
                        'slug' => $slug,
                        'ten_parent_id' => $tenParentId,
                        'woo_parent_id' => $wooParentId,
                        'error' => $err,
                        'pass' => $pass,
                    ]);
                    $c->markError($err);
                }
            }
            $pending = $nextPending;
            $remaining = count($pending);
            Log::info($marker . ' pass end', ['pass' => $pass, 'remaining' => $remaining, 'progress' => $progressThisPass]);
            if ($progressThisPass === 0) break;
        }
        $this->info("OK fin. synced={$synced} | created={$created} | linked={$linked} | updated={$updated} | skipped={$skipped} | errors={$errors}");
        Log::info($marker . ' done', compact('synced','created','linked','updated','skipped','errors'));
        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function categoriaNombre(Categoria $c): string
    {
        $name = trim((string)($c->ten_web_nombre ?? ''));
        if ($name !== '') return $name;
        $name = trim((string)($c->ten_nombre ?? ''));
        if ($name !== '') return $name;
        $name = trim((string)($c->ten_codigo ?? ''));
        return $name;
    }

    private function slugify(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        $value = mb_strtolower($value);
        $value = str_replace(['á','à','ä','â','ã'], 'a', $value);
        $value = str_replace(['é','è','ë','ê'], 'e', $value);
        $value = str_replace(['í','ì','ï','î'], 'i', $value);
        $value = str_replace(['ó','ò','ö','ô','õ'], 'o', $value);
        $value = str_replace(['ú','ù','ü','û'], 'u', $value);
        $value = str_replace(['ñ', 'Ñ'], 'n', $value);
        $value = preg_replace('/[^a-z0-9\s\-]/u', '', $value) ?? '';
        $value = preg_replace('/[\s\-]+/u', '-', $value) ?? '';
        $value = trim($value, '-');
        return $value;
    }

    private function slugParentKey(string $slug, ?int $wooParentId): string
    {
        $p = (int)($wooParentId ?? 0);
        return $slug . '|' . $p;
    }

    private function findWooCategoryIdBySlugAndParent(
        WooCommerceClient $client,
        string $slug,
        int|null $expectedWooParentId,
        array &$wooIdBySlugParent,
        string $marker,
        array $context = []
    ): int {
        $slug = trim($slug);
        if ($slug === '') return 0;
        $cacheKey = $this->slugParentKey($slug, $expectedWooParentId);
        if (isset($wooIdBySlugParent[$cacheKey])) {
            return (int) $wooIdBySlugParent[$cacheKey];
        }
        $found = $client->getCategoriasProductosBySlug($slug, 100, 1);
        if (!is_array($found) || count($found) === 0) {
            return 0;
        }
        if ($expectedWooParentId === null) {
            $first = $found[0] ?? null;
            if (is_array($first) && !empty($first['id'])) {
                $id = (int) $first['id'];
                $wooIdBySlugParent[$cacheKey] = $id;
                return $id;
            }
            return 0;
        }
        $expected = (int) $expectedWooParentId;
        foreach ($found as $row) {
            if (!is_array($row) || empty($row['id'])) continue;
            $p = (int)($row['parent'] ?? 0);
            if ($p === $expected) {
                $id = (int)($row['id']);
                $wooIdBySlugParent[$cacheKey] = $id;
                return $id;
            }
        }
        return 0;
    }
}
