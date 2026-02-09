<?php

namespace App\Console\Commands;

use App\Integrations\Mappers\WooCustomerMapper;
use App\Integrations\WooCommerceClient;
use App\Models\Cliente;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TestWCCustomersImport extends Command
{
    protected $signature = 'app:test-wc-customers-import
        {--per-page=100 : Per page (max 100)}
        {--page=1 : Page (starts at 1)}
        {--email= : Filtrar por email}
        {--search= : Search general}
        {--dry-run : No escribe en DB}
        {--chunk=0 : Tamaño de chunk fijo (0 = auto)}
    ';

    protected $description = 'Import customers desde WooCommerce a tabla clientes (sin direcciones). Marca pending para exportar a TEN';

    public function handle(): int
    {
        $marker = '[WC_CUSTOMERS_IMPORT v1]';
        $this->line($marker . ' start');
        Log::info($marker . ' start');

        $perPage = (int) $this->option('per-page');
        $page    = (int) $this->option('page');
        $dryRun  = (bool) $this->option('dry-run');
        $chunkOpt = (int) $this->option('chunk');

        $params = [];
        if ($email = $this->option('email')) $params['email'] = $email;
        if ($search = $this->option('search')) $params['search'] = $search;

        try {
            /** @var WooCommerceClient $client */
            $client = app(WooCommerceClient::class);

            $this->info("GET /customers?per_page={$perPage}&page={$page}");
            $customers = $client->getClientes($perPage, $page, $params);
        } catch (Throwable $e) {
            $this->error($marker . ' WC ERROR: ' . $e->getMessage());
            Log::error($marker . ' WC call failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        $totalFetched = count($customers);
        $this->info("Recibidos: {$totalFetched}");
        Log::info($marker . ' fetched', ['count' => $totalFetched]);

        if ($totalFetched === 0) return self::SUCCESS;

        $now = now();
        $rows = [];
        $skippedNoWooId = 0;

        $dbCols = $this->dbColumns();
        $dbColsFlip = array_flip($dbCols);

        foreach ($customers as $wcRow) {
            if (!is_array($wcRow)) continue;

            $attrs = WooCustomerMapper::toClienteAttributes($wcRow);

            if (empty($attrs['woocommerce_id'])) {
                $skippedNoWooId++;
                continue;
            }

            // WC -> TEN: siempre entra como pendiente de exportar a TEN
            $attrs['sync_status'] = 'pending';
            $attrs['last_error'] = null;

            $attrs['ten_hash'] = WooCustomerMapper::hashFromAttributes($attrs);
            $attrs['ten_last_fetched_at'] = $now;

            $attrs['created_at'] = $now;
            $attrs['updated_at'] = $now;

            $rows[] = array_intersect_key($attrs, $dbColsFlip);
        }

        $this->line("Mapeados: " . count($rows) . " | sin woocommerce_id: {$skippedNoWooId}");
        Log::info($marker . ' mapped', ['valid_rows' => count($rows), 'skipped_no_woocommerce_id' => $skippedNoWooId]);

        if (count($rows) === 0) return self::SUCCESS;

        // Dedup por woocommerce_id
        $before = count($rows);
        $rows = collect($rows)->keyBy('woocommerce_id')->values()->all();
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
         * - Si cambió y estaba synced => pending (reencolar)
         * - Si NO cambió => skip total (no write)
         */
        $wooIds = array_map(fn ($r) => (int) $r['woocommerce_id'], $rows);

        $existing = [];
        foreach (array_chunk($wooIds, 1000) as $idsChunk) {
            $dbRows = Cliente::query()
                ->whereIn('woocommerce_id', $idsChunk)
                ->get(['woocommerce_id', 'ten_hash', 'sync_status'])
                ->all();

            foreach ($dbRows as $c) {
                $existing[(int) $c->woocommerce_id] = [
                    'ten_hash' => (string)($c->ten_hash ?? ''),
                    'sync_status' => (string)($c->sync_status ?? 'pending'),
                ];
            }
        }

        $toUpsert = [];
        $insertCount = 0;
        $updateCount = 0;
        $skipCount = 0;
        $requeuedCount = 0;

        foreach ($rows as $r) {
            $id = (int) $r['woocommerce_id'];
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

            // Cambió: reencola si estaba synced
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
        Log::info($marker . ' diff', compact('insertCount','updateCount','skipCount','requeuedCount'));

        if (empty($toUpsert)) {
            $this->info('Nada que insertar/actualizar.');
            return self::SUCCESS;
        }

        // Upsert por woocommerce_id (clave natural aquí)
        $updateColumns = array_values(array_diff(array_keys($toUpsert[0]), ['woocommerce_id', 'created_at']));

        // Chunks para no petar placeholders
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
                    Cliente::upsert($chunk, ['woocommerce_id'], $updateColumns);
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
            'ten_id',
            'ten_codigo',
            'woocommerce_id',

            'sync_status',
            'last_error',

            'email',
            'nombre',
            'apellidos',
            'nombre_fiscal',
            'nif',
            'ten_id_direccion_envio',
            'ten_id_grupo_clientes',
            'ten_regimen_impuesto',
            'ten_persona',
            'ten_id_tarifa',
            'ten_vendedor',
            'ten_forma_pago',
            'telefono',
            'telefono2',
            'web',
            'ten_calculo_iva_factura',
            'ten_enviar_emails',
            'ten_consentimiento_datos',

            'ten_last_fetched_at',
            'ten_hash',

            'created_at',
            'updated_at',
            'deleted_at',
        ];
    }
}
