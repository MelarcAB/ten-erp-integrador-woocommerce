<?php

namespace App\Console\Commands;

use App\Integrations\TenClient;
use App\Models\Cliente;
use App\Models\PedidoLineas;
use App\Models\Pedidos;
use App\Models\Producto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProdSyncPedidos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:prod-sync-pedidos
        {--limit=50 : Máximo de pedidos a procesar}
        {--dry-run : No llama a TEN ni escribe en BD}
        {--order-id= : Procesa solo un pedido por woocommerce_id}
        {--only-pending : Solo procesa sync_status=pending (por defecto también error)}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync (creación) de pedidos: APP(DB de WC) -> TEN (/Orders/Set).';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $marker = '[TEN_ORDERS_SYNC_PROD v1]';
        $this->line($marker . ' start');
        Log::info($marker . ' start');

        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $onlyPending = (bool) $this->option('only-pending');

        $statuses = $onlyPending ? ['pending'] : ['pending', 'error'];

        $query = Pedidos::query()
            ->with(['lineas'])
            ->whereNull('ten_id')
            ->whereIn('sync_status', $statuses)
            ->orderBy('woocommerce_id')
            ->limit($limit);

        if ($orderId = $this->option('order-id')) {
            $query->where('woocommerce_id', (int) $orderId);
        }

        $pedidos = $query->get();
        $this->info('Pedidos a procesar: ' . $pedidos->count());

        if ($pedidos->isEmpty()) {
            $this->info($marker . ' done (no pending)');
            return self::SUCCESS;
        }

        /** @var TenClient $ten */
        $ten = app(TenClient::class);

        $sent = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($pedidos as $pedido) {
            if (!($pedido instanceof Pedidos)) continue;

            $wooOrderId = (int) ($pedido->woocommerce_id ?? 0);

            try {
                $payload = $this->mapPedidoToTenOrderPayload($pedido);

                if ($dryRun) {
                    $this->line('DRY RUN order payload: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $sent++;
                    continue;
                }

                $response = $ten->setOrders([$payload]);
                $parsed = $this->parseTenSetOrdersResponse($response);

                $hasExceptions = !empty($parsed['exceptions']);
                $tenId = $parsed['order_id_ten'];
                $hasValidTenId = $tenId !== null && $tenId !== '' && $tenId !== '0';

                if ($hasExceptions || !$hasValidTenId) {
                    $errors++;

                    $errParts = [];
                    if ($hasExceptions) {
                        $errParts[] = 'TEN Exceptions: ' . implode(' | ', array_map('strval', $parsed['exceptions']));
                    }
                    if (!$hasValidTenId) {
                        $errParts[] = 'TEN no devolvió IdTen válido (IdTen=' . ($tenId ?? 'null') . ')';
                    }
                    $errMsg = implode(' ; ', $errParts);
                    if ($errMsg === '') $errMsg = 'TEN devolvió error desconocido en Orders/Set';

                    $pedido->sync_status = 'error';
                    $pedido->last_error = $errMsg;
                    $pedido->save();

                    $this->error("Pedido woo_id={$wooOrderId} -> TEN error: {$errMsg}");
                    Log::warning($marker . ' TEN Orders/Set returned error', [
                        'pedido_woocommerce_id' => $wooOrderId,
                        'payload' => ['Orders' => [$payload]],
                        'response' => $response,
                        'parsed' => $parsed,
                    ]);

                    continue;
                }

                DB::transaction(function () use ($pedido, $tenId) {
                    $pedido->ten_id = (string) $tenId;
                    $pedido->sync_status = 'synced';
                    $pedido->last_error = null;
                    $pedido->ten_last_fetched_at = now();
                    $pedido->save();

                    foreach ($pedido->lineas as $linea) {
                        if (!($linea instanceof PedidoLineas)) continue;
                        $linea->sync_status = 'synced';
                        $linea->last_error = null;
                        $linea->ten_last_fetched_at = now();
                        $linea->save();
                    }
                });

                $sent++;
                $this->info("OK pedido woo_id={$wooOrderId} -> ten_id={$tenId}");
            } catch (Throwable $e) {
                $errors++;
                $msg = $e->getMessage();

                $pedido->sync_status = 'error';
                $pedido->last_error = $msg;
                $pedido->save();

                $this->error("Pedido woo_id={$wooOrderId} error: {$msg}");
                Log::error($marker . ' TEN Orders/Set failed', [
                    'pedido_woocommerce_id' => $wooOrderId,
                    'error' => $msg,
                ]);
            }
        }

        $this->info("Resultado: sent={$sent} | skipped={$skipped} | errors={$errors}");
        Log::info($marker . ' done', compact('sent', 'skipped', 'errors'));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Map DB -> payload TEN /Orders/Set (solo creación).
     */
    private function mapPedidoToTenOrderPayload(Pedidos $pedido): array
    {
        $cliente = Cliente::query()->where('woocommerce_id', (int) $pedido->cliente_id)->first();
        if (!$cliente) {
            throw new \RuntimeException('Cliente no encontrado para pedido (cliente_id=' . (string)$pedido->cliente_id . ')');
        }
        if (empty($cliente->ten_id)) {
            throw new \RuntimeException('Cliente sin ten_id (woocommerce_id=' . (string)$cliente->woocommerce_id . ')');
        }

        $idDireccionEnvio = (string)($cliente->ten_id_direccion_envio ?? '0');
        if ($idDireccionEnvio === '' || $idDireccionEnvio === '0') {
            $idDireccionEnvio = '0';
        }

        $idDireccionFacturacion = '0';

        $fecha = $pedido->wc_date_created ? $pedido->wc_date_created->format('Y-m-d H:i:s') : now()->format('Y-m-d H:i:s');
        $fechaEntrega = $pedido->wc_date_completed ? $pedido->wc_date_completed->format('Y-m-d H:i:s') : $fecha;

        $importe = (string) ($pedido->total ?? '0');
        $importePortes = (string) ($pedido->shipping_total ?? '0');
        $importeDivisa = $importe;
        $importePVP = $importe;

        $divisa = (string) ($pedido->currency ?? 'EUR');

        $formaPago = trim((string) ($pedido->payment_method_title ?? ''));
        if ($formaPago !== '') {
            $formaPago = mb_substr($formaPago, 0, 14);
        }

        $cobrado = '1';

        $lineas = [];
        foreach ($pedido->lineas as $linea) {
            if (!($linea instanceof PedidoLineas)) continue;

            $wcProductId = (int) ($linea->woocommerce_product_id ?? 0);
            $producto = null;
            if ($wcProductId > 0) {
                $producto = Producto::query()->where('woocommerce_id', $wcProductId)->first();
            }

            if (!$producto || empty($producto->ten_id)) {
                throw new \RuntimeException('Línea sin producto con ten_id (wc_product_id=' . $wcProductId . ', sku=' . (string)($linea->sku ?? '') . ')');
            }

            $qty = (int) ($linea->quantity ?? 0);
            if ($qty <= 0) $qty = 1;

            $precio = $linea->price !== null ? (string) $linea->price : '0';
            $importeLinea = $linea->total !== null ? (string) $linea->total : (string) ((float)$precio * $qty);

            $lineas[] = [
                'IdProducto' => (string) $producto->ten_id,
                'CodigoProducto' => (string) ($producto->ten_codigo ?? $linea->sku ?? ''),
                'Descripcion' => (string) ($linea->name ?? $producto->ten_web_nombre ?? ''),
                'Unidades' => (string) $qty,
                'UnidadMedida' => '',
                'Variante' => '',
                'Precio' => (string) $precio,
                'PrecioDivisa' => (float) $precio,
                'PrecioPVP' => (string) $precio,
                'Dto1' => '0',
                'Dto2' => '0',
                'Dto3' => '0',
                'Importe' => (string) $importeLinea,
                'ImporteDivisa' => (string) $importeLinea,
                'ImportePVP' => (string) $importeLinea,
                'PorcIVA' => '0.000',
                'PorcRecargo' => '0',
            ];
        }

        $shippingTotal = (float) ($pedido->shipping_total ?? 0);
        if ($shippingTotal > 0) {
            $shippingTenId = (string) (env('TEN_PRODUCT_ID_ENVIO') ?? '');
            if ($shippingTenId === '') {
                throw new \RuntimeException('TEN_PRODUCT_ID_ENVIO no está configurado en .env');
            }

            $shippingProducto = Producto::query()->where('ten_id', $shippingTenId)->first();
            $shippingCodigo = $shippingProducto?->ten_codigo ?: 'ENVIO';
            $shippingDesc = $shippingProducto?->ten_web_nombre ?: 'Gastos de envío';

            $lineas[] = [
                'IdProducto' => $shippingTenId,
                'CodigoProducto' => (string) $shippingCodigo,
                'Descripcion' => (string) $shippingDesc,
                'Unidades' => '1',
                'UnidadMedida' => '',
                'Variante' => '',
                'Precio' => (string) $shippingTotal,
                'PrecioDivisa' => (float) $shippingTotal,
                'PrecioPVP' => (string) $shippingTotal,
                'Dto1' => '0',
                'Dto2' => '0',
                'Dto3' => '0',
                'Importe' => (string) $shippingTotal,
                'ImporteDivisa' => (string) $shippingTotal,
                'ImportePVP' => (string) $shippingTotal,
                'PorcIVA' => '0.000',
                'PorcRecargo' => '0',
            ];
        }

        if (empty($lineas)) {
            throw new \RuntimeException('Pedido sin líneas.');
        }

        return [
            'Codigo' => (string) ($pedido->woocommerce_id ?? $pedido->getKey()),
            'Fecha' => $fecha,
            'FechaEntrega' => $fechaEntrega,
            'IdCliente' => (string) $cliente->ten_id,
            'IdDireccionEnvio' => (string) $idDireccionEnvio,
            'IdDireccionFacturacion' => (string) $idDireccionFacturacion,
            'IdTarifa' => (string) ((int)($cliente->ten_id_tarifa ?? 0)),
            'Importe' => (string) $importe,
            'ImporteDivisa' => (string) $importeDivisa,
            'ImportePVP' => (string) $importePVP,
            'ImportePortes' => (string) $importePortes,
            'FormaPago' => (string) $formaPago,
            'Cobrado' => (string) $cobrado,
            'Observacion' => (string) ($pedido->customer_note ?? ''),
            'Divisa' => (string) $divisa,
            'Vendedor' => 'WEB',
            'AditionalData' => (object) [],
            'Lineas' => $lineas,
        ];
    }

    /**
     * @return array{order_codigo:?string, order_id_ten:?string, exceptions:array}
     */
    private function parseTenSetOrdersResponse(array $response): array
    {
        $item = null;
        if (array_is_list($response) && isset($response[0]) && is_array($response[0])) {
            $item = $response[0];
        } elseif (isset($response['Orders'][0]) && is_array($response['Orders'][0])) {
            $item = $response['Orders'][0];
        }
        $item = is_array($item) ? $item : [];

        return [
            'order_codigo' => isset($item['Codigo']) ? (string)$item['Codigo'] : null,
            'order_id_ten' => isset($item['IdTen']) ? (string)$item['IdTen'] : (isset($item['Id']) ? (string)$item['Id'] : null),
            'exceptions' => is_array($item['Exceptions'] ?? null) ? $item['Exceptions'] : [],
        ];
    }
}
