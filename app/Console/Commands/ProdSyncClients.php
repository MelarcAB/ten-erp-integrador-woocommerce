<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesDailyEntityLog;
use App\Integrations\TenClient;
use App\Models\Cliente;
use App\Models\Direcciones;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProdSyncClients extends Command
{
    use WritesDailyEntityLog;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:prod-sync-clients
        {--modified-after= : Fecha "Y-m-d H:i:s" para el GET inicial a TEN (por defecto: 2022-01-01 00:00:00)}
        {--limit=500 : Máximo de clientes locales pendientes a procesar}
        {--dry-run : No escribe ni en DB ni llama a TEN/Set}
        {--use-legacy-get : Usa /customers/get en vez de /Customers/Get}
        {--retry-errors : Incluye clientes con sync_status=error}
        {--email= : Procesa solo un cliente por email (pending/error)}
        {--override-email= : Email alternativo a enviar a TEN para el cliente filtrado por --email}
        {--force-create : Ignora vinculación por email y omite IdTen para forzar alta del cliente filtrado por --email}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sube clientes importados de Woo a TEN y vincula por email los existentes.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $marker = '[TEN_CUSTOMERS_SYNC_PROD v1]';
        $this->initDailyEntityLog('clientes');
        $this->writeDailyEntityLog($marker . ' start');
        $this->line($marker . ' start');
        Log::info($marker . ' start');

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $emailFilterOpt = $this->option('email');
        $emailFilter = is_string($emailFilterOpt) && trim($emailFilterOpt) !== '' ? trim($emailFilterOpt) : null;
        $overrideEmailOpt = $this->option('override-email');
        $overrideEmail = is_string($overrideEmailOpt) && trim($overrideEmailOpt) !== '' ? trim($overrideEmailOpt) : null;
        $forceCreate = (bool) $this->option('force-create');
        $forceCreateTargetEmail = $forceCreate && $emailFilter !== null ? strtolower($emailFilter) : null;

        if (($forceCreate || $overrideEmail !== null) && $emailFilter === null) {
            $this->error('Usa --email junto con --override-email y/o --force-create.');
            return self::FAILURE;
        }

        if ($forceCreate) {
            $this->info('Modo force-create activo: se omitirá IdTen y se ignorará la vinculación por email para el cliente filtrado.');
        }
        if ($overrideEmail !== null) {
            $this->info('Override email TEN: ' . $overrideEmail);
        }

        // Paso 0: refrescar clientes/direcciones desde Woo a BD local
        $this->info('Refrescando clientes y direcciones desde WooCommerce...');
        $importArgs = [];
        if ($dryRun) {
            $importArgs['--dry-run'] = true;
        }
        $importExit = $this->call('app:prod-import-clients', $importArgs);
        if ($importExit !== self::SUCCESS) {
            $this->writeDailyEntityLog($marker . ' pre-sync woo import failed');
            $this->error('Falló el import local desde WooCommerce. Se aborta sync a TEN.');
            Log::error($marker . ' pre-sync woo import failed', ['exit_code' => $importExit, 'dry_run' => $dryRun]);
            return self::FAILURE;
        }

        $modifiedAfterOpt = $this->option('modified-after');
        $modifiedAfter = null;
        if (is_string($modifiedAfterOpt) && $modifiedAfterOpt !== '') {
            try {
                $modifiedAfter = Carbon::createFromFormat('Y-m-d H:i:s', $modifiedAfterOpt);
            } catch (Throwable) {
                $this->error('Formato inválido en --modified-after. Usa: Y-m-d H:i:s');
                return self::FAILURE;
            }
        }
        $modifiedAfter ??= Carbon::create(2022, 1, 1, 0, 0, 0);

        $this->info('TEN ModifiedAfter: ' . $modifiedAfter->format('Y-m-d H:i:s'));

        /** @var TenClient $ten */
        $ten = app(TenClient::class);

        // 1) GET TEN
        try {
            $tenCustomers = $this->option('use-legacy-get')
                ? $ten->getCustomersLegacy($modifiedAfter)
                : $ten->getCustomers($modifiedAfter);
        } catch (Throwable $e) {
            $this->writeDailyEntityLog($marker . ' TEN GET error: ' . $e->getMessage());
            $this->error($marker . ' TEN GET error: ' . $e->getMessage());
            Log::error($marker . ' ten get failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        $this->info('TEN customers recibidos: ' . count($tenCustomers));
        $this->writeDailyEntityLog('TEN_GET customers=' . count($tenCustomers));

        // Indexar por email
        $tenByEmail = [];
        foreach ($tenCustomers as $row) {
            if (!is_array($row)) continue;
            $email = strtolower(trim((string)($row['Email'] ?? '')));
            if ($email === '') continue;
            $tenByEmail[$email] = $row;
        }

        // 2) Vinculación por email (solo si local no tiene ten_id)
        $linked = 0;
        if (!$dryRun && !empty($tenByEmail)) {
            $localsToLink = Cliente::query()
                ->whereNull('ten_id')
                ->where('sync_status', '<>', 'disabled')
                ->whereNotNull('email')
                ->where('email', '<>', '')
                ->whereIn(DB::raw('LOWER(email)'), array_keys($tenByEmail))
                ->limit(5000)
                ->get();

            foreach ($localsToLink as $cliente) {
                $email = strtolower(trim((string)$cliente->email));
                if ($forceCreateTargetEmail !== null && $email === $forceCreateTargetEmail) {
                    continue;
                }
                $tenRow = $tenByEmail[$email] ?? null;
                if (!$tenRow) continue;

                $cliente->ten_id = (string)($tenRow['Id'] ?? null);
                $cliente->sync_status = 'synced';
                $cliente->last_error = null;
                $cliente->ten_last_fetched_at = now();

                // direcciones TEN -> mapping mínimo: asignar IdDireccionEnvio si existe y es válido (>0)
                $dirs = $tenRow['Direcciones'] ?? null;
                if (is_array($dirs) && isset($dirs[0]) && is_array($dirs[0])) {
                    $firstId = $dirs[0]['Id'] ?? null;
                    if ($firstId !== null) {
                        $firstIdInt = (int) $firstId;
                        if ($firstIdInt > 0) {
                            $cliente->ten_id_direccion_envio = (string) $firstIdInt;
                        }
                    }
                }

                $cliente->save();
                $linked++;
            }
        }

        $this->info("Vinculados por email (TEN->DB): {$linked}" . ($dryRun ? ' (dry-run)' : ''));
        $this->writeDailyEntityLog("LINKED_BY_EMAIL count={$linked} dry_run=" . ($dryRun ? '1' : '0'));

        // 3) Envío a TEN de pendientes (y opcionalmente errores)
        $statuses = ['pending'];
        if ((bool) $this->option('retry-errors')) {
            $statuses[] = 'error';
        }

        $pendingQuery = Cliente::query()->with('direcciones');
        if (!($forceCreate && $emailFilter !== null)) {
            $pendingQuery->whereIn('sync_status', $statuses);
        }

        if ($emailFilter !== null) {
            $pendingQuery->where('email', $emailFilter);
        }

        $pending = $pendingQuery
            ->orderBy('woocommerce_id')
            ->limit($limit)
            ->get();

        $this->info('Estados a procesar: ' . implode(',', $statuses));
        if (!empty($emailFilter)) {
            $this->info('Filtro email: ' . $emailFilter);
        }

        $this->info('Pendientes locales: ' . $pending->count());
        $this->writeDailyEntityLog('PENDING count=' . $pending->count() . ' statuses=' . implode(',', $statuses));

        if ($pending->isEmpty()) {
            $this->info($marker . ' done (no pending)');
            return self::SUCCESS;
        }

        $created = 0;
        $skippedAlreadyExists = 0;
        $errors = 0;

        foreach ($pending as $cliente) {
            $email = strtolower(trim((string) $cliente->email));
            $isForceCreateClient = $forceCreateTargetEmail !== null && $email === $forceCreateTargetEmail;

            // Si ya existe en TEN por email => vincular y marcar synced
            if (!$isForceCreateClient && $email !== '' && isset($tenByEmail[$email])) {
                if (!$dryRun) {
                    $cliente->ten_id = (string)($tenByEmail[$email]['Id'] ?? $cliente->ten_id);
                    $cliente->sync_status = 'synced';
                    $cliente->last_error = null;
                    $cliente->save();
                }
                $skippedAlreadyExists++;
                $this->writeDailyEntityLog("CUSTOMER_EXISTS email={$cliente->email} woo_id=" . ($cliente->woocommerce_id ?? $cliente->getKey()));
                continue;
            }

            $clientePayload = $this->mapClienteToTenPayload(
                $cliente,
                $isForceCreateClient ? $overrideEmail : null,
                $isForceCreateClient
            );

            if ($dryRun) {
                $this->writeDailyEntityLog('PAYLOAD ' . json_encode($clientePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->line('DRY RUN customer payload: ' . json_encode($clientePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $created++;
                continue;
            }

            try {
                $wrappedPayload = ['Customers' => [$clientePayload]];
                $this->writeDailyEntityLog(
                    'CUSTOMER_SEND woo_id=' . ($cliente->woocommerce_id ?? $cliente->getKey()) .
                    ' email=' . (string) $cliente->email .
                    ' payload=' . json_encode($wrappedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
                Log::info($marker . ' customer set payload', [
                    'cliente_woocommerce_id' => $cliente->woocommerce_id ?? $cliente->getKey(),
                    'email' => $cliente->email,
                    'payload' => $wrappedPayload,
                ]);

                $response = $ten->setCustomers([$clientePayload]);
                $this->writeDailyEntityLog(
                    'CUSTOMER_RESPONSE woo_id=' . ($cliente->woocommerce_id ?? $cliente->getKey()) .
                    ' email=' . (string) $cliente->email .
                    ' response=' . json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
                Log::info($marker . ' customer set response', [
                    'cliente_woocommerce_id' => $cliente->woocommerce_id ?? $cliente->getKey(),
                    'email' => $cliente->email,
                    'response' => $response,
                ]);

                $parsed = $this->parseTenSetCustomersResponse($response);

                $hasExceptions = !empty($parsed['exceptions']);
                $tenId = $parsed['customer_id_ten'];
                $hasValidTenId = $tenId !== null && $tenId !== '' && $tenId !== '0';

                if ($hasExceptions || !$hasValidTenId) {
                    $errors++;

                    $errMsgParts = [];
                    if ($hasExceptions) {
                        $errMsgParts[] = 'TEN Exceptions: ' . implode(' | ', array_map('strval', $parsed['exceptions']));
                    }
                    if (!$hasValidTenId) {
                        $errMsgParts[] = 'TEN no devolvió un IdTen válido (IdTen=' . ($tenId ?? 'null') . ')';
                    }
                    $errMsg = implode(' ; ', $errMsgParts);
                    if (!$errMsg) {
                        $errMsg = 'TEN devolvió error desconocido en Customers/Set';
                    }

                    $cliente->markError($errMsg);
                    foreach ($cliente->direcciones as $dir) {
                        if (!($dir instanceof Direcciones)) continue;
                        $dir->sync_status = 'error';
                        $dir->last_error = $errMsg;
                        $dir->save();
                    }

                    $ident = $cliente->woocommerce_id ?? $cliente->getKey();
                    $this->error("TEN error cliente woo_id={$ident} email={$cliente->email}: {$errMsg}");
                    $this->writeDailyEntityLog("CUSTOMER_ERROR woo_id={$ident} email={$cliente->email} message={$errMsg}");

                    Log::warning($marker . ' TEN Customers/Set returned error', [
                        'cliente_woocommerce_id' => $ident,
                        'email' => $cliente->email,
                        'payload' => ['Customers' => [$clientePayload]],
                        'response' => $response,
                        'parsed' => $parsed,
                    ]);

                    continue;
                }

                DB::transaction(function () use ($cliente, $parsed) {
                    $cliente->sync_status = 'synced';
                    $cliente->last_error = null;
                    $cliente->ten_last_fetched_at = now();

                    if ($parsed['customer_id_ten'] !== null) {
                        $cliente->ten_id = (string) $parsed['customer_id_ten'];
                    }

                    $firstDirTenId = null;
                    foreach ($parsed['direcciones'] as $d) {
                        if (($d['id_ten'] ?? null) !== null && (string)$d['id_ten'] !== '-1') {
                            $firstDirTenId = (string) $d['id_ten'];
                            break;
                        }
                    }

                    $dirsByCodigo = [];
                    foreach ($parsed['direcciones'] as $d) {
                        $codigo = (string)($d['codigo'] ?? '');
                        if ($codigo === '') continue;
                        $dirsByCodigo[$codigo] = (string)($d['id_ten'] ?? '');
                    }

                    $shippingDirTenId = null;
                    foreach ($cliente->direcciones as $dir) {
                        if (!($dir instanceof Direcciones)) continue;

                        $dir->sync_status = 'synced';
                        $dir->last_error = null;
                        $dir->ten_last_fetched_at = now();

                        $codigoDir = (string)$dir->getKey();
                        if (isset($dirsByCodigo[$codigoDir]) && $dirsByCodigo[$codigoDir] !== '' && $dirsByCodigo[$codigoDir] !== '-1' && $dirsByCodigo[$codigoDir] !== '0') {
                            $dir->ten_id_ten = $dirsByCodigo[$codigoDir];
                            if ((string) $dir->tipo === 'shipping') {
                                $shippingDirTenId = $dirsByCodigo[$codigoDir];
                            }
                        }

                        $dir->save();
                    }

                    $cliente->ten_id_direccion_envio = $shippingDirTenId ?? $firstDirTenId ?? $cliente->ten_id_direccion_envio;
                    $cliente->save();
                });

                $created++;
                $this->writeDailyEntityLog("CUSTOMER_OK woo_id={$cliente->woocommerce_id} email={$cliente->email} ten_id=" . ($cliente->ten_id ?? ''));
            } catch (Throwable $e) {
                $errors++;
                $msg = $e->getMessage();

                $cliente->markError($msg);

                $ident = $cliente->woocommerce_id ?? $cliente->getKey();
                $this->error("Error cliente woo_id={$ident} email={$cliente->email}: {$msg}");
                $this->writeDailyEntityLog("CUSTOMER_EXCEPTION woo_id={$ident} email={$cliente->email} message={$msg}");

                Log::error($marker . ' customer set failed', [
                    'cliente_woocommerce_id' => $ident,
                    'email' => $cliente->email,
                    'payload' => ['Customers' => [$clientePayload]],
                    'error' => $msg,
                ]);
            }
        }

        $this->info("Resultado: created(sent)={$created} | existed(linked)={$skippedAlreadyExists} | errors={$errors}");
        $this->writeDailyEntityLog("END created={$created} existed={$skippedAlreadyExists} errors={$errors}");
        Log::info($marker . ' done', compact('created', 'skippedAlreadyExists', 'errors'));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Mapeo DB -> payload TEN /Customers/Set
     *
     * @param Cliente $cliente
     * @return array<string,mixed>
     */
    private function mapClienteToTenPayload(Cliente $cliente, ?string $overrideEmail = null, bool $forceCreate = false): array
    {
        $dirs = [];
        $direcciones = $cliente->direcciones
            ->sortBy(fn ($dir) => match ((string) ($dir->tipo ?? '')) {
                'billing' => 0,
                'shipping' => 1,
                default => 2,
            })
            ->values();

        foreach ($direcciones as $dir) {
            if (!$dir instanceof Direcciones) continue;

            $dirPayload = $this->buildDireccionPayload($cliente, $dir);

            if (
                !$forceCreate &&
                !empty($dir->ten_id_ten) &&
                (string)$dir->ten_id_ten !== '-1' &&
                (string)$dir->ten_id_ten !== '0'
            ) {
                $dirPayload['IdTen'] = (string) $dir->ten_id_ten;
            }

            $dirs[] = $dirPayload;
        }

        if (empty($dirs)) {
            $dirs[] = [
                'Codigo' => (string)($cliente->getKey()),
                'Nombre' => (string)($cliente->nombre ?? ''),
                'Apellidos' => (string)($cliente->apellidos ?? ''),
                'Direccion' => '',
                'Direccion2' => '',
                'CodigoPostal' => '',
                'Poblacion' => '',
                'Provincia' => '',
                'Pais' => '',
                'Telefono' => (string)($cliente->telefono ?? ''),
                'Fax' => '',
                'AditionalData' => (object) [],
            ];
        }

        $principalPayload = $this->pickPrimaryDireccionPayload($dirs);

        // En creación, TEN necesita una copia de la dirección principal con IdTen=-1.
        // Seguimos el ejemplo de la API: Codigo normal del cliente y IdTen=-1.
        if ($forceCreate || empty($cliente->ten_id)) {
            $principal = $principalPayload;
            $principal['Codigo'] = (string)($cliente->ten_codigo ?? $cliente->woocommerce_id ?? $cliente->getKey());
            $principal['IdTen'] = '-1';
            array_unshift($dirs, $principal);
        }

        $payload = [
            'Codigo' => (string)($cliente->ten_codigo ?? $cliente->woocommerce_id ?? $cliente->getKey()),
            'Email' => (string)($overrideEmail ?? $cliente->email ?? ''),
            'Nombre' => (string)($cliente->nombre ?? ''),
            'Apellidos' => (string)($cliente->apellidos ?? ''),
            'NombreFiscal' => (string)($cliente->nombre_fiscal ?? ''),
            'NIF' => (string)($cliente->nif ?? ''),
            'IdDireccionEnvio' => (string)($cliente->ten_id_direccion_envio ?? '0'),
            'IdGrupoClientes' => (string)($cliente->ten_id_grupo_clientes ?? '0'),
            'RegimenImpuesto' => (string)($cliente->ten_regimen_impuesto ?? '0'),
            'Persona' => $cliente->ten_persona ? 1 : 0,
            'IdTarifa' => (int)($cliente->ten_id_tarifa ?? 0),
            'Vendedor' => (string)($cliente->ten_vendedor ?? 'WEB'),
            'FormaPago' => (string)($cliente->ten_forma_pago ?? ''),
            'Telefono' => (string)($cliente->telefono ?? ''),
            'Telefono2' => (string)($cliente->telefono2 ?? ''),
            'Web' => (string)($cliente->web ?? ''),
            'CalculoIVAFactura' => (string)($cliente->ten_calculo_iva_factura ?? ''),
            'EnviarEmails' => $cliente->ten_enviar_emails ? '1' : '0',
            'ConsentimientoDatos' => $cliente->ten_consentimiento_datos ? '1' : '0',
            'Direccion' => (string)($principalPayload['Direccion'] ?? ''),
            'Direccion2' => (string)($principalPayload['Direccion2'] ?? ''),
            'CodigoPostal' => (string)($principalPayload['CodigoPostal'] ?? ''),
            'Poblacion' => (string)($principalPayload['Poblacion'] ?? ''),
            'Provincia' => (string)($principalPayload['Provincia'] ?? ''),
            'Pais' => (string)($principalPayload['Pais'] ?? ''),
            'Fax' => (string)($principalPayload['Fax'] ?? ''),
            'AditionalData' => (object) [],
            'Direcciones' => $dirs,
        ];

        if (!empty($cliente->ten_id) && !$forceCreate) {
            $payload['IdTen'] = (string) $cliente->ten_id;
        }

        return $payload;
    }

    private function buildDireccionPayload(Cliente $cliente, Direcciones $dir): array
    {
        return [
            'Codigo' => (string)($dir->getKey()),
            'Nombre' => (string)($dir->ten_nombre ?? $dir->first_name ?? $cliente->nombre ?? ''),
            'Apellidos' => (string)($dir->ten_apellidos ?? $dir->last_name ?? $cliente->apellidos ?? ''),
            'Direccion' => (string)($dir->ten_direccion ?? $dir->address_1 ?? ''),
            'Direccion2' => (string)($dir->ten_direccion2 ?? $dir->address_2 ?? ''),
            'CodigoPostal' => (string)($dir->ten_codigo_postal ?? $dir->postcode ?? ''),
            'Poblacion' => (string)($dir->ten_poblacion ?? $dir->city ?? ''),
            'Provincia' => (string)($dir->ten_provincia ?? $dir->state ?? ''),
            'Pais' => (string)($dir->ten_pais ?? $dir->country ?? ''),
            'Telefono' => (string)($dir->ten_telefono ?? $dir->phone ?? $cliente->telefono ?? ''),
            'Fax' => (string)($dir->ten_fax ?? ''),
            'AditionalData' => (object) (is_array($dir->ten_aditional_data) ? $dir->ten_aditional_data : (array)($dir->ten_aditional_data ?? [])),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $dirs
     * @return array<string, mixed>
     */
    private function pickPrimaryDireccionPayload(array $dirs): array
    {
        foreach ($dirs as $dir) {
            if ($this->direccionPayloadHasAddressData($dir)) {
                return $dir;
            }
        }

        return $dirs[0];
    }

    /**
     * @param array<string, mixed> $dir
     */
    private function direccionPayloadHasAddressData(array $dir): bool
    {
        foreach (['Direccion', 'Direccion2', 'CodigoPostal', 'Poblacion', 'Provincia', 'Pais', 'Telefono'] as $field) {
            $value = $dir[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * TEN Customers/Set devuelve una LISTA, con 1 item por cliente enviado.
     *
     * @return array{customer_codigo:?string, customer_id_ten:?string, exceptions:array, direcciones:array<int, array{codigo:?string,id_ten:?string,exceptions:array}>}
     */
    private function parseTenSetCustomersResponse(array $response): array
    {
        $item = null;
        if (array_is_list($response) && isset($response[0]) && is_array($response[0])) {
            $item = $response[0];
        } elseif (isset($response['Customers'][0]) && is_array($response['Customers'][0])) {
            $item = $response['Customers'][0];
        }
        $item = is_array($item) ? $item : [];

        $direcciones = [];
        $respDirs = $item['Direcciones'] ?? [];
        if (is_array($respDirs)) {
            foreach ($respDirs as $d) {
                if (!is_array($d)) continue;
                $direcciones[] = [
                    'codigo' => isset($d['Codigo']) ? (string)$d['Codigo'] : null,
                    'id_ten' => isset($d['IdTen']) ? (string)$d['IdTen'] : null,
                    'exceptions' => is_array($d['Exceptions'] ?? null) ? $d['Exceptions'] : [],
                ];
            }
        }

        return [
            'customer_codigo' => isset($item['Codigo']) ? (string)$item['Codigo'] : null,
            'customer_id_ten' => isset($item['IdTen']) ? (string)$item['IdTen'] : null,
            'exceptions' => is_array($item['Exceptions'] ?? null) ? $item['Exceptions'] : [],
            'direcciones' => $direcciones,
        ];
    }

    public function __destruct()
    {
        $this->closeDailyEntityLog();
    }
}
