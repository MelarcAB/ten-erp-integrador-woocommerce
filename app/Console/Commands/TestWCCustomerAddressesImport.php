<?php

namespace App\Console\Commands;

use App\Integrations\Mappers\WooCustomerAddressMapper;
use App\Integrations\WooCommerceClient;
use App\Models\Cliente;
use App\Models\Direcciones;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TestWCCustomerAddressesImport extends Command
{
    protected $signature = 'app:test-wc-customer-addresses-import
        {--per-page=100 : Per page (max 100)}
        {--page=1 : Page (starts at 1)}
        {--dry-run : No escribe en DB}
        {--chunk=0 : Tamaño de chunk fijo (0 = auto)}
    ';

    protected $description = 'Import direcciones (billing/shipping) desde WooCommerce. Solo si el cliente ya existe';

    public function handle(): int
    {
        $marker = '[WC_CUSTOMER_ADDRESSES_IMPORT v2]';
        $this->line($marker . ' start');
        Log::info($marker . ' start');

        $perPage  = (int) $this->option('per-page');
        $page     = (int) $this->option('page');
        $dryRun   = (bool) $this->option('dry-run');
        $chunkOpt = (int) $this->option('chunk');

        try {
            /** @var WooCommerceClient $client */
            $client = app(WooCommerceClient::class);

            $this->info("GET /customers?per_page={$perPage}&page={$page}");
            $customers = $client->getClientes($perPage, $page);
        } catch (Throwable $e) {
            $this->error($marker . ' WC ERROR: ' . $e->getMessage());
            Log::error($marker . ' WC call failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        $totalFetched = count($customers);
        $this->info("Recibidos: {$totalFetched}");
        Log::info($marker . ' fetched', ['count' => $totalFetched]);

        if ($totalFetched === 0) return self::SUCCESS;

        // Woo IDs recibidos
        $wooIds = collect($customers)
            ->pluck('id')
            ->filter()
            ->map(fn ($v) => (int)$v)
            ->unique()
            ->values()
            ->all();

        // IMPORTANTE:
        // Tu tabla clientes NO tiene id (o no es id). No lo pidas.
        // Solo nos hace falta saber qué woocommerce_id existen.
        $clientesWooIds = Cliente::query()
            ->whereIn('woocommerce_id', $wooIds)
            ->pluck('woocommerce_id')
            ->map(fn ($v) => (int)$v)
            ->all();

        $clientesWooIds = array_flip($clientesWooIds); // lookup rápido

        $now = now();
        $rows = [];
        $skippedNoCliente = 0;

        $dbCols = $this->dbColumns();
        $dbColsFlip = array_flip($dbCols);

        foreach ($customers as $wcCustomer) {
            if (!is_array($wcCustomer)) continue;

            $wooId = (int)($wcCustomer['id'] ?? 0);
            if ($wooId <= 0) continue;

            // Solo si el cliente YA existe en tu tabla clientes
            if (!isset($clientesWooIds[$wooId])) {
                $skippedNoCliente++;
                continue;
            }

            // MENOS ES MÁS:
            // cliente_id en cliente_direcciones = woocommerce_id del cliente
            // (evita PK rara y evita FKs que ya te petaron)
            $clienteId = $wooId;

            $dirs = WooCustomerAddressMapper::toDirecciones($wcCustomer);

            foreach ($dirs as $attrs) {
                if (!is_array($attrs)) continue;

                // set relación
                $attrs['cliente_id'] = $clienteId;

                // mínimos
                if (empty($attrs['woocommerce_customer_id']) || empty($attrs['tipo'])) {
                    continue;
                }

                // sync / trazabilidad
                $attrs['sync_status'] = 'pending';
                $attrs['last_error'] = null;
                $attrs['ten_last_fetched_at'] = $now;

                $attrs['ten_hash'] = WooCustomerAddressMapper::hashFromAttributes($attrs);

                $attrs['created_at'] = $now;
                $attrs['updated_at'] = $now;

                $rows[] = array_intersect_key($attrs, $dbColsFlip);
            }
        }

        $this->info('Mapeadas direcciones: ' . count($rows) . " | customers sin cliente: {$skippedNoCliente}");
        Log::info($marker . ' mapped', [
            'rows' => count($rows),
            'skipped_no_cliente' => $skippedNoCliente,
        ]);

        if (empty($rows)) return self::SUCCESS;

        // dedup por woo_customer_id + tipo
        $before = count($rows);
        $rows = collect($rows)
            ->keyBy(fn ($r) => (int)$r['woocommerce_customer_id'] . ':' . (string)$r['tipo'])
            ->values()
            ->all();
        $after = count($rows);

        if ($after !== $before) {
            $this->warn("Dedup: {$before} -> {$after} (quitados " . ($before - $after) . ")");
            Log::warning($marker . ' dedup', ['before' => $before, 'after' => $after]);
        }

        if ($dryRun) {
            $this->warn('DRY RUN: no se escribirá en DB');
            $this->line(json_encode($rows[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        /**
         * Reglas:
         * - Insertar si no existe
         * - Update solo si cambió (ten_hash distinto)
         * - Si no cambió => skip (no write)
         *
         * Clave lógica: woocommerce_customer_id + tipo
         */
        $pairs = collect($rows)
            ->map(fn ($r) => [(int)$r['woocommerce_customer_id'], (string)$r['tipo']])
            ->values()
            ->all();

        $existing = [];
        // Query por ORs (pocos clientes normalmente). Simple y directo.
        $q = Direcciones::query()->select(['woocommerce_customer_id', 'tipo', 'ten_hash']);
        $q->where(function ($sub) use ($pairs) {
            foreach ($pairs as [$wooId, $tipo]) {
                $sub->orWhere(function ($w) use ($wooId, $tipo) {
                    $w->where('woocommerce_customer_id', $wooId)->where('tipo', $tipo);
                });
            }
        });

        foreach ($q->get() as $d) {
            $k = (int)$d->woocommerce_customer_id . ':' . (string)$d->tipo;
            $existing[$k] = (string)($d->ten_hash ?? '');
        }

        $toUpsert = [];
        $insertCount = 0;
        $updateCount = 0;
        $skipCount = 0;

        foreach ($rows as $r) {
            $k = (int)$r['woocommerce_customer_id'] . ':' . (string)$r['tipo'];
            $newHash = (string)$r['ten_hash'];

            if (!isset($existing[$k])) {
                $toUpsert[] = $r;
                $insertCount++;
                continue;
            }

            if ($existing[$k] === $newHash) {
                $skipCount++;
                continue;
            }

            // cambió => pending
            $r['sync_status'] = 'pending';
            $toUpsert[] = $r;
            $updateCount++;
        }

        $this->info("Insert: {$insertCount} | Update: {$updateCount} | Skip: {$skipCount}");
        Log::info($marker . ' diff', compact('insertCount','updateCount','skipCount'));

        if (empty($toUpsert)) {
            $this->info('Nada que insertar/actualizar.');
            return self::SUCCESS;
        }

        // Upsert por (woocommerce_customer_id, tipo)
        $updateColumns = array_values(array_diff(array_keys($toUpsert[0]), ['woocommerce_customer_id', 'tipo', 'created_at']));

        // chunking anti-placeholder
        $colsPerRow = count($toUpsert[0]);
        $maxPlaceholders = 60000;
        $autoChunk = max(100, (int) floor($maxPlaceholders / max(1, $colsPerRow)));
        $chunkSize = $chunkOpt > 0 ? $chunkOpt : $autoChunk;

        $chunks = array_chunk($toUpsert, $chunkSize);
        $this->info("Upsert en chunks: {$chunkSize} | chunks: " . count($chunks));
        Log::info($marker . ' chunking', ['chunk_size' => $chunkSize, 'cols_per_row' => $colsPerRow, 'auto' => $autoChunk]);

        $done = 0;

        foreach ($chunks as $i => $chunk) {
            $chunkNum = $i + 1;

            try {
                DB::transaction(function () use ($chunk, $updateColumns) {
                    Direcciones::upsert($chunk, ['woocommerce_customer_id', 'tipo'], $updateColumns);
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
            $this->line("OK chunk {$chunkNum}/" . count($chunks) . " | {$done}/" . count($toUpsert));
        }

        $this->info("OK: import direcciones completado ({$done} escritos).");
        Log::info($marker . ' success', ['written' => $done]);

        return self::SUCCESS;
    }

    private function dbColumns(): array
    {
        return [
            'cliente_id',
            'woocommerce_customer_id',
            'tipo',

            'sync_status',
            'last_error',

            // Woo
            'first_name',
            'last_name',
            'company',
            'address_1',
            'address_2',
            'city',
            'postcode',
            'state',
            'country',
            'email',
            'phone',

            // TEN (por ahora nulleable)
            'ten_codigo',
            'ten_id_ten',
            'ten_nombre',
            'ten_apellidos',
            'ten_direccion',
            'ten_direccion2',
            'ten_codigo_postal',
            'ten_poblacion',
            'ten_provincia',
            'ten_pais',
            'ten_telefono',
            'ten_fax',
            'ten_aditional_data',

            'ten_last_fetched_at',
            'ten_hash',

            'created_at',
            'updated_at',
            'deleted_at',
        ];
    }
}
