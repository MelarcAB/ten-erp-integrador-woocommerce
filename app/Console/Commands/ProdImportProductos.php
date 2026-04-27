<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Console\Commands\Concerns\WritesDailyEntityLog;
use App\Integrations\TenClient;
use App\Integrations\Mappers\TenProductMapper;
use App\Models\ProductoCategoriaTen;
use App\Models\Producto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProdImportProductos extends Command
{
    use WritesDailyEntityLog;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:prod-import-productos {--modified-after=} {--include-blocked : Incluir productos bloqueados} {--exclude-blocked : Excluir productos bloqueados}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Importa productos desde TEN y los integra en la base de datos (producción)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $marker = '[TEN_IMPORT_PROD_PROD v1]';
        $this->initDailyEntityLog('productos');
        $this->writeDailyEntityLog($marker . ' INICIO');
        $this->line($marker . ' INICIO');
        Log::info($marker . ' INICIO');

        $client = app(TenClient::class);
        $modifiedAfterOpt = $this->option('modified-after') ?? null;
        $includeBlocked = true;
        if ($this->option('exclude-blocked')) {
            $includeBlocked = false;
        }
        $modifiedAfter = null;
        if ($modifiedAfterOpt) {
            if ($modifiedAfterOpt === 'all') {
                $modifiedAfter = \Carbon\Carbon::create(2020, 1, 1, 0, 0, 0);
            } else {
                try {
                    $modifiedAfter = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $modifiedAfterOpt);
                } catch (\Throwable) {
                    $this->error('Formato inválido para --modified-after. Usa "YYYY-MM-DD HH:MM:SS" o "all"');
                    $this->writeDailyEntityLog($marker . ' invalid modified-after value=' . $modifiedAfterOpt);
                    Log::error($marker . ' invalid modified-after', ['value' => $modifiedAfterOpt]);
                    return self::FAILURE;
                }
            }
        }
        try {
            $this->info('Llamando a TEN /Products/Get ...');
            $tenProducts = $client->getProducts($modifiedAfter, 100000, 0);
        } catch (Throwable $e) {
            $this->error('Error TEN: ' . $e->getMessage());
            $this->writeDailyEntityLog($marker . ' TEN ERROR: ' . $e->getMessage());
            Log::error($marker . ' TEN call failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        $totalFetched = count($tenProducts);
        $this->writeDailyEntityLog("FETCH count={$totalFetched}");
        $this->info("Recibidos: {$totalFetched}");
        Log::info($marker . ' fetched', ['count' => $totalFetched]);
        if ($totalFetched === 0) return self::SUCCESS;

        $now = now();
        $rows = [];
        $rowsRaw = [];
        $tenIds = [];
        $categoriaIdsByTenProductId = [];
        $categoryIdsByTenId = [];
        $fallbackTenIds = [];
        $skippedNoTenId = 0;
        $skippedBlocked = 0;
        $skippedNoCategoria = 0;
        $categoriaErrors = 0;

        $dbCols = $this->dbColumns();
        $dbColsFlip = array_flip($dbCols);

        $processed = 0;
        foreach ($tenProducts as $tenRow) {
            $processed++;
            if (!is_array($tenRow)) continue;
            $attrs = TenProductMapper::toProductoAttributes($tenRow);
            if (!empty($attrs['ten_bloqueado']) && ! $includeBlocked) {
                $skippedBlocked++;
                continue;
            }
            if (empty($attrs['ten_id'])) {
                $skippedNoTenId++;
                continue;
            }
            $tenId = (int) $attrs['ten_id'];
            $catIds = $this->extractCategoryIdsFromTenRow($tenRow);
            $categoriaIdsByTenProductId[$tenId] = $catIds;
            if (empty($catIds)) {
                $fallbackTenIds[] = $tenId;
            }
            $rowsRaw[] = $attrs;
            $tenIds[] = $tenId;
            if (($processed % 500) === 0) {
                $this->line("Mapeados base: {$processed}/{$totalFetched}");
            }
        }

        // Fallback de categoria principal via /Query/Get para productos sin Categorias[] en /Products/Get
        $categoriaByTenId = [];
        $categoriaFallbackErrorTenId = [];
        $tenIds = array_values(array_unique($tenIds));
        $fallbackTenIds = array_values(array_unique($fallbackTenIds));
        $catChunkSize = 200;
        $this->info("Obteniendo categoria fallback TEN: " . count($fallbackTenIds) . " en chunks de {$catChunkSize}");
        $catChunks = array_chunk($fallbackTenIds, $catChunkSize);
        foreach ($catChunks as $i => $chunk) {
            $chunkNum = $i + 1;
            try {
                $map = $client->getCategoriesFromProducts($chunk);
                foreach ($map as $k => $v) {
                    $categoriaByTenId[(int) $k] = $v;
                }
            } catch (Throwable $e) {
                $categoriaErrors += count($chunk);
                foreach ($chunk as $id) {
                    $categoriaFallbackErrorTenId[(int) $id] = true;
                }
                Log::warning($marker . ' categoria batch failed', [
                    'chunk' => $chunkNum,
                    'chunk_size' => count($chunk),
                    'message' => $e->getMessage(),
                ]);
            }
            if (($chunkNum % 5) === 0 || $chunkNum === count($catChunks)) {
                $this->line("Categorias fallback TEN: chunk {$chunkNum}/" . count($catChunks));
            }
        }

        $pivotRows = [];

        foreach ($rowsRaw as $attrs) {
            $tenId = (int) ($attrs['ten_id'] ?? 0);
            $catIds = $categoriaIdsByTenProductId[$tenId] ?? [];
            if (empty($catIds) && !isset($categoriaFallbackErrorTenId[$tenId])) {
                $fallbackCat = $categoriaByTenId[$tenId] ?? null;
                if (is_numeric($fallbackCat) && (int) $fallbackCat > 0) {
                    $catIds = [(int) $fallbackCat];
                }
            }
            $catIds = array_values(array_unique(array_filter($catIds, static fn ($v) => is_numeric($v) && (int) $v > 0)));
            $categoryIdsByTenId[$tenId] = $catIds;

            $primaryCatId = $catIds[0] ?? null;
            $attrs['categoria_ten_id'] = $primaryCatId;
            if ($primaryCatId === null) {
                $skippedNoCategoria++;
            }

            foreach ($catIds as $idx => $catId) {
                $pivotRows[] = [
                    'producto_ten_id' => $tenId,
                    'categoria_ten_id' => (int) $catId,
                    'orden' => $idx,
                    'is_primary' => $idx === 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            $attrs['woocommerce_id'] = null;
            $attrs['woocommerce_sku'] = null;
            $attrs['woocommerce_ean'] = null;
            $attrs['woocommerce_upc'] = null;
            $attrs['ten_hash'] = TenProductMapper::hashFromAttributes($attrs);
            $attrs['sync_status'] = 'pending';
            $attrs['last_error'] = null;
            $attrs['ten_last_fetched_at'] = $now;
            $attrs['created_at'] = $now;
            $attrs['updated_at'] = $now;
            $rows[] = array_intersect_key($attrs, $dbColsFlip);
        }

        $this->line(
            "Mapeados: " . count($rows)
            . " | sin ten_id: {$skippedNoTenId}"
            . " | bloqueados: {$skippedBlocked}"
            . " | sin categoria: {$skippedNoCategoria}"
            . " | errores categoria: {$categoriaErrors}"
        );
        Log::info($marker . ' mapped', [
            'valid_rows' => count($rows),
            'skipped_no_ten_id' => $skippedNoTenId,
            'skipped_blocked' => $skippedBlocked,
            'skipped_no_categoria' => $skippedNoCategoria,
            'categoria_errors' => $categoriaErrors,
        ]);
        $this->writeDailyEntityLog(
            "MAP valid_rows=" . count($rows)
            . " skipped_no_ten_id={$skippedNoTenId}"
            . " skipped_blocked={$skippedBlocked}"
            . " skipped_no_categoria={$skippedNoCategoria}"
            . " categoria_errors={$categoriaErrors}"
        );
        if (count($rows) === 0) return self::SUCCESS;

        // Dedup por ten_id
        $before = count($rows);
        $rows = collect($rows)->keyBy('ten_id')->values()->all();
        $after = count($rows);
        if ($after !== $before) {
            $this->warn("Dedup: {$before} -> {$after} (quitados " . ($before - $after) . ")");
            $this->writeDailyEntityLog("DEDUP before={$before} after={$after}");
            Log::warning($marker . ' dedup', ['before' => $before, 'after' => $after]);
        }

        // Buscar existentes
        $tenIds = array_map(fn($r) => (int)$r['ten_id'], $rows);
        $incomingCategoryFingerprintByTenId = $this->buildIncomingCategoryFingerprints($categoryIdsByTenId, $tenIds);
        $existingCategoryFingerprintByTenId = $this->loadExistingCategoryFingerprints($tenIds);
        $existing = [];
        foreach (array_chunk($tenIds, 1000) as $idsChunk) {
            $dbRows = Producto::query()
                ->whereIn('ten_id', $idsChunk)
                ->get(['ten_id', 'ten_hash', 'sync_status'])
                ->all();
            foreach ($dbRows as $p) {
                $existing[(int)$p->ten_id] = [
                    'ten_hash' => (string)($p->ten_hash ?? ''),
                    'sync_status' => (string)($p->sync_status ?? 'pending'),
                ];
            }
        }

        $toUpsert = [];
        $insertCount = 0;
        $updateCount = 0;
        $skipCount = 0;
        $requeuedCount = 0;
        $categoryChangedCount = 0;
        $categoryOnlyUpdateCount = 0;

        foreach ($rows as $r) {
            $id = (int)$r['ten_id'];
            $newHash = (string)$r['ten_hash'];
            $categoriesChanged = ($incomingCategoryFingerprintByTenId[$id] ?? '') !== ($existingCategoryFingerprintByTenId[$id] ?? '');
            if (!isset($existing[$id])) {
                $toUpsert[] = $r;
                $insertCount++;
                continue;
            }
            $oldHash = $existing[$id]['ten_hash'];
            $oldStatus = $existing[$id]['sync_status'];
            if ($oldHash === $newHash && !$categoriesChanged) {
                $skipCount++;
                continue;
            }

            if ($categoriesChanged) {
                $categoryChangedCount++;
                if ($oldHash === $newHash) {
                    $categoryOnlyUpdateCount++;
                }
            }

            if ($oldStatus === 'synced') {
                $requeuedCount++;
            }

            $r['sync_status'] = 'pending';
            if ($oldStatus === 'synced') {
                $r['last_error'] = null;
            }
            $toUpsert[] = $r;
            $updateCount++;
        }

        $this->info(
            "Insert: {$insertCount} | Update: {$updateCount} | Skip: {$skipCount}"
            . " | Requeued(synced->pending): {$requeuedCount}"
            . " | CatChanged: {$categoryChangedCount}"
            . " | CatOnly: {$categoryOnlyUpdateCount}"
        );
        Log::info($marker . ' diff', compact(
            'insertCount',
            'updateCount',
            'skipCount',
            'requeuedCount',
            'categoryChangedCount',
            'categoryOnlyUpdateCount'
        ));
        $this->writeDailyEntityLog(
            "DIFF insert={$insertCount} update={$updateCount} skip={$skipCount} requeued={$requeuedCount} category_changed={$categoryChangedCount} category_only={$categoryOnlyUpdateCount}"
        );
        $done = 0;
        if (!empty($toUpsert)) {
            $updateColumns = array_values(array_diff(array_keys($toUpsert[0]), ['ten_id', 'created_at']));
            $colsPerRow = count($dbCols);
            $maxPlaceholders = 60000;
            $autoChunk = max(200, (int) floor($maxPlaceholders / max(1, $colsPerRow)));
            $chunkSize = $autoChunk;
            $this->info("Upsert en chunks: {$chunkSize} filas/chunk");
            Log::info($marker . ' chunking', ['chunk_size' => $chunkSize, 'cols_per_row' => $colsPerRow]);
            $total = count($toUpsert);
            $chunks = array_chunk($toUpsert, $chunkSize);
            $this->info("Total a escribir: {$total} | chunks: " . count($chunks));
            foreach ($chunks as $i => $chunk) {
                $chunkNum = $i + 1;
                try {
                    DB::transaction(function () use ($chunk, $updateColumns) {
                        Producto::upsert($chunk, ['ten_id'], $updateColumns);
                    });
                } catch (\Throwable $e) {
                    $this->error("Chunk {$chunkNum} falló: " . $e->getMessage());
                    $this->writeDailyEntityLog("UPSERT_ERROR chunk={$chunkNum} message=" . $e->getMessage());
                    Log::error($marker . ' chunk failed', [
                        'chunk' => $chunkNum,
                        'chunk_size' => count($chunk),
                        'message' => $e->getMessage(),
                    ]);
                    return self::FAILURE;
                }
                $done += count($chunk);
                $this->line("OK chunk {$chunkNum}/" . count($chunks) . " | {$done}/{$total}");
            }
        } else {
            $this->info('Nada que insertar/actualizar en productos (solo sync de categorías pivote).');
        }

        try {
            $syncedPivotRows = $this->syncPivotCategories($tenIds, $pivotRows);
            $this->info("Categorías pivote sincronizadas: {$syncedPivotRows} filas.");
            Log::info($marker . ' pivot synced', ['rows' => $syncedPivotRows, 'products' => count($tenIds)]);
        } catch (Throwable $e) {
            $this->error('Error sincronizando pivote producto-categorías: ' . $e->getMessage());
            $this->writeDailyEntityLog('PIVOT_SYNC_ERROR message=' . $e->getMessage());
            Log::error($marker . ' pivot sync failed', ['message' => $e->getMessage()]);
            return self::FAILURE;
        }
        $this->info("OK: import completado ({$done} escritos).");
        $this->writeDailyEntityLog("SUCCESS written={$done}");
        Log::info($marker . ' success', ['written' => $done]);
        return self::SUCCESS;
    }

    public function __destruct()
    {
        $this->closeDailyEntityLog();
    }

    /**
     * @param array<int, array<int, int>> $categoryIdsByTenId
     * @param array<int, int> $tenIds
     * @return array<int, string>
     */
    private function buildIncomingCategoryFingerprints(array $categoryIdsByTenId, array $tenIds): array
    {
        $fingerprints = [];
        foreach ($tenIds as $tenId) {
            $id = (int) $tenId;
            if ($id <= 0) {
                continue;
            }
            $fingerprints[$id] = $this->categoryFingerprint($categoryIdsByTenId[$id] ?? []);
        }

        return $fingerprints;
    }

    /**
     * @param array<int, int> $tenIds
     * @return array<int, string>
     */
    private function loadExistingCategoryFingerprints(array $tenIds): array
    {
        $tenIds = array_values(array_unique(array_filter($tenIds, static fn ($v) => is_numeric($v) && (int) $v > 0)));
        if (empty($tenIds)) {
            return [];
        }

        $byProduct = [];
        foreach (array_chunk($tenIds, 1000) as $idsChunk) {
            $rows = ProductoCategoriaTen::query()
                ->whereIn('producto_ten_id', $idsChunk)
                ->orderBy('producto_ten_id')
                ->orderBy('orden')
                ->get(['producto_ten_id', 'categoria_ten_id']);

            foreach ($rows as $row) {
                $productTenId = (int) ($row->producto_ten_id ?? 0);
                $categoryTenId = (int) ($row->categoria_ten_id ?? 0);
                if ($productTenId <= 0 || $categoryTenId <= 0) {
                    continue;
                }
                if (!isset($byProduct[$productTenId])) {
                    $byProduct[$productTenId] = [];
                }
                $byProduct[$productTenId][] = $categoryTenId;
            }
        }

        $fingerprints = [];
        foreach ($tenIds as $tenId) {
            $id = (int) $tenId;
            $fingerprints[$id] = $this->categoryFingerprint($byProduct[$id] ?? []);
        }

        return $fingerprints;
    }

    /**
     * @param array<int, mixed> $categoryIds
     */
    private function categoryFingerprint(array $categoryIds): string
    {
        $ids = array_map(static fn ($v) => (int) $v, $categoryIds);
        $ids = array_values(array_unique(array_filter($ids, static fn ($v) => $v > 0)));
        sort($ids);

        return implode('|', $ids);
    }

    private function dbColumns(): array
    {
        return [
            'ten_id',
            'ten_codigo',
            'woocommerce_id',
            'woocommerce_sku',
            'ten_ean',
            'ten_upc',
            'woocommerce_ean',
            'woocommerce_upc',
            'ten_id_grupo_productos',
            'ten_web_nombre',
            'ten_web_descripcion_corta',
            'ten_web_descripcion_larga',
            'ten_web_control_stock',
            'ten_precio',
            'ten_bloqueado',
            'ten_fabricante',
            'ten_referencia',
            'ten_catalogo',
            'ten_prioridad',
            'ten_fraccionar_formato_venta',
            'ten_peso',
            'ten_porc_impost',
            'ten_porc_recargo',
            'categoria_ten_id',
            'ten_last_fetched_at',
            'ten_hash',
            'sync_status',
            'last_error',
            'created_at',
            'updated_at',
            'deleted_at',
        ];
    }

    /**
     * @param array<string,mixed> $tenRow
     * @return array<int,int>
     */
    private function extractCategoryIdsFromTenRow(array $tenRow): array
    {
        $raw = $tenRow['Categorias'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $item) {
            $candidate = null;
            if (is_array($item)) {
                $candidate = $item['IdCategoria'] ?? $item['Id'] ?? $item['id'] ?? null;
            } else {
                $candidate = $item;
            }
            if (is_numeric($candidate) && (int) $candidate > 0) {
                $ids[] = (int) $candidate;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<int,int> $tenIds
     * @param array<int,array<string,mixed>> $pivotRows
     */
    private function syncPivotCategories(array $tenIds, array $pivotRows): int
    {
        $tenIds = array_values(array_unique(array_filter($tenIds, static fn ($v) => is_numeric($v) && (int) $v > 0)));

        if (!empty($tenIds)) {
            foreach (array_chunk($tenIds, 1000) as $idsChunk) {
                ProductoCategoriaTen::query()
                    ->whereIn('producto_ten_id', $idsChunk)
                    ->delete();
            }
        }

        if (empty($pivotRows)) {
            return 0;
        }

        $dedup = [];
        foreach ($pivotRows as $row) {
            $productTenId = (int) ($row['producto_ten_id'] ?? 0);
            $catTenId = (int) ($row['categoria_ten_id'] ?? 0);
            if ($productTenId <= 0 || $catTenId <= 0) {
                continue;
            }
            $key = $productTenId . ':' . $catTenId;
            $dedup[$key] = $row;
        }
        $rows = array_values($dedup);
        if (empty($rows)) {
            return 0;
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            ProductoCategoriaTen::query()->upsert(
                $chunk,
                ['producto_ten_id', 'categoria_ten_id'],
                ['orden', 'is_primary', 'updated_at']
            );
        }

        return count($rows);
    }
}
