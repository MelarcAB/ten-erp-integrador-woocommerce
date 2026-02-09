<?php

namespace App\Console\Commands;

use App\Integrations\WooCommerceClient;
use App\Models\Cliente;
use App\Models\PedidoLineas;
use App\Models\Pedidos;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TestWCPedidosImport extends Command
{
    protected $signature = 'app:test-wc-pedidos-import
        {--per-page=20 : Per page (max 100)}
        {--page=1 : Page (starts at 1)}
        {--status= : status (pending|processing|on-hold|completed|cancelled|refunded|failed|trash|any)}
        {--after= : ISO8601 after (e.g. 2026-02-01T00:00:00)}
        {--before= : ISO8601 before (e.g. 2026-02-09T23:59:59)}
        {--modified-after= : ISO8601 modified_after (if supported)}
        {--modified-before= : ISO8601 modified_before (if supported)}
        {--customer= : Woo customer id}
        {--search= : Search}
        {--orderby= : orderby (date|modified|id)}
        {--order= : order (asc|desc)}
        {--include= : comma-separated order ids (e.g. 10,11,12)}
        {--dry-run : No escribe en DB}
        {--chunk=0 : Tamaño de chunk fijo (0 = auto)}
        {--pretty : Pretty print (solo en dry-run)}
    ';

    protected $description = 'Import pedidos y líneas desde WooCommerce. Si no existe el cliente del pedido -> skip.';

    public function handle(): int
    {
        $marker = '[WC_ORDERS_IMPORT v1]';
        $this->line($marker . ' start');
        Log::info($marker . ' start');

        $perPage  = (int) $this->option('per-page');
        $page     = (int) $this->option('page');
        $dryRun   = (bool) $this->option('dry-run');
        $chunkOpt = (int) $this->option('chunk');

        $params = [];

        // Pasamos params TAL CUAL a WC (sin mapear nada)
        foreach ([
            'status' => 'status',
            'after' => 'after',
            'before' => 'before',
            'modified-after' => 'modified_after',
            'modified-before' => 'modified_before',
            'customer' => 'customer',
            'search' => 'search',
            'orderby' => 'orderby',
            'order' => 'order',
            'include' => 'include',
        ] as $opt => $key) {
            $val = $this->option($opt);
            if ($val !== null && $val !== '') {
                $params[$key] = $val;
            }
        }

        try {
            /** @var WooCommerceClient $client */
            $client = app(WooCommerceClient::class);

            $qs = http_build_query(array_merge(['per_page' => $perPage, 'page' => $page], $params));
            $this->info("GET /orders?{$qs}");

            $orders = $client->getPedidos($perPage, $page, $params);
        } catch (Throwable $e) {
            $this->error($marker . ' WC ERROR: ' . $e->getMessage());
            Log::error($marker . ' WC call failed', ['error' => $e->getMessage(), 'params' => $params]);
            return self::FAILURE;
        }

        $totalFetched = is_array($orders) ? count($orders) : 0;
        $this->info("Recibidos: {$totalFetched}");
        Log::info($marker . ' fetched', ['count' => $totalFetched, 'params' => $params, 'per_page' => $perPage, 'page' => $page]);

        if ($totalFetched === 0) return self::SUCCESS;

        // Woo order ids recibidos
        $wooOrderIds = collect($orders)
            ->pluck('id')
            ->filter()
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values()
            ->all();

        // Woo customer ids presentes
        $wooCustomerIds = collect($orders)
            ->pluck('customer_id')
            ->filter()
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values()
            ->all();

        // Lookup de qué clientes existen: cliente.woocommerce_id
        $clientesWooIds = [];
        if (!empty($wooCustomerIds)) {
            $clientesWooIds = Cliente::query()
                ->whereIn('woocommerce_id', $wooCustomerIds)
                ->pluck('woocommerce_id')
                ->map(fn ($v) => (int) $v)
                ->all();
            $clientesWooIds = array_flip($clientesWooIds);
        }

        $now = now();

        $pedidoCols = $this->pedidoDbColumns();
        $pedidoColsFlip = array_flip($pedidoCols);

        $lineaCols = $this->lineaDbColumns();
        $lineaColsFlip = array_flip($lineaCols);

        $pedidoRows = [];
        $lineaRowsByOrderId = []; // woocommerce_order_id => rows

        $skippedNoWooOrderId = 0;
        $skippedNoCliente = 0;
        $mappedPedidos = 0;
        $mappedLineas = 0;

        foreach ($orders as $wcOrder) {
            if (!is_array($wcOrder)) continue;

            $wooOrderId = (int) ($wcOrder['id'] ?? 0);
            if ($wooOrderId <= 0) {
                $skippedNoWooOrderId++;
                continue;
            }

            $wooCustomerId = (int) ($wcOrder['customer_id'] ?? 0);

            // Si no existe el cliente del pedido -> skip (pedido + sus líneas)
            if ($wooCustomerId <= 0 || !isset($clientesWooIds[$wooCustomerId])) {
                $skippedNoCliente++;
                continue;
            }

            // En este proyecto: cliente_id = woocommerce_id del cliente (igual que en direcciones)
            $clienteId = $wooCustomerId;

            $pedidoAttrs = $this->mapPedido($wcOrder);
            $pedidoAttrs['woocommerce_id'] = $wooOrderId;
            $pedidoAttrs['woocommerce_customer_id'] = $wooCustomerId;
            $pedidoAttrs['cliente_id'] = $clienteId;

            // sync / trazabilidad
            $pedidoAttrs['sync_status'] = 'pending';
            $pedidoAttrs['last_error'] = null;
            $pedidoAttrs['ten_last_fetched_at'] = $now;
            $pedidoAttrs['ten_hash'] = $this->hashFromAttributes($pedidoAttrs);
            $pedidoAttrs['created_at'] = $now;
            $pedidoAttrs['updated_at'] = $now;

            $pedidoRows[] = array_intersect_key($pedidoAttrs, $pedidoColsFlip);
            $mappedPedidos++;

            // line_items
            $wcLineItems = $wcOrder['line_items'] ?? [];
            if (!is_array($wcLineItems)) $wcLineItems = [];

            foreach ($wcLineItems as $li) {
                if (!is_array($li)) continue;

                $lineaAttrs = $this->mapLinea($li);
                $lineaAttrs['woocommerce_order_id'] = $wooOrderId;

                // por ahora pedido_id se resuelve después (post-upsert)
                $lineaAttrs['pedido_id'] = 0;

                // mínimos
                if (empty($lineaAttrs['woocommerce_line_item_id'])) {
                    continue;
                }

                $lineaAttrs['sync_status'] = 'pending';
                $lineaAttrs['last_error'] = null;
                $lineaAttrs['ten_last_fetched_at'] = $now;
                $lineaAttrs['ten_hash'] = $this->hashFromAttributes($lineaAttrs);
                $lineaAttrs['created_at'] = $now;
                $lineaAttrs['updated_at'] = $now;

                $row = array_intersect_key($lineaAttrs, $lineaColsFlip);
                $lineaRowsByOrderId[$wooOrderId][] = $row;
                $mappedLineas++;
            }
        }

        $this->info("Mapeados pedidos: {$mappedPedidos} | líneas: {$mappedLineas} | skip sin order_id: {$skippedNoWooOrderId} | skip sin cliente: {$skippedNoCliente}");
        Log::info($marker . ' mapped', [
            'mapped_pedidos' => $mappedPedidos,
            'mapped_lineas' => $mappedLineas,
            'skipped_no_woo_order_id' => $skippedNoWooOrderId,
            'skipped_no_cliente' => $skippedNoCliente,
        ]);

        if (empty($pedidoRows)) return self::SUCCESS;

        // Dedup pedidos por woocommerce_id
        $beforePedidos = count($pedidoRows);
        $pedidoRows = collect($pedidoRows)->keyBy('woocommerce_id')->values()->all();
        $afterPedidos = count($pedidoRows);
        if ($afterPedidos !== $beforePedidos) {
            $this->warn("Dedup pedidos: {$beforePedidos} -> {$afterPedidos}");
            Log::warning($marker . ' dedup pedidos', ['before' => $beforePedidos, 'after' => $afterPedidos]);
        }

        // Dedup líneas por (woocommerce_order_id, woocommerce_line_item_id)
        $allLineas = [];
        foreach ($lineaRowsByOrderId as $orderId => $rows) {
            $rows = collect($rows)
                ->keyBy(fn ($r) => (int) $r['woocommerce_order_id'] . ':' . (int) $r['woocommerce_line_item_id'])
                ->values()
                ->all();
            foreach ($rows as $r) $allLineas[] = $r;
        }

        if ($dryRun) {
            $this->warn('DRY RUN: no se escribirá en DB.');
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
            if ($this->option('pretty')) $flags |= JSON_PRETTY_PRINT;
            $this->line('Ejemplo pedido: ' . json_encode($pedidoRows[0], $flags));
            if (!empty($allLineas)) {
                $this->line('Ejemplo línea: ' . json_encode($allLineas[0], $flags));
            }
            return self::SUCCESS;
        }

        // --- DIFF pedidos: insert/update/skip (por ten_hash) ---
        $existingPedidos = [];
        foreach (array_chunk($wooOrderIds, 1000) as $idsChunk) {
            $dbRows = Pedidos::query()
                ->whereIn('woocommerce_id', $idsChunk)
                ->get(['woocommerce_id', 'ten_hash', 'sync_status'])
                ->all();
            foreach ($dbRows as $p) {
                $existingPedidos[(int) $p->woocommerce_id] = [
                    'ten_hash' => (string) ($p->ten_hash ?? ''),
                    'sync_status' => (string) ($p->sync_status ?? 'pending'),
                ];
            }
        }

        $toUpsertPedidos = [];
        $insertPedidos = 0;
        $updatePedidos = 0;
        $skipPedidos = 0;
        $requeuedPedidos = 0;

        foreach ($pedidoRows as $r) {
            $id = (int) $r['woocommerce_id'];
            $newHash = (string) $r['ten_hash'];

            if (!isset($existingPedidos[$id])) {
                $toUpsertPedidos[] = $r;
                $insertPedidos++;
                continue;
            }

            $oldHash = $existingPedidos[$id]['ten_hash'];
            $oldStatus = $existingPedidos[$id]['sync_status'];

            if ($oldHash === $newHash) {
                $skipPedidos++;
                continue;
            }

            if ($oldStatus === 'synced') {
                $r['sync_status'] = 'pending';
                $requeuedPedidos++;
            } else {
                $r['sync_status'] = 'pending';
            }

            $toUpsertPedidos[] = $r;
            $updatePedidos++;
        }

        $this->info("Pedidos -> Insert: {$insertPedidos} | Update: {$updatePedidos} | Skip: {$skipPedidos} | Requeued: {$requeuedPedidos}");
        Log::info($marker . ' pedidos diff', compact('insertPedidos','updatePedidos','skipPedidos','requeuedPedidos'));

        if (!empty($toUpsertPedidos)) {
            $updateColumns = array_values(array_diff(array_keys($toUpsertPedidos[0]), ['woocommerce_id', 'created_at']));

            $colsPerRow = count($toUpsertPedidos[0]);
            $maxPlaceholders = 60000;
            $autoChunk = max(100, (int) floor($maxPlaceholders / max(1, $colsPerRow)));
            $chunkSize = $chunkOpt > 0 ? $chunkOpt : $autoChunk;

            $chunks = array_chunk($toUpsertPedidos, $chunkSize);
            $this->info("Upsert pedidos en chunks: {$chunkSize} | chunks: " . count($chunks));

            $done = 0;
            foreach ($chunks as $i => $chunk) {
                $chunkNum = $i + 1;
                try {
                    DB::transaction(function () use ($chunk, $updateColumns) {
                        Pedidos::upsert($chunk, ['woocommerce_id'], $updateColumns);
                    });
                } catch (QueryException $e) {
                    $this->error("Pedidos chunk {$chunkNum} petó: " . $e->getMessage());
                    Log::error($marker . ' pedidos chunk failed', ['chunk' => $chunkNum, 'message' => $e->getMessage(), 'sql' => $e->getSql()]);
                    return self::FAILURE;
                } catch (Throwable $e) {
                    $this->error("Pedidos chunk {$chunkNum} petó: " . $e->getMessage());
                    Log::error($marker . ' pedidos chunk failed (throwable)', ['chunk' => $chunkNum, 'message' => $e->getMessage()]);
                    return self::FAILURE;
                }

                $done += count($chunk);
                $this->line("OK pedidos chunk {$chunkNum}/" . count($chunks) . " | {$done}/" . count($toUpsertPedidos));
            }
        } else {
            $this->info('Pedidos: nada que insertar/actualizar.');
        }

        // --- Resolver pedido_id para líneas y luego diff/upsert líneas ---
        $pedidoIdByWooId = Pedidos::query()
            ->whereIn('woocommerce_id', $wooOrderIds)
            ->pluck('id', 'woocommerce_id')
            ->mapWithKeys(fn ($id, $wooId) => [(int)$wooId => (int)$id])
            ->all();

        $lineaRows = [];
        $skippedLineasNoPedido = 0;
        foreach ($allLineas as $r) {
            $wooOrderId = (int) ($r['woocommerce_order_id'] ?? 0);
            if ($wooOrderId <= 0 || !isset($pedidoIdByWooId[$wooOrderId])) {
                $skippedLineasNoPedido++;
                continue;
            }

            $r['pedido_id'] = $pedidoIdByWooId[$wooOrderId];
            $lineaRows[] = $r;
        }

        if ($skippedLineasNoPedido > 0) {
            $this->warn("Líneas skip sin pedido_id: {$skippedLineasNoPedido}");
            Log::warning($marker . ' lineas skipped no pedido', ['count' => $skippedLineasNoPedido]);
        }

        if (empty($lineaRows)) {
            $this->info('No hay líneas a escribir.');
            Log::info($marker . ' done (no lineas)');
            return self::SUCCESS;
        }

        // Existing líneas por (woocommerce_order_id, woocommerce_line_item_id)
        $existingLineas = [];
        $q = PedidoLineas::query()->select(['woocommerce_order_id', 'woocommerce_line_item_id', 'ten_hash']);
        $q->whereIn('woocommerce_order_id', $wooOrderIds);
        foreach ($q->get() as $l) {
            $k = (int)$l->woocommerce_order_id . ':' . (int)$l->woocommerce_line_item_id;
            $existingLineas[$k] = (string) ($l->ten_hash ?? '');
        }

        $toUpsertLineas = [];
        $insertLineas = 0;
        $updateLineas = 0;
        $skipLineas = 0;

        foreach ($lineaRows as $r) {
            $k = (int)$r['woocommerce_order_id'] . ':' . (int)$r['woocommerce_line_item_id'];
            $newHash = (string) $r['ten_hash'];

            if (!isset($existingLineas[$k])) {
                $toUpsertLineas[] = $r;
                $insertLineas++;
                continue;
            }

            if ($existingLineas[$k] === $newHash) {
                $skipLineas++;
                continue;
            }

            $r['sync_status'] = 'pending';
            $toUpsertLineas[] = $r;
            $updateLineas++;
        }

        $this->info("Líneas -> Insert: {$insertLineas} | Update: {$updateLineas} | Skip: {$skipLineas}");
        Log::info($marker . ' lineas diff', compact('insertLineas','updateLineas','skipLineas'));

        if (empty($toUpsertLineas)) {
            $this->info('Líneas: nada que insertar/actualizar.');
            Log::info($marker . ' success', ['written_pedidos' => count($toUpsertPedidos), 'written_lineas' => 0]);
            return self::SUCCESS;
        }

        // Upsert líneas: clave natural (woocommerce_order_id, woocommerce_line_item_id)
        $updateColumns = array_values(array_diff(array_keys($toUpsertLineas[0]), ['woocommerce_order_id', 'woocommerce_line_item_id', 'created_at']));

        $colsPerRow = count($toUpsertLineas[0]);
        $maxPlaceholders = 60000;
        $autoChunk = max(100, (int) floor($maxPlaceholders / max(1, $colsPerRow)));
        $chunkSize = $chunkOpt > 0 ? $chunkOpt : $autoChunk;

        $chunks = array_chunk($toUpsertLineas, $chunkSize);
        $this->info("Upsert líneas en chunks: {$chunkSize} | chunks: " . count($chunks));

        $done = 0;
        foreach ($chunks as $i => $chunk) {
            $chunkNum = $i + 1;
            try {
                DB::transaction(function () use ($chunk, $updateColumns) {
                    PedidoLineas::upsert($chunk, ['woocommerce_order_id', 'woocommerce_line_item_id'], $updateColumns);
                });
            } catch (QueryException $e) {
                $this->error("Líneas chunk {$chunkNum} petó: " . $e->getMessage());
                Log::error($marker . ' lineas chunk failed', ['chunk' => $chunkNum, 'message' => $e->getMessage(), 'sql' => $e->getSql()]);
                return self::FAILURE;
            } catch (Throwable $e) {
                $this->error("Líneas chunk {$chunkNum} petó: " . $e->getMessage());
                Log::error($marker . ' lineas chunk failed (throwable)', ['chunk' => $chunkNum, 'message' => $e->getMessage()]);
                return self::FAILURE;
            }

            $done += count($chunk);
            $this->line("OK líneas chunk {$chunkNum}/" . count($chunks) . " | {$done}/" . count($toUpsertLineas));
        }

        $this->info("OK: import pedidos completado (pedidos escritos: " . count($toUpsertPedidos) . ", líneas escritas: {$done}).");
        Log::info($marker . ' success', ['written_pedidos' => count($toUpsertPedidos), 'written_lineas' => $done]);

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $wc
     * @return array<string,mixed>
     */
    private function mapPedido(array $wc): array
    {
        $int = static fn ($v) => ($v === null || $v === '') ? null : (int) $v;
        $str = static fn ($v) => ($v === null) ? null : trim((string) $v);
        $num = static fn ($v) => ($v === null || $v === '') ? null : (string) $v; // Woo suele mandar números como string

        $attrs = [
            'ten_id' => null,
            'ten_codigo' => null,

            'woocommerce_parent_id' => $int($wc['parent_id'] ?? null),
            'woocommerce_number' => $str($wc['number'] ?? null),
            'woocommerce_order_key' => $str($wc['order_key'] ?? null),

            'status' => $str($wc['status'] ?? null),

            'currency' => $str($wc['currency'] ?? null),
            'prices_include_tax' => (bool)($wc['prices_include_tax'] ?? false),

            'discount_total' => $num($wc['discount_total'] ?? null),
            'discount_tax' => $num($wc['discount_tax'] ?? null),
            'shipping_total' => $num($wc['shipping_total'] ?? null),
            'shipping_tax' => $num($wc['shipping_tax'] ?? null),
            'cart_tax' => $num($wc['cart_tax'] ?? null),
            'total' => $num($wc['total'] ?? null),
            'total_tax' => $num($wc['total_tax'] ?? null),

            'payment_method' => $str($wc['payment_method'] ?? null),
            'payment_method_title' => $str($wc['payment_method_title'] ?? null),
            'transaction_id' => $str($wc['transaction_id'] ?? null),
            'customer_ip_address' => $str($wc['customer_ip_address'] ?? null),
            'customer_user_agent' => $str($wc['customer_user_agent'] ?? null),
            'created_via' => $str($wc['created_via'] ?? null),
            'customer_note' => $str($wc['customer_note'] ?? null),

            'wc_date_created' => $str($wc['date_created'] ?? null),
            'wc_date_modified' => $str($wc['date_modified'] ?? null),
            'wc_date_completed' => $str($wc['date_completed'] ?? null),
            'wc_date_paid' => $str($wc['date_paid'] ?? null),

            'billing' => is_array($wc['billing'] ?? null) ? $wc['billing'] : null,
            'shipping' => is_array($wc['shipping'] ?? null) ? $wc['shipping'] : null,
            'meta_data' => is_array($wc['meta_data'] ?? null) ? $wc['meta_data'] : null,
            'cart_hash' => $str($wc['cart_hash'] ?? null),
            'payment_url' => $str($wc['payment_url'] ?? null),

            // relaciones direcciones: por ahora null (se puede resolver luego)
            'direccion_1_id' => null,
            'direccion_2_id' => null,
        ];

        foreach ($attrs as $k => $v) {
            if (is_string($v) && $v === '') $attrs[$k] = null;
        }

        return $attrs;
    }

    /**
     * @param array<string,mixed> $li
     * @return array<string,mixed>
     */
    private function mapLinea(array $li): array
    {
        $int = static fn ($v) => ($v === null || $v === '') ? null : (int) $v;
        $str = static fn ($v) => ($v === null) ? null : trim((string) $v);
        $num = static fn ($v) => ($v === null || $v === '') ? null : (string) $v;

        $image = is_array($li['image'] ?? null) ? $li['image'] : null;

        $attrs = [
            'ten_id' => null,
            'ten_codigo' => null,

            'woocommerce_line_item_id' => $int($li['id'] ?? null),

            'woocommerce_product_id' => $int($li['product_id'] ?? null),
            'woocommerce_variation_id' => $int($li['variation_id'] ?? null),
            'sku' => $str($li['sku'] ?? null),
            'producto_id' => null,

            'name' => $str($li['name'] ?? null),
            'quantity' => (int)($li['quantity'] ?? 0),
            'tax_class' => $str($li['tax_class'] ?? null),

            'subtotal' => $num($li['subtotal'] ?? null),
            'subtotal_tax' => $num($li['subtotal_tax'] ?? null),
            'total' => $num($li['total'] ?? null),
            'total_tax' => $num($li['total_tax'] ?? null),

            'global_unique_id' => $str($li['global_unique_id'] ?? null),
            'price' => $num($li['price'] ?? null),

            'image_id' => $int($image['id'] ?? null),
            'image_src' => $str($image['src'] ?? null),

            'taxes' => is_array($li['taxes'] ?? null) ? $li['taxes'] : null,
            'meta_data' => is_array($li['meta_data'] ?? null) ? $li['meta_data'] : null,
        ];

        foreach ($attrs as $k => $v) {
            if (is_string($v) && $v === '') $attrs[$k] = null;
        }

        return $attrs;
    }

    /**
     * Hash estable para detectar cambios reales (mismo enfoque que WooCustomerMapper).
     *
     * @param array<string,mixed> $attrs
     */
    private function hashFromAttributes(array $attrs): string
    {
        $copy = $attrs;

        unset(
            $copy['sync_status'],
            $copy['last_error'],
            $copy['ten_last_fetched_at'],
            $copy['created_at'],
            $copy['updated_at'],
            $copy['deleted_at']
        );

        ksort($copy);

        return hash('sha256', json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function pedidoDbColumns(): array
    {
        return [
            'id',
            'woocommerce_id',
            'woocommerce_parent_id',
            'woocommerce_number',
            'woocommerce_order_key',

            'woocommerce_customer_id',
            'cliente_id',
            'direccion_1_id',
            'direccion_2_id',

            'status',
            'sync_status',
            'last_error',

            'currency',
            'prices_include_tax',
            'discount_total',
            'discount_tax',
            'shipping_total',
            'shipping_tax',
            'cart_tax',
            'total',
            'total_tax',

            'payment_method',
            'payment_method_title',
            'transaction_id',
            'customer_ip_address',
            'customer_user_agent',
            'created_via',
            'customer_note',

            'wc_date_created',
            'wc_date_modified',
            'wc_date_completed',
            'wc_date_paid',

            'billing',
            'shipping',
            'meta_data',
            'cart_hash',
            'payment_url',

            'ten_codigo',
            'ten_id',
            'ten_last_fetched_at',
            'ten_hash',

            'created_at',
            'updated_at',
            'deleted_at',
        ];
    }

    private function lineaDbColumns(): array
    {
        return [
            'id',
            'pedido_id',

            'woocommerce_line_item_id',
            'woocommerce_order_id',

            'woocommerce_product_id',
            'woocommerce_variation_id',
            'sku',
            'producto_id',

            'name',
            'quantity',
            'tax_class',
            'subtotal',
            'subtotal_tax',
            'total',
            'total_tax',

            'global_unique_id',
            'price',
            'image_id',
            'image_src',

            'taxes',
            'meta_data',

            'ten_codigo',
            'ten_id',

            'sync_status',
            'last_error',
            'ten_last_fetched_at',
            'ten_hash',

            'created_at',
            'updated_at',
            'deleted_at',
        ];
    }
}
