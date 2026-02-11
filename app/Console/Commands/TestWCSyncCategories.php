<?php

namespace App\Console\Commands;

use App\Integrations\WooCommerceClient;
use App\Models\Categoria;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sync categorías desde APP (importadas desde TEN) hacia WooCommerce.
 *
 * Estrategia:
 * - Fuente de verdad: TEN -> APP. Woo se alinea con APP.
 * - Selección: enable_sync=1, sync_status=pending (por defecto), ten_bloqueado=0
 * - Identificación en Woo: por slug derivado de ten_web_nombre/ten_nombre/ten_codigo.
 * - Jerarquía: primero categorías raíz, luego hijas (usando ten_categoria_padre).
 */
class TestWCSyncCategories extends Command
{
    protected $signature = 'app:test-wc-sync-categories
        {--only=pending : pending|error|all}
        {--limit=0 : Límite de categorías a procesar (0 = sin límite)}
        {--dry-run : No llama a Woo ni escribe en DB}
    ';

    protected $description = 'Sync categorías APP->WooCommerce (TEN -> APP -> Woo)';

    public function handle(): int
    {
        $marker = '[WC_CATEGORIES_SYNC v1]';
        $this->line($marker . ' start');
        Log::info($marker . ' start');

        $only = (string) $this->option('only');
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        if (!in_array($only, ['pending', 'error', 'all'], true)) {
            $this->error('Valor inválido para --only. Usa: pending|error|all');
            return self::FAILURE;
        }

        $q = Categoria::query()
            ->where(function ($sub) {
                $sub->whereNull('ten_bloqueado')->orWhere('ten_bloqueado', false)->orWhere('ten_bloqueado', 0);
            });

        if ($only === 'pending') {
            $q->where('sync_status', 'pending');
        } elseif ($only === 'error') {
            $q->where('sync_status', 'error');
        }

        // Traer todas las seleccionadas (sin depender del orden final para jerarquía)
        if ($limit > 0) {
            $q->limit($limit);
        }

        $cats = $q->get();
        $total = $cats->count();

        $this->info("Seleccionadas: {$total} (only={$only}, limit={$limit}, dry-run=" . ($dryRun ? '1' : '0') . ")");
        Log::info($marker . ' selected', ['count' => $total, 'only' => $only, 'limit' => $limit, 'dry_run' => $dryRun]);

        if ($total === 0) return self::SUCCESS;

        /** @var WooCommerceClient $client */
        $client = app(WooCommerceClient::class);

        $synced = 0;
        $created = 0;
        $updated = 0;
        $linked  = 0;
        $skipped = 0;
        $errors  = 0;

        // Cache slug->wooId
        $wooIdBySlug = [];

        // Cache ten_id->wooId precargado desde DB (IMPORTANTE: evita SKIPs si el padre ya estaba sincronizado en ejecuciones previas)
        $wooIdByTenId = Categoria::query()
            ->whereNotNull('woocommerce_categoria_id')
            ->pluck('woocommerce_categoria_id', 'ten_id_numero')
            ->map(fn($v) => (int) $v)
            ->all();

        // Particionar: roots primero para maximizar resoluciones
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

                if ($tenId <= 0) {
                    $errors++;
                    $msg = 'Categoría sin ten_id_numero';
                    $this->warn("[cat] ERROR: {$msg}");
                    Log::warning($marker . ' item error', ['reason' => $msg, 'row' => $c->toArray()]);
                    if (!$dryRun) {
                        $c->sync_status = 'error';
                        $c->last_error = $msg;
                        $c->save();
                    }
                    continue;
                }

                $name = $this->categoriaNombre($c);
                $slug = $this->slugify($name);
                if ($slug === '') {
                    $errors++;
                    $msg = 'Categoría sin nombre usable (ten_web_nombre/ten_nombre/ten_codigo)';
                    $this->warn("[TEN#{$tenId}] ERROR: {$msg}");
                    Log::warning($marker . ' item error', ['ten_id' => $tenId, 'reason' => $msg]);
                    if (!$dryRun) {
                        $c->sync_status = 'error';
                        $c->last_error = $msg;
                        $c->save();
                    }
                    continue;
                }

                $tenParentId = (int)($c->ten_categoria_padre ?? 0);

                // Detectar self-parent / ciclos simples
                if ($tenParentId > 0 && $tenParentId === $tenId) {
                    $errors++;
                    $msg = 'Categoría con parent igual a sí misma (ciclo)';
                    $this->warn("[TEN#{$tenId}] ERROR: {$msg}");
                    Log::warning($marker . ' item error', ['ten_id' => $tenId, 'slug' => $slug, 'reason' => $msg]);
                    if (!$dryRun) {
                        $c->sync_status = 'error';
                        $c->last_error = $msg;
                        $c->save();
                    }
                    continue;
                }

                $wooParentId = 0;
                if ($tenParentId > 0) {
                    // Resolver parent desde el cache, o desde DB si ya estaba sincronizado
                    if (isset($wooIdByTenId[$tenParentId])) {
                        $wooParentId = (int) $wooIdByTenId[$tenParentId];
                    } else {
                        // No resoluble todavía: lo dejamos para la siguiente pasada
                        $nextPending[] = $c;
                        continue;
                    }
                }

                $payload = [
                    'name' => $name,
                    'slug' => $slug,
                    'parent' => $wooParentId,
                ];

                try {
                    // UPDATE directo si ya tenemos woo id en la fila
                    if (!empty($c->woocommerce_categoria_id)) {
                        $wooId = (int) $c->woocommerce_categoria_id;

                        if ($dryRun) {
                            $this->line("[TEN#{$tenId}] UPDATE(dry) WooCat#{$wooId} slug={$slug} parent={$wooParentId}");
                            Log::info($marker . ' item update (dry-run)', compact('tenId', 'wooId', 'name', 'slug', 'wooParentId'));
                            $updated++;
                            $synced++;
                            $wooIdByTenId[$tenId] = $wooId;
                            $wooIdBySlug[$slug] = $wooId;
                            $progressThisPass++;
                            continue;
                        }

                        $resp = $client->updateCategoriaProducto($wooId, $payload);
                        $wcId = (int)($resp['id'] ?? $wooId);
                        $wcParent = (int)($resp['parent'] ?? $wooParentId);

                        $c->woocommerce_categoria_id = $wcId;
                        $c->woocommerce_categoria_padre_id = $wcParent > 0 ? $wcParent : null;
                        $c->sync_status = 'synced';
                        $c->last_error = null;
                        $c->save();

                        $wooIdByTenId[$tenId] = $wcId;
                        $wooIdBySlug[$slug] = $wcId;

                        $this->line("[TEN#{$tenId}] UPDATE WooCat#{$wcId} slug={$slug} parent={$wcParent}");
                        Log::info($marker . ' item update', [
                            'ten_id' => $tenId,
                            'woo_id' => $wcId,
                            'name' => $name,
                            'slug' => $slug,
                            'woo_parent' => $wcParent,
                            'pass' => $pass,
                        ]);

                        $updated++;
                        $synced++;
                        $progressThisPass++;
                        continue;
                    }

                    if ($dryRun) {
                        $this->line("[TEN#{$tenId}] LINK/CREATE(dry) slug={$slug} parent={$wooParentId}");
                        Log::info($marker . ' item link-or-create (dry-run)', [
                            'ten_id' => $tenId,
                            'name' => $name,
                            'slug' => $slug,
                            'woo_parent' => $wooParentId,
                            'pass' => $pass,
                        ]);
                        $synced++;
                        $progressThisPass++;
                        continue;
                    }

                    // Buscar por slug
                    $wooId = 0;
                    if (isset($wooIdBySlug[$slug])) {
                        $wooId = (int) $wooIdBySlug[$slug];
                    } else {
                        $found = $client->getCategoriasProductosBySlug($slug, 100, 1);
                        $first = $found[0] ?? null;
                        if (is_array($first) && !empty($first['id'])) {
                            $wooId = (int) $first['id'];
                            $wooIdBySlug[$slug] = $wooId;
                        }
                    }

                    if ($wooId > 0) {
                        // Enlazar + update
                        $resp = $client->updateCategoriaProducto($wooId, $payload);
                        $wcId = (int)($resp['id'] ?? $wooId);
                        $wcParent = (int)($resp['parent'] ?? $wooParentId);

                        $c->woocommerce_categoria_id = $wcId;
                        $c->woocommerce_categoria_padre_id = $wcParent > 0 ? $wcParent : null;
                        $c->sync_status = 'synced';
                        $c->last_error = null;
                        $c->save();

                        $wooIdByTenId[$tenId] = $wcId;
                        $wooIdBySlug[$slug] = $wcId;

                        $this->line("[TEN#{$tenId}] LINK WooCat#{$wcId} slug={$slug} parent={$wcParent}");
                        Log::info($marker . ' item link', [
                            'ten_id' => $tenId,
                            'woo_id' => $wcId,
                            'name' => $name,
                            'slug' => $slug,
                            'woo_parent' => $wcParent,
                            'pass' => $pass,
                        ]);

                        $linked++;
                        $updated++;
                        $synced++;
                        $progressThisPass++;
                        continue;
                    }

                    // Crear
                    $resp = $client->createCategoriaProducto($payload);
                    $wcId = (int)($resp['id'] ?? 0);
                    $wcParent = (int)($resp['parent'] ?? $wooParentId);

                    if ($wcId <= 0) {
                        throw new \RuntimeException('Respuesta Woo sin id al crear categoría');
                    }

                    $c->woocommerce_categoria_id = $wcId;
                    $c->woocommerce_categoria_padre_id = $wcParent > 0 ? $wcParent : null;
                    $c->sync_status = 'synced';
                    $c->last_error = null;
                    $c->save();

                    $wooIdByTenId[$tenId] = $wcId;
                    $wooIdBySlug[$slug] = $wcId;

                    $this->line("[TEN#{$tenId}] CREATE WooCat#{$wcId} slug={$slug} parent={$wcParent}");
                    Log::info($marker . ' item create', [
                        'ten_id' => $tenId,
                        'woo_id' => $wcId,
                        'name' => $name,
                        'slug' => $slug,
                        'woo_parent' => $wcParent,
                        'pass' => $pass,
                    ]);

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

                    if (!$dryRun) {
                        $c->sync_status = 'error';
                        $c->last_error = $err;
                        $c->save();
                    }
                }
            }

            $pending = $nextPending;
            $remaining = count($pending);

            Log::info($marker . ' pass end', ['pass' => $pass, 'remaining' => $remaining, 'progress' => $progressThisPass]);

            if ($progressThisPass === 0) {
                // No avanzamos: lo que queda puede ser por parent inexistente o ciclos
                break;
            }
        }

        // Lo que quede sin resolver => skip final con log (no lo marcamos error automáticamente)
        foreach ($pending as $c) {
            /** @var Categoria $c */
            $tenId = (int)($c->ten_id_numero ?? 0);
            $tenParentId = (int)($c->ten_categoria_padre ?? 0);
            $name = $this->categoriaNombre($c);
            $slug = $this->slugify($name);

            $skipped++;
            $this->warn("[TEN#{$tenId}] SKIP(final): parent TEN#{$tenParentId} no resuelto tras {$pass} pasadas");
            Log::warning($marker . ' item skip (final)', [
                'ten_id' => $tenId,
                'name' => $name,
                'slug' => $slug,
                'ten_parent_id' => $tenParentId,
                'passes' => $pass,
                'reason' => 'parent_not_resolved_after_passes',
            ]);
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

        // reemplazos básicos
        $value = str_replace(['á','à','ä','â','ã'], 'a', $value);
        $value = str_replace(['é','è','ë','ê'], 'e', $value);
        $value = str_replace(['í','ì','ï','î'], 'i', $value);
        $value = str_replace(['ó','ò','ö','ô','õ'], 'o', $value);
        $value = str_replace(['ú','ù','ü','û'], 'u', $value);
        $value = str_replace(['ñ'], 'n', $value);

        $value = preg_replace('/[^a-z0-9\s\-]/u', '', $value) ?? '';
        $value = preg_replace('/[\s\-]+/u', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value;
    }
}
