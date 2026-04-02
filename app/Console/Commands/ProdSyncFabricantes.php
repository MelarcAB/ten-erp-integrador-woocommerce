<?php

namespace App\Console\Commands;

use App\Integrations\TenClient;
use App\Integrations\WooCommerceClient;
use App\Models\Fabricante;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProdSyncFabricantes extends Command
{
    protected $signature = 'app:prod-sync-fabricantes
        {--no_create : No crear marcas nuevas en WooCommerce}
    ';

    protected $description = 'Sincroniza fabricantes: TEN -> BD local -> marcas de WooCommerce.';

    public function handle(): int
    {
        $marker = '[PROD_SYNC_FABRICANTES v1]';
        $this->line($marker . ' start');
        Log::info($marker . ' start');

        $noCreate = (bool) $this->option('no_create');
        $now = now();

        /** @var TenClient $tenClient */
        $tenClient = app(TenClient::class);

        try {
            $this->info('Llamando a TEN /Manufacturers/Get ...');
            $tenManufacturers = $tenClient->getManufacturers();
        } catch (Throwable $e) {
            $this->error('Error TEN: ' . $e->getMessage());
            Log::error($marker . ' TEN call failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        $totalFetched = count($tenManufacturers);
        $this->info("Recibidos TEN: {$totalFetched}");
        if ($totalFetched === 0) {
            $this->info('No hay fabricantes en TEN.');
            return self::SUCCESS;
        }

        $rows = [];
        $invalidRows = 0;
        foreach ($tenManufacturers as $row) {
            if (!is_array($row)) {
                $invalidRows++;
                continue;
            }

            $tenId = (int) ($row['Id'] ?? $row['IdNumero'] ?? 0);
            $name = trim((string) ($row['Nombre'] ?? $row['Name'] ?? ''));
            if ($tenId <= 0 || $name === '') {
                $invalidRows++;
                continue;
            }

            $attrs = [
                'ten_id_numero' => $tenId,
                'ten_nombre' => $name,
            ];

            $rows[] = [
                ...$attrs,
                'ten_hash' => $this->hashFromAttributes($attrs),
                'sync_status' => 'pending',
                'last_error' => null,
                'ten_last_fetched_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $rows = collect($rows)->keyBy('ten_id_numero')->values()->all();
        $tenIds = array_map(static fn($r) => (int) $r['ten_id_numero'], $rows);

        $existing = [];
        foreach (array_chunk($tenIds, 1000) as $chunk) {
            $dbRows = Fabricante::query()
                ->whereIn('ten_id_numero', $chunk)
                ->get(['ten_id_numero', 'ten_hash', 'sync_status'])
                ->all();
            foreach ($dbRows as $dbRow) {
                $existing[(int) $dbRow->ten_id_numero] = [
                    'ten_hash' => (string) ($dbRow->ten_hash ?? ''),
                    'sync_status' => (string) ($dbRow->sync_status ?? 'pending'),
                ];
            }
        }

        $toUpsert = [];
        $insertCount = 0;
        $updateCount = 0;
        $skipCount = 0;
        $requeuedCount = 0;

        foreach ($rows as $row) {
            $tenId = (int) $row['ten_id_numero'];
            $newHash = (string) $row['ten_hash'];
            if (!isset($existing[$tenId])) {
                $toUpsert[] = $row;
                $insertCount++;
                continue;
            }

            $oldHash = (string) $existing[$tenId]['ten_hash'];
            $oldStatus = (string) $existing[$tenId]['sync_status'];
            if ($oldHash === $newHash) {
                $skipCount++;
                continue;
            }

            $row['sync_status'] = 'pending';
            $toUpsert[] = $row;
            $updateCount++;
            if ($oldStatus === 'synced') {
                $requeuedCount++;
            }
        }

        if (!empty($toUpsert)) {
            Fabricante::query()->upsert(
                $toUpsert,
                ['ten_id_numero'],
                ['ten_nombre', 'ten_hash', 'sync_status', 'last_error', 'ten_last_fetched_at', 'updated_at']
            );
        }

        $this->info(
            "Import BD: insert={$insertCount} | update={$updateCount} | skip={$skipCount}"
            . " | requeued={$requeuedCount} | invalid={$invalidRows}"
        );
        Log::info($marker . ' import diff', compact(
            'insertCount',
            'updateCount',
            'skipCount',
            'requeuedCount',
            'invalidRows'
        ));

        $pendingQuery = Fabricante::query()
            ->where('sync_status', 'pending')
            ->orderBy('id');
        $totalPending = (clone $pendingQuery)->count();
        $this->info("Pendientes de sync Woo: {$totalPending}");
        if ($totalPending === 0) {
            return self::SUCCESS;
        }

        /** @var WooCommerceClient $wooClient */
        $wooClient = app(WooCommerceClient::class);

        try {
            $wooBrandsByNormalizedName = $this->loadWooBrandsByNormalizedName($wooClient);
        } catch (Throwable $e) {
            $this->error('Error consultando marcas en WooCommerce: ' . $e->getMessage());
            Log::error($marker . ' woo brands load failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        $linked = 0;
        $created = 0;
        $errors = 0;
        $processed = 0;

        $pendingQuery->chunkById(200, function ($fabricantes) use (
            $wooClient,
            &$wooBrandsByNormalizedName,
            $noCreate,
            &$linked,
            &$created,
            &$errors,
            &$processed,
            $totalPending
        ) {
            foreach ($fabricantes as $fabricante) {
                /** @var Fabricante $fabricante */
                $processed++;
                $progress = $this->syncProgress($processed, $totalPending);
                $name = trim((string) $fabricante->ten_nombre);
                $normalizedName = $this->normalizeName($name);
                if ($normalizedName === '') {
                    $fabricante->markError('Fabricante sin nombre válido');
                    $errors++;
                    $this->warn("[TEN#{$fabricante->ten_id_numero}] ERROR: nombre vacío{$progress}");
                    continue;
                }

                try {
                    if (isset($wooBrandsByNormalizedName[$normalizedName])) {
                        $wooId = (int) ($wooBrandsByNormalizedName[$normalizedName]['id'] ?? 0);
                        $wooName = (string) ($wooBrandsByNormalizedName[$normalizedName]['name'] ?? $name);
                        $fabricante->woocommerce_marca_id = $wooId > 0 ? $wooId : null;
                        $fabricante->woocommerce_marca_nombre = $wooName;
                        $fabricante->markSynced();
                        $linked++;
                        $this->line("[TEN#{$fabricante->ten_id_numero}] LINK WooBrand#{$wooId} nombre=\"{$wooName}\"{$progress}");
                        continue;
                    }

                    if ($noCreate) {
                        $fabricante->markError('Marca no encontrada en Woo (no_create activo)');
                        $errors++;
                        $this->warn("[TEN#{$fabricante->ten_id_numero}] ERROR: marca no existe en Woo (no_create){$progress}");
                        continue;
                    }

                    $resp = $wooClient->createMarcaProducto(['name' => $name]);
                    $wooId = (int) ($resp['id'] ?? 0);
                    $wooName = trim((string) ($resp['name'] ?? $name));
                    if ($wooId <= 0 || $wooName === '') {
                        throw new \RuntimeException('Respuesta Woo sin id/nombre al crear marca');
                    }

                    $fabricante->woocommerce_marca_id = $wooId;
                    $fabricante->woocommerce_marca_nombre = $wooName;
                    $fabricante->markSynced();
                    $wooBrandsByNormalizedName[$normalizedName] = [
                        'id' => $wooId,
                        'name' => $wooName,
                    ];
                    $created++;
                    $this->line("[TEN#{$fabricante->ten_id_numero}] CREATE WooBrand#{$wooId} nombre=\"{$wooName}\"{$progress}");
                } catch (Throwable $e) {
                    $errors++;
                    $fabricante->markError($e->getMessage());
                    $this->warn("[TEN#{$fabricante->ten_id_numero}] ERROR: {$e->getMessage()}{$progress}");
                }
            }
        });

        $this->info("OK fin. linked={$linked} | created={$created} | errors={$errors}");
        Log::info($marker . ' done', compact('linked', 'created', 'errors'));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, array{id:int,name:string}>
     */
    private function loadWooBrandsByNormalizedName(WooCommerceClient $client): array
    {
        $map = [];
        $page = 1;
        $perPage = 100;

        while (true) {
            $brands = $client->getMarcasProductos($perPage, $page);
            if (empty($brands)) {
                break;
            }

            foreach ($brands as $brand) {
                if (!is_array($brand)) {
                    continue;
                }
                $id = (int) ($brand['id'] ?? 0);
                $name = trim((string) ($brand['name'] ?? ''));
                if ($id <= 0 || $name === '') {
                    continue;
                }
                $key = $this->normalizeName($name);
                if ($key === '') {
                    continue;
                }
                if (!isset($map[$key])) {
                    $map[$key] = [
                        'id' => $id,
                        'name' => $name,
                    ];
                }
            }

            $page++;
        }

        return $map;
    }

    /**
     * @param array<string,mixed> $attrs
     */
    private function hashFromAttributes(array $attrs): string
    {
        ksort($attrs);
        return hash('sha256', json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        return mb_strtolower($name);
    }

    private function syncProgress(int $processed, int $total): string
    {
        if ($total <= 0) {
            return '';
        }

        $percent = ($processed / $total) * 100;
        return sprintf(' | %d/%d (%.2f%%)', $processed, $total, $percent);
    }
}

