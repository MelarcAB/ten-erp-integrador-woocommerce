<?php

namespace App\Console\Commands;

use App\Integrations\TenClient;
use App\Integrations\Mappers\TenCategoryMapper;
use App\Models\Categoria;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TestTenCategoriesImport extends Command
{
    protected $signature = 'app:test-ten-categories-import
        {--limit=100000 : TOP N (default 100000)}
        {--dry-run : No escribe en DB}
        {--chunk=0 : Tamaño de chunk fijo (0 = auto)}
    ';

    protected $description = 'Import masivo categorías desde TEN (/Query/Get tblCategoriasWeb) con upsert por chunks y diff por hash';

    public function handle(): int
    {
        $marker = '[TEN_CAT_IMPORT_MARKER v1]';
        $this->line($marker . ' start');
        Log::info($marker . ' start');

        $client   = app(TenClient::class);
        $limit    = (int) $this->option('limit');
        $dryRun   = (bool) $this->option('dry-run');
        $chunkOpt = (int) $this->option('chunk');

        try {
            $this->info('Llamando a TEN /Query/Get (tblCategoriasWeb) ...');
            $tenCats = $client->getCategorias($limit);
        } catch (Throwable $e) {
            $this->error('Error TEN: ' . $e->getMessage());
            Log::error($marker . ' TEN call failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        $totalFetched = count($tenCats);
        $this->info("Recibidos: {$totalFetched}");
        Log::info($marker . ' fetched', ['count' => $totalFetched]);

        if ($totalFetched === 0) return self::SUCCESS;

        $now = now();
        $rows = [];
        $skippedNoTenId = 0;

        $dbCols = $this->dbColumns();
        $dbColsFlip = array_flip($dbCols);

        foreach ($tenCats as $tenRow) {
            if (!is_array($tenRow)) continue;

            $attrs = TenCategoryMapper::toCategoriaAttributes($tenRow);

            if (empty($attrs['ten_id_numero'])) {
                $skippedNoTenId++;
                continue;
            }

            // TEN NO trae Woo => NULL SIEMPRE
            $attrs['woocommerce_categoria_id'] = null;
            $attrs['woocommerce_categoria_padre_id'] = null;

            // enable_sync: NO LO TOCAMOS nunca en import (se gestiona manualmente)
            // Si lo metes aquí, te lo cargas cada import. Lo excluimos del row.

            $attrs['ten_hash'] = TenCategoryMapper::hashFromAttributes($attrs);

            // Estado:
            // - nuevos => pending
            // - si cambia y estaba synced => pending (se decide en diff)
            $attrs['sync_status'] = 'pending';
            $attrs['last_error'] = null;
            $attrs['ten_last_fetched_at'] = $now;

            $attrs['created_at'] = $now;
            $attrs['updated_at'] = $now;

            // Filtrar solo columnas reales (y ojo: no queremos enable_sync)
            $row = array_intersect_key($attrs, $dbColsFlip);
            unset($row['enable_sync']); // seguridad extra

            $rows[] = $row;
        }

        $this->line("Mapeados: " . count($rows) . " | sin ten_id_numero: {$skippedNoTenId}");
        Log::info($marker . ' mapped', ['valid_rows' => count($rows), 'skipped_no_ten_id_numero' => $skippedNoTenId]);

        if (count($rows) === 0) return self::SUCCESS;

        // Dedup por ten_id_numero (por si TEN repite)
        $before = count($rows);
        $rows = collect($rows)->keyBy('ten_id_numero')->values()->all();
        $after = count($rows);
        if ($after !== $before) {
            $this->warn("Dedup: {$before} -> {$after} (quitados " . ($before - $after) . ")");
            Log::warning($marker . ' dedup', ['before' => $before, 'after' => $after]);
        }

        if ($dryRun) {
            $this->warn('DRY RUN: no se escribirá en DB.');
            $this->line('Ejemplo: ' . json_encode($rows[0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        /**
         * Reglas:
         * - Insertar solo si es nuevo
         * - Actualizar solo si cambió (ten_hash distinto)
         * - sync_status:
         *    - nuevos -> pending
         *    - si cambió y estaba synced -> pending
         *    - si NO cambió -> NO tocar DB
         *
         * Importante:
         * - NO tocar enable_sync
         * - NO tocar woocommerce_categoria_* (mapeo interno)
         */
        $tenIds = array_map(fn ($r) => (int) $r['ten_id_numero'], $rows);

        $existing = [];
        foreach (array_chunk($tenIds, 1000) as $idsChunk) {
            $dbRows = Categoria::query()
                ->whereIn('ten_id_numero', $idsChunk)
                ->get(['ten_id_numero', 'ten_hash', 'sync_status'])
                ->all();

            foreach ($dbRows as $c) {
                $existing[(int) $c->ten_id_numero] = [
                    'ten_hash' => (string) ($c->ten_hash ?? ''),
                    'sync_status' => (string) ($c->sync_status ?? 'pending'),
                ];
            }
        }

        $toUpsert = [];
        $insertCount = 0;
        $updateCount = 0;
        $skipCount = 0;
        $requeuedCount = 0;

        foreach ($rows as $r) {
            $id = (int) $r['ten_id_numero'];
            $newHash = (string) $r['ten_hash'];

            if (!isset($existing[$id])) {
                $toUpsert[] = $r;
                $insertCount++;
                continue;
            }

            $oldHash = $existing[$id]['ten_hash'];
            $oldStatus = $existing[$id]['sync_status'];

            if ($oldHash === $newHash) {
                $skipCount++;
                continue;
            }

            if ($oldStatus === 'synced') {
                $r['sync_status'] = 'pending';
                $requeuedCount++;
            } else {
                $r['sync_status'] = 'pending';
            }

            $toUpsert[] = $r;
            $updateCount++;
        }

        $this->info("Insert: {$insertCount} | Update: {$updateCount} | Skip: {$skipCount} | Requeued(synced->pending): {$requeuedCount}");
        Log::info($marker . ' diff', compact('insertCount', 'updateCount', 'skipCount', 'requeuedCount'));

        if (empty($toUpsert)) {
            $this->info('Nada que insertar/actualizar.');
            return self::SUCCESS;
        }

        // Columnas que se actualizan:
        // NO tocar created_at ni ten_id_numero
        // NO tocar enable_sync ni woocommerce_categoria_* (no vienen en $toUpsert, pero por seguridad no los ponemos)
        $updateColumns = array_values(array_diff(array_keys($toUpsert[0]), ['ten_id_numero', 'created_at']));

        // --- CHUNK SIZE AUTO para evitar "too many placeholders" ---
        $colsPerRow = count($dbCols);
        $maxPlaceholders = 60000;
        $autoChunk = max(200, (int) floor($maxPlaceholders / max(1, $colsPerRow)));
        $chunkSize = $chunkOpt > 0 ? $chunkOpt : $autoChunk;

        $this->info("Upsert en chunks: {$chunkSize} filas/chunk (cols={$colsPerRow}, auto={$autoChunk})");
        Log::info($marker . ' chunking', ['chunk_size' => $chunkSize, 'cols_per_row' => $colsPerRow, 'auto' => $autoChunk]);

        $total = count($toUpsert);
        $chunks = array_chunk($toUpsert, $chunkSize);
        $this->info("Total a escribir: {$total} | chunks: " . count($chunks));

        $done = 0;

        foreach ($chunks as $i => $chunk) {
            $chunkNum = $i + 1;

            try {
                DB::transaction(function () use ($chunk, $updateColumns) {
                    Categoria::upsert($chunk, ['ten_id_numero'], $updateColumns);
                });
            } catch (QueryException $e) {
                $this->error("Chunk {$chunkNum} petó: " . $e->getMessage());
                Log::error($marker . ' chunk failed', [
                    'chunk' => $chunkNum,
                    'chunk_size' => count($chunk),
                    'message' => $e->getMessage(),
                    'sql' => $e->getSql(),
                ]);
                return self::FAILURE;
            } catch (Throwable $e) {
                $this->error("Chunk {$chunkNum} petó: " . $e->getMessage());
                Log::error($marker . ' chunk failed (throwable)', [
                    'chunk' => $chunkNum,
                    'chunk_size' => count($chunk),
                    'message' => $e->getMessage(),
                ]);
                return self::FAILURE;
            }

            $done += count($chunk);
            $this->line("OK chunk {$chunkNum}/" . count($chunks) . " | {$done}/{$total}");
        }

        $this->info("OK: import completado ({$done} escritos).");
        Log::info($marker . ' success', ['written' => $done]);

        return self::SUCCESS;
    }

    private function dbColumns(): array
    {
        return [
            'ten_id_numero',
            'ten_codigo',

            // Woo mapping (NO tocar en import, pero existen como columnas)
            'woocommerce_categoria_id',
            'woocommerce_categoria_padre_id',

            // control
            'sync_status',
            'enable_sync',

            // TEN fields
            'ten_nombre',
            'ten_web_nombre',
            'ten_categoria_padre',
            'ten_ultimo_usuario',
            'ten_ultimo_cambio',
            'ten_alta_usuario',
            'ten_alta_fecha',
            'ten_web_sincronizar',
            'ten_bloqueado',
            'ten_usr_peso',

            // trace
            'ten_last_fetched_at',
            'ten_hash',
            'last_error',

            'created_at',
            'updated_at',
            'deleted_at',
        ];
    }
}
