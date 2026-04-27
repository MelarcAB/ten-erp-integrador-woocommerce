<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesDailyEntityLog;
use App\Integrations\Mappers\WooCustomerAddressMapper;
use App\Integrations\Mappers\WooCustomerMapper;
use App\Integrations\WooCommerceClient;
use App\Models\Cliente;
use App\Models\Direcciones;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProdImportClients extends Command
{
    use WritesDailyEntityLog;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:prod-import-clients
        {--per-page=100 : Per page (max 100)}
        {--page=1 : Page (starts at 1)}
        {--max-pages=0 : Máximo de páginas a procesar (0 = sin límite)}
        {--dry-run : No escribe en DB}
        {--chunk=0 : Tamaño de chunk fijo (0 = auto)}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Importa clientes desde WooCommerce a BD (producción). Importa direcciones billing/shipping del cliente y usa el primer pedido solo como fallback.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $marker = '[WC_CUSTOMERS_PROD_IMPORT v1]';
        $this->initDailyEntityLog('clientes');
        $this->writeDailyEntityLog($marker . ' start');
        $this->line($marker . ' start');
        Log::info($marker . ' start');

        $perPage  = (int) $this->option('per-page');
        $page     = (int) $this->option('page');
        $maxPages = (int) $this->option('max-pages');
        $dryRun   = (bool) $this->option('dry-run');
        $chunkOpt = (int) $this->option('chunk');

        /** @var WooCommerceClient $client */
        $client = app(WooCommerceClient::class);

        $totalWritten = 0;
        $totalAddressesWritten = 0;
        $pagesDone = 0;

        while (true) {
            if ($maxPages > 0 && $pagesDone >= $maxPages) {
                $this->info("Límite alcanzado: max-pages={$maxPages}");
                break;
            }

            try {
                $this->info("GET /customers?per_page={$perPage}&page={$page}");
                $customers = $client->getClientes($perPage, $page);
            } catch (Throwable $e) {
                $this->writeDailyEntityLog($marker . ' WC ERROR: ' . $e->getMessage());
                $this->error($marker . ' WC ERROR: ' . $e->getMessage());
                Log::error($marker . ' WC call failed', ['error' => $e->getMessage(), 'page' => $page]);
                return self::FAILURE;
            }

            $totalFetched = count($customers);
            $this->writeDailyEntityLog("FETCH page={$page} count={$totalFetched}");
            $this->info("Página {$page} recibida: {$totalFetched}");
            Log::info($marker . ' fetched', ['count' => $totalFetched, 'page' => $page]);

            if ($totalFetched === 0) {
                break;
            }

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

                $attrs['sync_status'] = 'pending';
                $attrs['last_error'] = null;

                $attrs['ten_hash'] = WooCustomerMapper::hashFromAttributes($attrs);
                $attrs['ten_last_fetched_at'] = $now;

                $attrs['created_at'] = $now;
                $attrs['updated_at'] = $now;

                $rows[] = array_intersect_key($attrs, $dbColsFlip);
            }

            $this->line("Mapeados: " . count($rows) . " | sin woocommerce_id: {$skippedNoWooId}");
            $this->writeDailyEntityLog("MAP page={$page} valid_rows=" . count($rows) . " skipped_no_woocommerce_id={$skippedNoWooId}");
            Log::info($marker . ' mapped', ['valid_rows' => count($rows), 'skipped_no_woocommerce_id' => $skippedNoWooId]);

            if (empty($rows)) {
                $page++;
                $pagesDone++;
                continue;
            }

            // Dedup por woocommerce_id
            $rows = collect($rows)->keyBy('woocommerce_id')->values()->all();

            // Normaliza y filtra ids inválidos antes de tocar BD
            $rows = array_values(array_filter($rows, function ($r) {
                if (!is_array($r)) return false;
                if (!isset($r['woocommerce_id'])) return false;
                return (int) $r['woocommerce_id'] > 0;
            }));

            if (empty($rows)) {
                $this->info('Nada que procesar: 0 filas válidas con woocommerce_id.');
                $page++;
                $pagesDone++;
                continue;
            }

            $wooIds = array_values(array_unique(array_map(fn ($r) => (int) $r['woocommerce_id'], $rows)));

            $existing = [];
            foreach (array_chunk($wooIds, 1000) as $idsChunk) {
                $dbRows = Cliente::query()
                    ->whereIn('woocommerce_id', $idsChunk)
                    ->get(['woocommerce_id'])
                    ->all();
                foreach ($dbRows as $c) {
                    $existing[(int) $c->woocommerce_id] = true;
                }
            }

            $toInsert = array_values(array_filter($rows, fn ($r) => !isset($existing[(int)($r['woocommerce_id'] ?? 0)])));
            $insertCount = count($toInsert);
            $skipCount = count($rows) - $insertCount;

            $this->info("Insert: {$insertCount} | Skip (existentes): {$skipCount}");
            $this->writeDailyEntityLog("DIFF page={$page} insert={$insertCount} skip_existing={$skipCount}");
            Log::info($marker . ' diff', compact('insertCount', 'skipCount'));

            if ($dryRun) {
                $this->warn('DRY RUN: no se escribe en DB.');
                $page++;
                $pagesDone++;
                continue;
            }

            if (!empty($toInsert)) {
                $updateColumns = array_values(array_diff(array_keys($toInsert[0]), ['woocommerce_id', 'created_at']));
                $colsPerRow = count($dbCols);
                $maxPlaceholders = 60000;
                $autoChunk = max(200, (int) floor($maxPlaceholders / max(1, $colsPerRow)));
                $chunkSize = $chunkOpt > 0 ? $chunkOpt : $autoChunk;

                $this->info("Upsert en chunks: {$chunkSize} filas/chunk");
                Log::info($marker . ' chunking', ['chunk_size' => $chunkSize, 'cols_per_row' => $colsPerRow, 'auto' => $autoChunk]);

                $chunks = array_chunk($toInsert, $chunkSize);
                $done = 0;

                foreach ($chunks as $i => $chunk) {
                    $chunkNum = $i + 1;
                    try {
                        DB::transaction(function () use ($chunk, $updateColumns) {
                            Cliente::upsert($chunk, ['woocommerce_id'], $updateColumns);
                        });
                    } catch (QueryException $e) {
                        $this->writeDailyEntityLog("UPSERT_CLIENTS_ERROR chunk={$chunkNum} message=" . $e->getMessage());
                        $this->error("Chunk {$chunkNum} petó: " . $e->getMessage());
                        Log::error($marker . ' chunk failed', [
                            'chunk' => $chunkNum,
                            'chunk_size' => count($chunk),
                            'message' => $e->getMessage(),
                            'sql' => $e->getSql(),
                        ]);
                        return self::FAILURE;
                    } catch (Throwable $e) {
                        $this->writeDailyEntityLog("UPSERT_CLIENTS_ERROR chunk={$chunkNum} message=" . $e->getMessage());
                        $this->error("Chunk {$chunkNum} petó: " . $e->getMessage());
                        Log::error($marker . ' chunk failed (throwable)', [
                            'chunk' => $chunkNum,
                            'chunk_size' => count($chunk),
                            'message' => $e->getMessage(),
                        ]);
                        return self::FAILURE;
                    }

                    $done += count($chunk);
                    $this->line("OK chunk {$chunkNum}/" . count($chunks) . " | {$done}/" . count($toInsert));
                }

                $totalWritten += $done;
            }

            // --- NIF desde el primer pedido (meta _billing_wooccm9) ---
            $this->autoEnrichNifFromOrders($client, $wooIds, $now, $marker);

            // --- Direcciones desde la ficha del cliente Woo ---
            $addressesFromCustomers = $this->importAddressesFromCustomers($customers, $now, $marker);
            $totalAddressesWritten += $addressesFromCustomers;

            // --- Fallback desde el primer pedido solo si falta billing/shipping útil ---
            $addressesFromOrders = $this->importAddressesFromFirstOrders($client, $customers, $now, $marker);
            $totalAddressesWritten += $addressesFromOrders;

            $page++;
            $pagesDone++;
        }

        $this->writeDailyEntityLog("SUCCESS written_clients={$totalWritten} written_addresses={$totalAddressesWritten}");
        $this->info("OK: import completado. clientes escritos={$totalWritten} | direcciones escritas={$totalAddressesWritten}");
        Log::info($marker . ' success', ['written_clients' => $totalWritten, 'written_addresses' => $totalAddressesWritten]);

        return self::SUCCESS;
    }

    /**
     * Importa direcciones billing/shipping directamente del customer de WooCommerce.
     *
     * @param array<int, array<string,mixed>> $customers
     */
    private function importAddressesFromCustomers(array $customers, $now, string $marker): int
    {
        if (empty($customers)) return 0;

        $dbCols = $this->direccionDbColumns();
        $dbColsFlip = array_flip($dbCols);

        $wooIds = collect($customers)
            ->pluck('id')
            ->filter()
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values()
            ->all();

        if (empty($wooIds)) return 0;

        $clientesWooIds = Cliente::query()
            ->whereIn('woocommerce_id', $wooIds)
            ->pluck('woocommerce_id')
            ->map(fn ($v) => (int) $v)
            ->all();
        $clientesWooIds = array_flip($clientesWooIds);

        $rows = [];
        foreach ($customers as $wcCustomer) {
            if (!is_array($wcCustomer)) continue;

            $wooId = (int) ($wcCustomer['id'] ?? 0);
            if ($wooId <= 0 || !isset($clientesWooIds[$wooId])) continue;

            $dirs = WooCustomerAddressMapper::toDirecciones($wcCustomer);
            foreach ($dirs as $attrs) {
                if (!is_array($attrs)) continue;
                if (!$this->hasMeaningfulAddressData($attrs)) continue;

                $attrs['cliente_id'] = $wooId;
                $attrs['sync_status'] = 'pending';
                $attrs['last_error'] = null;
                $attrs['ten_last_fetched_at'] = $now;
                $attrs['ten_hash'] = WooCustomerAddressMapper::hashFromAttributes($attrs);
                $attrs['created_at'] = $now;
                $attrs['updated_at'] = $now;

                $rows[] = array_intersect_key($attrs, $dbColsFlip);
            }
        }

        return $this->upsertAddressRows($rows, $marker, 'customer');
    }

    /**
     * Para cada cliente, consulta su primer pedido y guarda direcciones billing/shipping
     * solo si en /customers faltan o vienen vacías.
     *
     * @param array<int, array<string,mixed>> $customers
     */
    private function importAddressesFromFirstOrders(WooCommerceClient $client, array $customers, $now, string $marker): int
    {
        if (empty($customers)) return 0;

        $dbCols = $this->direccionDbColumns();
        $dbColsFlip = array_flip($dbCols);
        $rows = [];

        $fallbackByCustomerId = [];
        foreach ($customers as $wcCustomer) {
            if (!is_array($wcCustomer)) continue;

            $wooId = (int) ($wcCustomer['id'] ?? 0);
            if ($wooId <= 0) continue;

            $types = $this->addressTypesNeedingFallback($wcCustomer);
            if (!empty($types)) {
                $fallbackByCustomerId[$wooId] = $types;
            }
        }

        if (empty($fallbackByCustomerId)) {
            $this->writeDailyEntityLog('ADDRESSES_FALLBACK no_missing_types');
            $this->line('Direcciones fallback pedido: no hace falta completar ninguna dirección.');
            return 0;
        }

        $wooIds = array_keys($fallbackByCustomerId);
        $clientesWooIds = Cliente::query()
            ->whereIn('woocommerce_id', $wooIds)
            ->pluck('woocommerce_id')
            ->map(fn ($v) => (int) $v)
            ->all();
        $clientesWooIds = array_flip($clientesWooIds);

        $noOrders = 0;
        $errors = 0;

        foreach ($wooIds as $wcCustomerId) {
            if (!isset($clientesWooIds[$wcCustomerId])) continue;

            try {
                $orders = $client->getPedidos(1, 1, [
                    'customer' => $wcCustomerId,
                    'orderby' => 'date',
                    'order' => 'asc',
                    'status' => 'any',
                ]);

                if (empty($orders) || !is_array($orders[0])) {
                    $noOrders++;
                    continue;
                }

                $order = $orders[0];
                $dirs = $this->mapOrderAddresses($order, $wcCustomerId);

                foreach ($dirs as $attrs) {
                    if (!in_array((string) ($attrs['tipo'] ?? ''), $fallbackByCustomerId[$wcCustomerId] ?? [], true)) {
                        continue;
                    }
                    if (empty($attrs['woocommerce_customer_id']) || empty($attrs['tipo'])) {
                        continue;
                    }
                    if (!$this->hasMeaningfulAddressData($attrs)) {
                        continue;
                    }

                    $attrs['cliente_id'] = $wcCustomerId;
                    $attrs['sync_status'] = 'pending';
                    $attrs['last_error'] = null;
                    $attrs['ten_last_fetched_at'] = $now;
                    $attrs['ten_hash'] = WooCustomerAddressMapper::hashFromAttributes($attrs);
                    $attrs['created_at'] = $now;
                    $attrs['updated_at'] = $now;

                    $rows[] = array_intersect_key($attrs, $dbColsFlip);
                }
            } catch (Throwable $e) {
                $this->writeDailyEntityLog("ADDRESSES_FALLBACK_FETCH_ERROR customer={$wcCustomerId} message=" . $e->getMessage());
                $errors++;
                $this->warn("Direcciones: error consultando pedidos customer={$wcCustomerId}: " . $e->getMessage());
                Log::warning($marker . ' orders fetch failed', [
                    'woocommerce_customer_id' => $wcCustomerId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $done = $this->upsertAddressRows($rows, $marker, 'first_order_fallback');
        if ($done === 0) {
            $this->writeDailyEntityLog("ADDRESSES_FALLBACK_EMPTY no_orders={$noOrders} errors={$errors}");
            $this->line("Direcciones fallback pedido: sin filas útiles (no_orders={$noOrders}, errors={$errors})");
            return 0;
        }

        $this->writeDailyEntityLog("ADDRESSES_FALLBACK written={$done} no_orders={$noOrders} errors={$errors}");
        $this->info("Direcciones fallback pedido: escritas {$done} (no_orders={$noOrders}, errors={$errors})");
        return $done;
    }

    /**
     * @param array<int, array<string,mixed>> $rows
     */
    private function upsertAddressRows(array $rows, string $marker, string $source): int
    {
        if (empty($rows)) {
            $this->writeDailyEntityLog("ADDRESSES_{$source} rows=0");
            $this->line("Direcciones {$source}: sin filas.");
            return 0;
        }

        $rows = collect($rows)
            ->keyBy(fn ($r) => (int)$r['woocommerce_customer_id'] . ':' . (string)$r['tipo'])
            ->values()
            ->all();

        $pairs = collect($rows)
            ->map(fn ($r) => [(int)$r['woocommerce_customer_id'], (string)$r['tipo']])
            ->values()
            ->all();

        $existing = [];
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
        foreach ($rows as $r) {
            $k = (int)$r['woocommerce_customer_id'] . ':' . (string)$r['tipo'];
            $newHash = (string)$r['ten_hash'];
            if (!isset($existing[$k]) || $existing[$k] !== $newHash) {
                $toUpsert[] = $r;
            }
        }

        if (empty($toUpsert)) {
            $this->writeDailyEntityLog("ADDRESSES_{$source} unchanged=1");
            $this->line("Direcciones {$source}: sin cambios (skip total).");
            return 0;
        }

        $updateColumns = array_values(array_diff(array_keys($toUpsert[0]), ['woocommerce_customer_id', 'tipo', 'created_at']));
        $colsPerRow = count($toUpsert[0]);
        $maxPlaceholders = 60000;
        $autoChunk = max(100, (int) floor($maxPlaceholders / max(1, $colsPerRow)));
        $chunkSize = $autoChunk;
        $chunks = array_chunk($toUpsert, $chunkSize);

        $done = 0;
        foreach ($chunks as $i => $chunk) {
            $chunkNum = $i + 1;
            try {
                DB::transaction(function () use ($chunk, $updateColumns) {
                    Direcciones::upsert($chunk, ['woocommerce_customer_id', 'tipo'], $updateColumns);
                });
            } catch (Throwable $e) {
                $this->writeDailyEntityLog("ADDRESSES_{$source}_ERROR chunk={$chunkNum} message=" . $e->getMessage());
                $this->warn("Direcciones {$source}: chunk {$chunkNum} falló: " . $e->getMessage());
                Log::warning($marker . ' addresses upsert failed', [
                    'source' => $source,
                    'chunk' => $chunkNum,
                    'chunk_size' => count($chunk),
                    'message' => $e->getMessage(),
                ]);
                return $done;
            }

            $done += count($chunk);
        }

        $this->writeDailyEntityLog("ADDRESSES_{$source} written={$done}");
        $this->info("Direcciones {$source}: escritas {$done}");
        return $done;
    }

    /**
     * @param array<string,mixed> $wcCustomer
     * @return array<int, string>
     */
    private function addressTypesNeedingFallback(array $wcCustomer): array
    {
        $missing = [];
        foreach (['billing', 'shipping'] as $tipo) {
            $addr = $wcCustomer[$tipo] ?? null;
            if (!is_array($addr) || !$this->hasMeaningfulAddressData($addr)) {
                $missing[] = $tipo;
            }
        }

        return $missing;
    }

    /**
     * @param array<string,mixed> $attrs
     */
    private function hasMeaningfulAddressData(array $attrs): bool
    {
        foreach ([
            'company',
            'address_1',
            'address_2',
            'city',
            'postcode',
            'state',
            'country',
            'email',
            'phone',
        ] as $field) {
            $value = $attrs[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Enriquecimiento NIF: para clientes con nif vacío, intenta obtenerlo del primer pedido
     * (meta _billing_wooccm9) y persistirlo.
     */
    private function autoEnrichNifFromOrders(WooCommerceClient $client, array $wooIds, $now, string $marker): void
    {
        $candidateIds = array_values(array_unique(array_filter($wooIds, fn ($v) => (int) $v > 0)));
        if (empty($candidateIds)) return;

        $scopeIds = Cliente::query()
            ->whereIn('woocommerce_id', $candidateIds)
            ->where(function ($q) {
                $q->whereNull('nif')->orWhere('nif', '')->orWhereRaw('TRIM(nif) = ""');
            })
            ->pluck('woocommerce_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $scopeIds = array_values(array_unique(array_filter($scopeIds, fn ($v) => (int) $v > 0)));
        if (empty($scopeIds)) return;

        $enriched = 0;
        $notFound = 0;
        $errors = 0;

        foreach ($scopeIds as $wcCustomerId) {
            try {
                $orders = $client->getPedidos(1, 1, [
                    'customer' => $wcCustomerId,
                    'orderby' => 'date',
                    'order' => 'asc',
                    'status' => 'any',
                ]);

                if (empty($orders) || !is_array($orders[0])) {
                    $notFound++;
                    continue;
                }

                $order = $orders[0];
                $meta = $order['meta_data'] ?? null;
                if (!is_array($meta)) {
                    $notFound++;
                    continue;
                }

                $nifValue = null;
                foreach ($meta as $m) {
                    if (!is_array($m)) continue;
                    $key = $m['key'] ?? null;
                    if ($key === '_billing_wooccm9' || $key === 'billing_wooccm9') {
                        $val = $m['value'] ?? null;
                        if (is_string($val) && trim($val) !== '') {
                            $nifValue = trim($val);
                        }
                        break;
                    }
                }

                if ($nifValue === null) {
                    $notFound++;
                    continue;
                }

                $cliente = Cliente::query()->where('woocommerce_id', $wcCustomerId)->first(['woocommerce_id', 'nif']);
                if (!$cliente) continue;
                if (is_string($cliente->nif) && trim($cliente->nif) !== '') continue;

                $affected = Cliente::query()->whereKey($cliente->woocommerce_id)->update([
                    'nif' => $nifValue,
                    'sync_status' => 'pending',
                    'last_error' => null,
                    'ten_last_fetched_at' => $now,
                    'ten_hash' => WooCustomerMapper::hashFromAttributes(array_merge(
                        $cliente->fresh()->toArray(),
                        ['nif' => $nifValue]
                    )),
                    'updated_at' => now(),
                ]);

                if ($affected) $enriched++;
            } catch (Throwable $e) {
                $this->writeDailyEntityLog("ENRICH_NIF_ERROR customer={$wcCustomerId} message=" . $e->getMessage());
                $errors++;
                Log::warning($marker . ' enrich nif failed', [
                    'woocommerce_customer_id' => $wcCustomerId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->writeDailyEntityLog("ENRICH_NIF ok={$enriched} not_found={$notFound} errors={$errors}");
        $this->info("Enrich NIF: ok={$enriched} | no encontrado={$notFound} | errores={$errors}");
        Log::info($marker . ' enrich nif done', compact('enriched','notFound','errors'));
    }

    /**
     * Mapea billing/shipping desde un pedido.
     *
     * @param array<string,mixed> $order
     * @return array<int, array<string,mixed>>
     */
    private function mapOrderAddresses(array $order, int $wcCustomerId): array
    {
        $dirs = [];

        $billing = $order['billing'] ?? null;
        if (is_array($billing)) {
            $dirs[] = $this->mapOneAddress($billing, 'billing', $wcCustomerId, $order);
        }

        $shipping = $order['shipping'] ?? null;
        if (is_array($shipping)) {
            $dirs[] = $this->mapOneAddress($shipping, 'shipping', $wcCustomerId, $order);
        }

        return $dirs;
    }

    /**
     * @param array<string,mixed> $addr
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    private function mapOneAddress(array $addr, string $tipo, int $wcCustomerId, array $order): array
    {
        $email = $addr['email'] ?? ($order['billing']['email'] ?? null);

        $attrs = [
            'woocommerce_customer_id' => $wcCustomerId,
            'tipo' => $tipo,

            'first_name' => $addr['first_name'] ?? null,
            'last_name'  => $addr['last_name'] ?? null,
            'company'    => $addr['company'] ?? null,
            'address_1'  => $addr['address_1'] ?? null,
            'address_2'  => $addr['address_2'] ?? null,
            'city'       => $addr['city'] ?? null,
            'postcode'   => $addr['postcode'] ?? null,
            'state'      => $addr['state'] ?? null,
            'country'    => $addr['country'] ?? null,
            'email'      => $email,
            'phone'      => $addr['phone'] ?? null,
        ];

        foreach ($attrs as $k => $v) {
            if (is_string($v) && trim($v) === '') {
                $attrs[$k] = null;
            }
        }

        return $attrs;
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

    private function direccionDbColumns(): array
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

            // TEN
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

    public function __destruct()
    {
        $this->closeDailyEntityLog();
    }
}
