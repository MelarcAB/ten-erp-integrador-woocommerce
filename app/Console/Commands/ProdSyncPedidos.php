<?php

namespace App\Console\Commands;

use App\Integrations\TenClient;
use App\Models\Cliente;
use App\Models\Direcciones;
use App\Models\PedidoLineas;
use App\Models\Pedidos;
use App\Models\Producto;
use App\Integrations\WooCommerceClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProdSyncPedidos extends Command
{
    private const PRODUCT_PORC_IVA = '21.000';
    private const SHIPPING_PORC_IVA = '0.000';

    private $syncLogHandle = null;
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
        {--serie-id= : Fuerza IdSerie en el payload enviado a TEN}
        {--force-resend : Permite reenviar un pedido aunque ya tenga ten_id}
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
        $serieIdOpt = $this->option('serie-id');
        $serieId = is_string($serieIdOpt) && trim($serieIdOpt) !== '' ? trim($serieIdOpt) : null;
        $forceResend = (bool) $this->option('force-resend');

        if ($forceResend && !$this->option('order-id')) {
            $this->error('Usa --order-id junto con --force-resend.');
            return self::FAILURE;
        }

        // Paso 0: refrescar pedidos/líneas desde Woo a BD local
        $this->info('Refrescando pedidos y líneas desde WooCommerce...');
        $importArgs = [];
        if ($dryRun) {
            $importArgs['--dry-run'] = true;
        }
        if ($orderIdForImport = $this->option('order-id')) {
            $importArgs['--include'] = (string) ((int) $orderIdForImport);
        }
        $importExit = $this->call('app:prod-import-pedidos', $importArgs);
        if ($importExit !== self::SUCCESS) {
            $this->error('Falló el import local de pedidos desde WooCommerce. Se aborta sync a TEN.');
            Log::error($marker . ' pre-sync woo import failed', [
                'exit_code' => $importExit,
                'dry_run' => $dryRun,
                'order_id' => $orderIdForImport ?? null,
            ]);
            return self::FAILURE;
        }

        $statuses = $onlyPending ? ['pending'] : ['pending', 'error'];

        $query = Pedidos::query()
            ->with(['lineas'])
            ->whereIn('sync_status', $statuses)
            ->orderBy('woocommerce_id')
            ->limit($limit);

        if (!$forceResend) {
            $query->whereNull('ten_id');
        }

        if ($orderId = $this->option('order-id')) {
            $query->where('woocommerce_id', (int) $orderId);
        }

        $this->syncLogHandle = $this->openSyncLog();
        $this->writeSyncLog('START sync');

        $pedidos = $query->get();
        $this->info('Pedidos a procesar: ' . $pedidos->count());

        if ($pedidos->isEmpty()) {
            $this->info($marker . ' done (no pending)');
            $this->writeSyncLog('END sync (no pending)');
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
                $payload = $this->mapPedidoToTenOrderPayload($pedido, $dryRun, $serieId);
                $this->writeSyncLog("ORDER woo_id={$wooOrderId} lineas=" . count($payload['Lineas'] ?? []));
                $this->writeSyncLog('PAYLOAD ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                if (!empty($payload['Lineas']) && is_array($payload['Lineas'])) {
                    foreach ($payload['Lineas'] as $idx => $linea) {
                        if (!is_array($linea)) continue;
                        $this->writeSyncLog(
                            'LINEA ' . ($idx + 1) .
                            ' id_producto=' . ($linea['IdProducto'] ?? '') .
                            ' codigo=' . ($linea['CodigoProducto'] ?? '') .
                            ' desc=' . ($linea['Descripcion'] ?? '') .
                            ' unidades=' . ($linea['Unidades'] ?? '') .
                            ' precio=' . ($linea['Precio'] ?? '') .
                            ' importe=' . ($linea['Importe'] ?? '')
                        );
                    }
                }

                if ($dryRun) {
                    $this->line('DRY RUN order payload: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $sent++;
                    $this->writeSyncLog("ORDER woo_id={$wooOrderId} status=DRY_RUN");
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
                    $this->writeSyncLog("ORDER woo_id={$wooOrderId} status=ERROR msg=" . $errMsg);
                    Log::warning($marker . ' TEN Orders/Set returned error', [
                        'pedido_woocommerce_id' => $wooOrderId,
                        'payload' => ['Orders' => [$payload]],
                        'response' => $response,
                        'parsed' => $parsed,
                    ]);

                    continue;
                }

                DB::transaction(function () use ($pedido, $tenId, $forceResend) {
                    if (!$forceResend || empty($pedido->ten_id)) {
                        $pedido->ten_id = (string) $tenId;
                    }
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
                $resultLabel = $forceResend ? 'resent_ten_id' : 'ten_id';
                $this->info("OK pedido woo_id={$wooOrderId} -> {$resultLabel}={$tenId}");
                $this->writeSyncLog("ORDER woo_id={$wooOrderId} status=OK {$resultLabel}={$tenId}");
            } catch (Throwable $e) {
                $errors++;
                $msg = $e->getMessage();

                $pedido->sync_status = str_contains($msg, 'Cliente bloqueado para sync a TEN') ? 'disabled' : 'error';
                $pedido->last_error = $msg;
                $pedido->save();

                $this->error("Pedido woo_id={$wooOrderId} error: {$msg}");
                $this->writeSyncLog("ORDER woo_id={$wooOrderId} status=EXCEPTION msg=" . $msg);
                Log::error($marker . ' TEN Orders/Set failed', [
                    'pedido_woocommerce_id' => $wooOrderId,
                    'error' => $msg,
                ]);
            }
        }

        $this->info("Resultado: sent={$sent} | skipped={$skipped} | errors={$errors}");
        Log::info($marker . ' done', compact('sent', 'skipped', 'errors'));
        $this->writeSyncLog("END sync sent={$sent} skipped={$skipped} errors={$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    public function __destruct()
    {
        if (is_resource($this->syncLogHandle)) {
            fclose($this->syncLogHandle);
            $this->syncLogHandle = null;
        }
    }

    private function openSyncLog()
    {
        $dir = storage_path('logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $filename = 'log_pedidos_' . now()->format('Ymd') . '.log';
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        return @fopen($path, 'a');
    }

    private function writeSyncLog(string $line): void
    {
        if (!is_resource($this->syncLogHandle)) return;
        $ts = now()->format('Y-m-d H:i:s');
        @fwrite($this->syncLogHandle, '[' . $ts . '] ' . $line . PHP_EOL);
    }
    /**
     * Map DB -> payload TEN /Orders/Set (solo creación).
     */
    private function mapPedidoToTenOrderPayload(Pedidos $pedido, bool $allowMock = false, ?string $serieId = null): array
    {
        $cliente = Cliente::query()->where('woocommerce_id', (int) $pedido->cliente_id)->first();
        if (!$cliente) {
            throw new \RuntimeException('Cliente no encontrado para pedido (cliente_id=' . (string)$pedido->cliente_id . ')');
        }
        if ((string) ($cliente->sync_status ?? '') === 'disabled') {
            throw new \RuntimeException('Cliente bloqueado para sync a TEN (woocommerce_id=' . (string)$cliente->woocommerce_id . ')');
        }
        if (!$allowMock && empty($cliente->ten_id)) {
            throw new \RuntimeException('Cliente sin ten_id (woocommerce_id=' . (string)$cliente->woocommerce_id . ')');
        }

        $idDireccionFacturacion = $this->resolveTenDireccionId((int) ($pedido->direccion_1_id ?? 0), $allowMock, 'billing');
        $idDireccionEnvio = $this->resolveTenDireccionId((int) ($pedido->direccion_2_id ?? 0), $allowMock, 'shipping');
        $clienteDireccionEnvio = $this->normalizeTenId($cliente->ten_id_direccion_envio ?? null);

        $fallbackDireccionId = $idDireccionEnvio
            ?? $idDireccionFacturacion
            ?? $clienteDireccionEnvio
            ?? ($allowMock ? $this->mockTenDireccionId((int) ($pedido->direccion_2_id ?? 0), 'shipping') : null)
            ?? ($allowMock ? $this->mockTenDireccionId((int) ($pedido->direccion_1_id ?? 0), 'billing') : null);

        $idDireccionEnvio ??= $idDireccionFacturacion ?? $clienteDireccionEnvio ?? $fallbackDireccionId;
        $idDireccionFacturacion ??= $idDireccionEnvio ?? $clienteDireccionEnvio ?? $fallbackDireccionId;

        $idDireccionEnvio ??= '0';
        $idDireccionFacturacion ??= '0';

        $fecha = $pedido->wc_date_created ? $pedido->wc_date_created->format('Y-m-d H:i:s') : now()->format('Y-m-d H:i:s');
        $fechaEntrega = $pedido->wc_date_completed ? $pedido->wc_date_completed->format('Y-m-d H:i:s') : $fecha;

        $importe = (string) ($pedido->total ?? '0');
        // El envío se manda como línea de producto; no duplicarlo como portes de cabecera.
        $importePortes = '0';
        $importeDivisa = $importe;
        $importePVP = $importe;

        $divisa = (string) ($pedido->currency ?? 'EUR');

        $formaPago = $this->resolveFormaPago(
            (string) ($pedido->payment_method ?? ''),
            (string) ($pedido->payment_method_title ?? '')
        );

        $cobrado = '1';

        $lineas = [];
        $auxTenId = (string) (env('TEN_PRODUCT_ID_AUXILIAR') ?? '');
        /** @var WooCommerceClient $woo */
        $woo = app(WooCommerceClient::class);
        foreach ($pedido->lineas as $linea) {
            if (!($linea instanceof PedidoLineas)) continue;

            $wcProductId = (int) ($linea->woocommerce_product_id ?? 0);
            $producto = null;
            if ($wcProductId > 0) {
                $producto = Producto::query()->where('woocommerce_id', $wcProductId)->first();
            }

            if (!$producto || empty($producto->ten_id)) {
                if ($auxTenId === '') {
                    throw new \RuntimeException('Pedido omitido: producto sin ten_id y TEN_PRODUCT_ID_AUXILIAR no configurado (wc_product_id=' . $wcProductId . ', sku=' . (string)($linea->sku ?? '') . ')');
                }
            }

            $qty = (int) ($linea->quantity ?? 0);
            if ($qty <= 0) $qty = 1;

            $precio = $linea->price !== null ? (string) $linea->price : '0';
            $importeLinea = $linea->total !== null ? (string) $linea->total : (string) ((float)$precio * $qty);

            $useAux = (!$producto || empty($producto->ten_id));
            $auxDescripcion = (string) ($linea->name ?? $linea->sku ?? 'Producto auxiliar');
            if ($useAux && $wcProductId > 0) {
                try {
                    $wcProd = $woo->getProductoById($wcProductId);
                    if (is_array($wcProd) && !empty($wcProd['name'])) {
                        $auxDescripcion = (string) $wcProd['name'];
                    }
                } catch (\Throwable $e) {
                    // silent fallback: usamos nombre de la línea
                }
            }
            $lineas[] = [
                'IdProducto' => $useAux ? $auxTenId : (string) $producto->ten_id,
                'CodigoProducto' => $useAux ? '-' : (string) ($producto->ten_codigo ?? $linea->sku ?? ''),
                'Descripcion' => $useAux
                    ? $auxDescripcion
                    : (string) ($linea->name ?? $producto->ten_web_nombre ?? ''),
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
                'PorcIVA' => self::PRODUCT_PORC_IVA,
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
                'PorcIVA' => self::SHIPPING_PORC_IVA,
                'PorcRecargo' => '0',
            ];
        }

        if (empty($lineas)) {
            throw new \RuntimeException('Pedido sin líneas.');
        }

        $payload = [
            'Codigo' => (string) ($pedido->woocommerce_id ?? $pedido->getKey()),
            'Fecha' => $fecha,
            'FechaEntrega' => $fechaEntrega,
            'IdCliente' => $this->normalizeTenId($cliente->ten_id ?? null)
                ?? ($allowMock ? $this->mockTenClienteId((int) $cliente->woocommerce_id) : ''),
            // 'IdDireccionEnvio' => (string) $idDireccionEnvio,
            // 'IdDireccionFacturacion' => (string) $idDireccionFacturacion,
            'IdDireccionEnvio' => '0',
            'IdDireccionFacturacion' => '0',
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

        if ($serieId !== null) {
            $payload['IdSerie'] = (string) $serieId;
        }

        return $payload;
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

    private function resolveTenDireccionId(int $direccionId, bool $allowMock = false, string $kind = 'direccion'): ?string
    {
        if ($direccionId <= 0) {
            return $allowMock ? $this->mockTenDireccionId($direccionId, $kind) : null;
        }

        $tenId = Direcciones::query()
            ->whereKey($direccionId)
            ->value('ten_id_ten');

        return $this->normalizeTenId($tenId)
            ?? ($allowMock ? $this->mockTenDireccionId($direccionId, $kind) : null);
    }

    private function normalizeTenId(mixed $tenId): ?string
    {
        if ($tenId === null) return null;

        $value = trim((string) $tenId);
        if ($value === '' || $value === '0' || $value === '-1') {
            return null;
        }

        return $value;
    }

    private function resolveFormaPago(string $paymentMethod, string $paymentMethodTitle): string
    {
        $haystack = mb_strtolower(trim($paymentMethod . ' ' . $paymentMethodTitle));

        foreach ([
            'redsys',
            'tarjeta',
            'targeta',
            'card',
            'stripe',
            'credit card',
            'debit card',
        ] as $needle) {
            if (str_contains($haystack, $needle)) {
                return 'TARGETA';
            }
        }

        foreach ([
            'transfer',
            'transferencia',
            'bacs',
            'bank',
            'wire',
            'sepa',
        ] as $needle) {
            if (str_contains($haystack, $needle)) {
                return 'TRANSFERENCIA';
            }
        }

        return 'PENDIENTE';
    }

    private function mockTenClienteId(int $woocommerceId): string
    {
        return '__MOCK_CLIENT_TEN_ID_' . $woocommerceId . '__';
    }

    private function mockTenDireccionId(int $direccionId, string $kind): ?string
    {
        if ($direccionId <= 0) {
            return null;
        }

        return '__MOCK_' . strtoupper($kind) . '_TEN_ID_' . $direccionId . '__';
    }
}
