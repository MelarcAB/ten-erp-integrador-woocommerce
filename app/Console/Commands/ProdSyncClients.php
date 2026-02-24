<?php

namespace App\Console\Commands;

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
        $this->line($marker . ' start');
        Log::info($marker . ' start');

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

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
            $this->error($marker . ' TEN GET error: ' . $e->getMessage());
            Log::error($marker . ' ten get failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        $this->info('TEN customers recibidos: ' . count($tenCustomers));

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
                ->whereNotNull('email')
                ->where('email', '<>', '')
                ->whereIn(DB::raw('LOWER(email)'), array_keys($tenByEmail))
                ->limit(5000)
                ->get();

            foreach ($localsToLink as $cliente) {
                $email = strtolower(trim((string)$cliente->email));
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

        // 3) Envío a TEN de pendientes (y opcionalmente errores)
        $statuses = ['pending'];
        if ((bool) $this->option('retry-errors')) {
            $statuses[] = 'error';
        }

        $pendingQuery = Cliente::query()
            ->with('direcciones')
            ->whereIn('sync_status', $statuses);

        if ($emailFilter = $this->option('email')) {
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

        if ($pending->isEmpty()) {
            $this->info($marker . ' done (no pending)');
            return self::SUCCESS;
        }

        $created = 0;
        $skippedAlreadyExists = 0;
        $errors = 0;

        foreach ($pending as $cliente) {
            $email = strtolower(trim((string) $cliente->email));

            // Si ya existe en TEN por email => vincular y marcar synced
            if ($email !== '' && isset($tenByEmail[$email])) {
                if (!$dryRun) {
                    $cliente->ten_id = (string)($tenByEmail[$email]['Id'] ?? $cliente->ten_id);
                    $cliente->sync_status = 'synced';
                    $cliente->last_error = null;
                    $cliente->save();
                }
                $skippedAlreadyExists++;
                continue;
            }

            $clientePayload = $this->mapClienteToTenPayload($cliente);

            if ($dryRun) {
                $this->line('DRY RUN customer payload: ' . json_encode($clientePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $created++;
                continue;
            }

            try {
                $response = $ten->setCustomers([$clientePayload]);
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
                    if ($firstDirTenId !== null) {
                        $cliente->ten_id_direccion_envio = (string) $firstDirTenId;
                    }

                    $cliente->save();

                    $dirsByCodigo = [];
                    foreach ($parsed['direcciones'] as $d) {
                        $codigo = (string)($d['codigo'] ?? '');
                        if ($codigo === '') continue;
                        $dirsByCodigo[$codigo] = (string)($d['id_ten'] ?? '');
                    }

                    foreach ($cliente->direcciones as $dir) {
                        if (!($dir instanceof Direcciones)) continue;

                        $dir->sync_status = 'synced';
                        $dir->last_error = null;
                        $dir->ten_last_fetched_at = now();

                        $codigoDir = (string)$dir->getKey();
                        if (isset($dirsByCodigo[$codigoDir]) && $dirsByCodigo[$codigoDir] !== '' && $dirsByCodigo[$codigoDir] !== '-1' && $dirsByCodigo[$codigoDir] !== '0') {
                            $dir->ten_id_ten = $dirsByCodigo[$codigoDir];
                        }

                        $dir->save();
                    }
                });

                $created++;
            } catch (Throwable $e) {
                $errors++;
                $msg = $e->getMessage();

                $cliente->markError($msg);

                $ident = $cliente->woocommerce_id ?? $cliente->getKey();
                $this->error("Error cliente woo_id={$ident} email={$cliente->email}: {$msg}");

                Log::error($marker . ' customer set failed', [
                    'cliente_woocommerce_id' => $ident,
                    'email' => $cliente->email,
                    'payload' => ['Customers' => [$clientePayload]],
                    'error' => $msg,
                ]);
            }
        }

        $this->info("Resultado: created(sent)={$created} | existed(linked)={$skippedAlreadyExists} | errors={$errors}");
        Log::info($marker . ' done', compact('created', 'skippedAlreadyExists', 'errors'));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Mapeo DB -> payload TEN /Customers/Set
     *
     * @param Cliente $cliente
     * @return array<string,mixed>
     */
    private function mapClienteToTenPayload(Cliente $cliente): array
    {
        $dirs = [];
        foreach ($cliente->direcciones as $dir) {
            if (!$dir instanceof Direcciones) continue;

            $dirPayload = [
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

            if (!empty($dir->ten_id_ten) && (string)$dir->ten_id_ten !== '-1' && (string)$dir->ten_id_ten !== '0') {
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

        $payload = [
            'Codigo' => (string)($cliente->ten_codigo ?? $cliente->woocommerce_id ?? $cliente->getKey()),
            'Email' => (string)($cliente->email ?? ''),
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
            'AditionalData' => (object) [],
            'Direcciones' => $dirs,
        ];

        if (!empty($cliente->ten_id)) {
            $payload['IdTen'] = (string) $cliente->ten_id;
        }

        return $payload;
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
}
