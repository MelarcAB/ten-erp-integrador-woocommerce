<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Integrations\WooCommerceClient;
use App\Models\Categoria;
use App\Models\Fabricante;
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
    protected $signature = 'app:prod-sync-productos
        {--full-category-validation : Valida categorías para todos los productos enlazados en Woo}
        {--full-brand-sync : Fuerza sincronización de marca para todos los productos enlazados}
        {--brand-batch-size=100 : Tamaño del batch para sync masivo de marcas (1-100)}
        {--skip-fabricantes-sync : Omitir pre-sync de fabricantes TEN->BD->Woo}
        {--skip-stocks-sync : Omitir pre-sync de stocks TEN->BD->Woo}
        {--skip-stock-proveedores-sync : Omitir pre-sync de stock por proveedores (CSV)}
        {--stocks-chunk-size=1000 : Chunk size para pre-sync de stocks}
        {--stocks-batch-size=100 : Batch size Woo para pre-sync de stocks}
    ';

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

        // Paso 0: refrescar productos desde TEN (nuevos/cambios => pending)
        $this->info('Refrescando productos desde TEN antes de sincronizar...');
        $importExit = $this->call('app:prod-import-productos');
        if ($importExit !== self::SUCCESS) {
            $this->error('Falló el import de productos desde TEN. Se aborta sync.');
            Log::error($marker . ' pre-sync import failed', ['exit_code' => $importExit]);
            return self::FAILURE;
        }

        $fullCategoryValidation = (bool) $this->option('full-category-validation');
        $fullBrandSync = (bool) $this->option('full-brand-sync');
        $brandBatchSize = max(1, min(100, (int) $this->option('brand-batch-size')));
        $skipFabricantesSync = (bool) $this->option('skip-fabricantes-sync');
        $skipStocksSync = (bool) $this->option('skip-stocks-sync');
        $skipStockProveedoresSync = (bool) $this->option('skip-stock-proveedores-sync');
        $stocksChunkSize = max(100, (int) $this->option('stocks-chunk-size'));
        $stocksBatchSize = max(1, min(100, (int) $this->option('stocks-batch-size')));

        // Paso 0.1: refrescar fabricantes TEN -> BD -> Woo
        if (!$skipFabricantesSync) {
            $this->info('Sincronizando fabricantes antes de productos...');
            $fabricantesExit = $this->call('app:prod-sync-fabricantes');
            if ($fabricantesExit !== self::SUCCESS) {
                $this->error('Falló el sync de fabricantes. Se aborta sync de productos.');
                Log::error($marker . ' pre-sync fabricantes failed', ['exit_code' => $fabricantesExit]);
                return self::FAILURE;
            }
        } else {
            $this->line('Pre-sync fabricantes omitido por flag.');
        }

        // Paso 0.2: refrescar stocks TEN -> BD -> Woo (versión chunk/batch)
        if (!$skipStocksSync) {
            $this->info('Sincronizando stocks antes de productos...');
            $stocksExit = $this->call('app:prod-sync-stocks', [
                '--chunk-size' => $stocksChunkSize,
                '--batch-size' => $stocksBatchSize,
            ]);
            if ($stocksExit !== self::SUCCESS) {
                $this->error('Falló el sync de stocks. Se aborta sync de productos.');
                Log::error($marker . ' pre-sync stocks failed', ['exit_code' => $stocksExit]);
                return self::FAILURE;
            }
        } else {
            $this->line('Pre-sync stocks omitido por flag.');
        }

        // Paso 0.3: sync stock por proveedores (CSV -> Woo), tras el stock normal
        if (!$skipStockProveedoresSync) {
            $this->info('Sincronizando stock por proveedores después del stock normal...');
            $stockProveedoresExit = $this->call('app:prod-sync-stock-proveedores');
            if ($stockProveedoresExit !== self::SUCCESS) {
                $this->error('Falló el sync de stock por proveedores. Se aborta sync de productos.');
                Log::error($marker . ' pre-sync stock proveedores failed', ['exit_code' => $stockProveedoresExit]);
                return self::FAILURE;
            }
        } else {
            $this->line('Pre-sync stock proveedores omitido por flag.');
        }

        $pendingQuery = Producto::query()
            ->where('sync_status', 'pending')
            ->orderBy('id');
        $total = (clone $pendingQuery)->count();
        $this->info("Seleccionados: {$total}");
        Log::info($marker . ' selected', ['count' => $total]);

        /** @var WooCommerceClient $client */
        $client = app(WooCommerceClient::class);

        if ($total > 0) {
            $synced = 0;
            $linked = 0;
            $created = 0;
            $updated = 0;
            $skipped = 0;
            $errors = 0;
            $processedSync = 0;
            $syncedTenIds = [];
            $pendingQuery->chunkById(200, function ($productos) use (
                $client,
                $marker,
                $total,
                &$processedSync,
                &$synced,
                &$linked,
                &$created,
                &$updated,
                &$skipped,
                &$errors,
                &$syncedTenIds
            ) {
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
                            $updated++;
                            $synced++;
                            $syncedTenIds[] = (int) $p->ten_id;
                            $this->line("[{$p->ten_id}] UPDATE Woo #{$wcId} sku={$wcSku}{$progress}");
                            continue;
                        }
                        // Buscar por SKU en Woo
                        $found = $client->getProductosBySku($sku, 100, 1);
                        $first = $found[0] ?? null;
                        if (is_array($first) && !empty($first['id'])) {
                            $wcId = (int) $first['id'];
                            $wcSku = (string)($first['sku'] ?? $sku);
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
                            $linked++;
                            $updated++;
                            $synced++;
                            $syncedTenIds[] = (int) $p->ten_id;
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
                        $created++;
                        $synced++;
                        $syncedTenIds[] = (int) $p->ten_id;
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
            });
            $this->info("OK fin. synced={$synced} | created={$created} | linked={$linked} | updated={$updated} | errors={$errors}");
            Log::info($marker . ' done', compact('synced','created','linked','updated','errors'));

            $syncedTenIds = array_values(array_unique(array_filter($syncedTenIds, static fn ($id) => $id > 0)));
        } else {
            $syncedTenIds = [];
        }

        $brandSweepErrors = 0;
        if ($fullBrandSync) {
            $brandResult = $this->runFullBrandSync($client, $marker, $brandBatchSize);
            $brandSweepErrors = (int) ($brandResult['errors'] ?? 0);
        }

        if (!$fullCategoryValidation && empty($syncedTenIds)) {
            $this->info('Validación de categorías omitida: no hubo productos sincronizados en esta ejecución.');
            Log::info($marker . ' category validation skipped (no synced products)');
            return $brandSweepErrors > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->info(
            $fullCategoryValidation
                ? 'Validando categorías de productos en Woo (modo completo)...'
                : 'Validando categorías de productos en Woo (solo sincronizados en esta ejecución)...'
        );
        static $catWooByTenId = null;
        if ($catWooByTenId === null) {
            $catWooByTenId = Categoria::query()
                ->whereNotNull('woocommerce_categoria_id')
                ->whereNotNull('ten_id_numero')
                ->pluck('woocommerce_categoria_id', 'ten_id_numero')
                ->map(fn($v) => (int) $v)
                ->all();
        }
        $productosTodosQuery = Producto::query()
            ->whereNotNull('woocommerce_id')
            ->where('woocommerce_id', '!=', '')
            ->orderBy('id');
        if (!$fullCategoryValidation) {
            $productosTodosQuery->whereIn('ten_id', $syncedTenIds);
        }
        $totalValidacion = (clone $productosTodosQuery)->count();
        if ($totalValidacion === 0) {
            $this->info('Validación de categorías finalizada (0 productos a validar).');
            Log::info($marker . ' category validation done', ['count' => 0, 'full' => $fullCategoryValidation]);
            return self::SUCCESS;
        }
        $processedValidacion = 0;
        $productosTodosQuery->chunkById(200, function ($productos) use (
            $client,
            $marker,
            $catWooByTenId,
            $totalValidacion,
            &$processedValidacion
        ) {
            foreach ($productos as $p) {
                $processedValidacion++;
                $wooId = (int) $p->woocommerce_id;
                if (!$wooId) continue;
                $wooCatIds = $this->desiredWooCategoryIdsForProduct($p, $catWooByTenId);
                if (empty($wooCatIds)) continue;
                try {
                    $remote = $client->getProductoById($wooId);
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
        });
        $this->info("Validación de categorías finalizada.");

        return $brandSweepErrors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{updated:int,same:int,skipped_no_mapping:int,errors:int}
     */
    private function runFullBrandSync(WooCommerceClient $client, string $marker, int $batchSize): array
    {
        $this->info("Sincronizando marcas en modo completo (batch={$batchSize})...");

        $wooBrandByTenFabricanteId = Fabricante::query()
            ->whereNotNull('woocommerce_marca_id')
            ->pluck('woocommerce_marca_id', 'ten_id_numero')
            ->map(static fn ($v) => (int) $v)
            ->all();

        if (empty($wooBrandByTenFabricanteId)) {
            $this->warn('Sync de marcas completo omitido: no hay fabricantes mapeados con Woo.');
            Log::warning($marker . ' full brand sync skipped', ['reason' => 'empty brand map']);
            return ['updated' => 0, 'same' => 0, 'skipped_no_mapping' => 0, 'errors' => 0];
        }

        $baseQuery = Producto::query()
            ->whereNotNull('woocommerce_id')
            ->where('woocommerce_id', '!=', '')
            ->whereNotNull('ten_fabricante')
            ->where('ten_fabricante', '>', 0);
        $totalWithFabricante = (clone $baseQuery)->count();

        $mappedTenFabricantes = array_values(array_unique(array_keys($wooBrandByTenFabricanteId)));
        $query = (clone $baseQuery)
            ->whereIn('ten_fabricante', $mappedTenFabricantes)
            ->orderBy('id');
        $total = (clone $query)->count();
        $skippedNoMapping = max(0, $totalWithFabricante - $total);
        if ($total === 0) {
            $this->info('Sync de marcas completo: 0 productos candidatos.');
            return ['updated' => 0, 'same' => 0, 'skipped_no_mapping' => $skippedNoMapping, 'errors' => 0];
        }

        $updated = 0;
        $errors = 0;
        $processed = 0;
        $batchOk = 0;
        $pendingBatch = [];

        $flushBatch = function () use (
            $client,
            $marker,
            &$pendingBatch,
            &$updated,
            &$errors,
            &$batchOk
        ): void {
            if (empty($pendingBatch)) {
                return;
            }

            try {
                // En modo masivo de marcas no necesitamos parsear respuesta completa,
                // evitando cargar miles de objetos de producto en memoria.
                $client->updateProductosBatch($pendingBatch, false);
                $updated += count($pendingBatch);
                $batchOk++;
            } catch (Throwable $e) {
                $errors += count($pendingBatch);
                Log::error($marker . ' full brand batch failed', [
                    'batch_size' => count($pendingBatch),
                    'error' => $e->getMessage(),
                ]);
                $this->warn('ERROR batch marcas: ' . $e->getMessage());
            }

            $pendingBatch = [];
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        };

        $query->chunkById(500, function ($productos) use (
            $client,
            $marker,
            $wooBrandByTenFabricanteId,
            $total,
            $batchSize,
            &$processed,
            &$updated,
            &$errors
            ,
            &$pendingBatch,
            &$flushBatch
        ) {
            foreach ($productos as $p) {
                /** @var Producto $p */
                $processed++;
                $wooId = (int) ($p->woocommerce_id ?? 0);
                $tenFabricanteId = (int) ($p->ten_fabricante ?? 0);
                if ($wooId <= 0 || $tenFabricanteId <= 0) {
                    continue;
                }

                $expectedBrandId = (int) ($wooBrandByTenFabricanteId[$tenFabricanteId] ?? 0);
                if ($expectedBrandId <= 0) {
                    continue;
                }

                $pendingBatch[] = [
                    'id' => $wooId,
                    'brands' => [['id' => $expectedBrandId]],
                ];

                if (count($pendingBatch) >= $batchSize) {
                    $flushBatch();
                }

                if (($processed % 500) === 0 || $processed === $total) {
                    $this->line("Sync marcas: {$processed}/{$total}");
                }
            }
        });

        $flushBatch();

        $this->info("Sync marcas completo finalizado. updated={$updated} | batches_ok={$batchOk} | skipped_no_mapping={$skippedNoMapping} | errors={$errors}");
        Log::info($marker . ' full brand sync done', compact('updated', 'batchOk', 'skippedNoMapping', 'errors'));

        return [
            'updated' => $updated,
            'same' => 0,
            'skipped_no_mapping' => $skippedNoMapping,
            'errors' => $errors,
        ];
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

        // Marca Woo desde fabricante TEN (ten_fabricante -> fabricantes.ten_id_numero -> woocommerce_marca_id)
        static $wooBrandByTenFabricanteId = null;
        if ($wooBrandByTenFabricanteId === null) {
            $wooBrandByTenFabricanteId = Fabricante::query()
                ->whereNotNull('woocommerce_marca_id')
                ->pluck('woocommerce_marca_id', 'ten_id_numero')
                ->map(static fn ($v) => (int) $v)
                ->all();
        }
        $tenFabricanteId = (int) ($p->ten_fabricante ?? 0);
        $wooBrandId = (int) ($wooBrandByTenFabricanteId[$tenFabricanteId] ?? 0);
        if ($wooBrandId > 0) {
            $payload['brands'] = [['id' => $wooBrandId]];
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
