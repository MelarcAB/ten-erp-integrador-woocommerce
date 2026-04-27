<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesDailyEntityLog;
use App\Integrations\WooCommerceClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProdSyncStockProveedores extends Command
{
    use WritesDailyEntityLog;
    protected $signature = 'app:prod-sync-stock-proveedores
        {--dry-run : No actualiza Woo}
        {--limit=0 : Límite de filas a procesar (0 = sin límite)}
        {--batch-size=100 : Tamaño del batch para /products/batch (recomendado 50-200)}
    ';

    protected $description = 'Comando legado. Delegado al flujo unificado app:prod-sync-stocks.';

    private $providerLogHandle = null;

    public function handle(): int
    {
        $marker = '[STOCK_PROVEEDORES v3]';
        $this->initDailyEntityLog('stocks');
        $this->writeDailyEntityLog($marker . ' start');
        $this->line($marker . ' start');

        $dryRun = (bool) $this->option('dry-run');
        $this->warn('Comando legado: usando app:prod-sync-stocks como fuente única de verdad para stock y reservas.');
        $this->writeDailyEntityLog($marker . ' delegated dry_run=' . ($dryRun ? '1' : '0'));
        Log::warning($marker . ' deprecated command delegated', ['dry_run' => $dryRun]);

        return $this->call('app:prod-sync-stocks', array_filter([
            '--dry-run' => $dryRun ? true : null,
        ], static fn ($value) => $value !== null));

        $tmp = tempnam(sys_get_temp_dir(), 'stock_prov_');
        if ($tmp === false) {
            $this->error('No se pudo crear archivo temporal.');
            return self::FAILURE;
        }

        try {
            $this->providerLogHandle = $this->openProviderLog();
            $this->writeProviderLog('START sync');

            // 1) Descargar CSV
            $this->info('Descargando CSV...');
            $response = Http::timeout(60)->get($url);
            if (!$response->successful()) {
                $this->error('Error al descargar CSV. HTTP ' . $response->status());
                Log::warning($marker . ' download failed', ['status' => $response->status(), 'body' => $response->body()]);
                return self::FAILURE;
            }

            if (file_put_contents($tmp, $response->body()) === false) {
                $this->error('No se pudo escribir el CSV en disco.');
                return self::FAILURE;
            }

            // 2) Leer headers
            $this->info('Leyendo headers...');
            $handle = fopen($tmp, 'r');
            if ($handle === false) {
                $this->error('No se pudo abrir el CSV.');
                return self::FAILURE;
            }

            $header = fgetcsv($handle, 0, ';');
            fclose($handle);

            if (!is_array($header)) {
                $this->error('No se pudieron leer los headers.');
                return self::FAILURE;
            }

            $map = $this->mapHeaders($header);
            if (!isset($map['MODELO'], $map['STOCK'], $map['PVPR'])) {
                $this->error('Faltan columnas obligatorias: MODELO, STOCK, PVPR.');
                return self::FAILURE;
            }
            $hasEan = isset($map['EAN']);

            /** @var WooCommerceClient $woo */
            $woo = app(WooCommerceClient::class);

            // 3) Cargar productos de Woo (sku + global_unique_id)
            $this->info('Cargando productos de WooCommerce...');
            $skuToId = [];
            $eanToId = [];

            $page = 1;
            $perPage = 100;

            while (true) {
                try {
                    $products = $woo->getProductos($perPage, $page, [
                        '_fields' => 'id,sku,global_unique_id',
                    ]);
                } catch (Throwable $e) {
                    $this->error('Error Woo al listar productos: ' . $e->getMessage());
                    Log::error($marker . ' woo list failed', ['page' => $page, 'error' => $e->getMessage()]);
                    return self::FAILURE;
                }

                if (empty($products)) break;

                foreach ($products as $p) {
                    if (!is_array($p)) continue;
                    $id = (int)($p['id'] ?? 0);
                    if ($id <= 0) continue;

                    $sku = trim((string)($p['sku'] ?? ''));
                    if ($sku !== '') $skuToId[$sku] = $id;

                    if ($hasEan) {
                        $ean = trim((string)($p['global_unique_id'] ?? ''));
                        if ($ean !== '') $eanToId[$ean] = $id;
                    }
                }

                $page++;
            }

            $this->info('Productos cargados (SKU): ' . count($skuToId));
            if ($hasEan) $this->info('EANs cargados (global_unique_id): ' . count($eanToId));

            // 4) Procesar CSV y construir updates deduplicados
            $this->info('Procesando filas (dedup + batch)...');

            $handle = fopen($tmp, 'r');
            if ($handle === false) {
                $this->error('No se pudo reabrir el CSV.');
                return self::FAILURE;
            }

            // skip header
            fgetcsv($handle, 0, ';');

            $processed = 0;
            $skipped = 0;
            $errors = 0;

            // Dedup: key => payload (último gana)
            $updatesByKey = [];

            while (($row = fgetcsv($handle, 0, ';')) !== false) {
                if ($limit > 0 && $processed >= $limit) break;

                $sku = $this->getCol($row, $map['MODELO']);
                $ean = $hasEan ? $this->getCol($row, $map['EAN']) : '';

                $wooId = null;
                $foundBy = null;

                if ($sku !== '' && isset($skuToId[$sku])) {
                    $wooId = (int)$skuToId[$sku];
                    $foundBy = 'SKU';
                } elseif ($ean !== '' && isset($eanToId[$ean])) {
                    $wooId = (int)$eanToId[$ean];
                    $foundBy = 'EAN';
                }

                if (!$wooId) {
                    $skipped++;
                    $processed++;
                    continue;
                }

                $stock = $this->toInt($this->getCol($row, $map['STOCK']));
                $pvpr  = $this->toDecimalString($this->getCol($row, $map['PVPR']));

                // Key para dedup (prefiere SKU si existe)
                $key = $sku !== '' ? "sku:$sku" : "ean:$ean";

                $updatesByKey[$key] = [
                    'id' => $wooId,
                    'manage_stock' => true,
                    'stock_quantity' => $stock,
                    'regular_price' => $pvpr,
                    '_debug_found_by' => $foundBy,
                    '_debug_sku' => $sku,
                    '_debug_ean' => $ean,
                ];

                $processed++;
            }

            fclose($handle);

            $totalToUpdate = count($updatesByKey);
            $this->info("Filas leídas={$processed} | dedup updates={$totalToUpdate} | skipped={$skipped}");

            if ($dryRun) {
                $this->warn('DRY RUN activo: no se enviarán updates a Woo.');
                // muestra unas pocas
                $i = 0;
                foreach ($updatesByKey as $k => $u) {
                    $this->line("DRY: {$u['_debug_found_by']} sku={$u['_debug_sku']} ean={$u['_debug_ean']} id={$u['id']} stock={$u['stock_quantity']} price={$u['regular_price']}");
                    if (++$i >= 20) break;
                }
                $this->writeProviderLog("END sync (dry-run) processed={$processed} updates={$totalToUpdate} skipped={$skipped} errors={$errors}");
                return self::SUCCESS;
            }

            // 5) Enviar en batches
            $this->info("Enviando batches a Woo (batch-size={$batchSize})...");
            $updated = 0;

            $batch = [];
            $batchDebug = [];

            foreach ($updatesByKey as $k => $u) {
                $batch[] = [
                    'id' => $u['id'],
                    'manage_stock' => true,
                    'stock_quantity' => $u['stock_quantity'],
                    'regular_price' => $u['regular_price'],
                ];
                $batchDebug[] = $u;

                if (count($batch) >= $batchSize) {
                    [$ok, $fail] = $this->flushBatch($woo, $batch, $batchDebug, $marker);
                    $updated += $ok;
                    $errors += $fail;
                    $batch = [];
                    $batchDebug = [];
                }
            }

            if (!empty($batch)) {
                [$ok, $fail] = $this->flushBatch($woo, $batch, $batchDebug, $marker);
                $updated += $ok;
                $errors += $fail;
            }

            $this->info("OK: updates={$updated} | skipped={$skipped} | errors={$errors}");
            $this->writeProviderLog("END sync processed={$processed} updated={$updated} skipped={$skipped} errors={$errors}");
            return $errors > 0 ? self::FAILURE : self::SUCCESS;

        } finally {
            if (is_resource($this->providerLogHandle)) {
                fclose($this->providerLogHandle);
                $this->providerLogHandle = null;
            }
            @unlink($tmp);
        }
    }

    public function __destruct()
    {
        $this->closeDailyEntityLog();
    }

    /**
     * @param array<int,array<string,mixed>> $batch
     * @param array<int,array<string,mixed>> $batchDebug
     * @return array{0:int,1:int} ok, fail
     */
    private function flushBatch(WooCommerceClient $woo, array $batch, array $batchDebug, string $marker): array
    {
        try {
            // Evitamos parsear cuerpos JSON grandes de Woo para no disparar memoria.
            $res = $woo->updateProductosBatch($batch, false);

            // Woo devuelve 'update' con objetos (algunos con 'error')
            $ok = 0;
            $fail = 0;

            $items = is_array($res['update'] ?? null) ? $res['update'] : null;
            if (!$items) {
                foreach ($batchDebug as $dbg) {
                    $this->writeProviderLog(
                        "UPDATED sku={$dbg['_debug_sku']} ean={$dbg['_debug_ean']} woo_id={$dbg['id']} stock={$dbg['stock_quantity']} pvpr={$dbg['regular_price']}"
                    );
                }
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
                return [count($batch), 0];
            }

            foreach ($items as $idx => $item) {
                $dbg = $batchDebug[$idx] ?? null;

                if (is_array($item) && isset($item['error'])) {
                    $fail++;
                    Log::warning($marker . ' batch item failed', [
                        'id' => $dbg['id'] ?? null,
                        'sku' => $dbg['_debug_sku'] ?? null,
                        'ean' => $dbg['_debug_ean'] ?? null,
                        'error' => $item['error'],
                    ]);
                } else {
                    $ok++;
                    if (is_array($dbg)) {
                        $this->writeProviderLog(
                            "UPDATED sku={$dbg['_debug_sku']} ean={$dbg['_debug_ean']} woo_id={$dbg['id']} stock={$dbg['stock_quantity']} pvpr={$dbg['regular_price']}"
                        );
                    }
                }
            }

            $this->line("Batch enviado: ok={$ok} fail={$fail}");
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            return [$ok, $fail];

        } catch (Throwable $e) {
            Log::warning($marker . ' batch request failed', [
                'error' => $e->getMessage(),
                'count' => count($batch),
            ]);
            $this->warn('Batch falló completo: ' . $e->getMessage());
            return [0, count($batch)];
        }
    }

    /**
     * @param array<int,string> $header
     * @return array<string,int>
     */
    private function mapHeaders(array $header): array
    {
        $map = [];
        foreach ($header as $i => $h) {
            $key = strtoupper(trim((string) $h));
            if ($key !== '') $map[$key] = (int) $i;
        }
        return $map;
    }

    private function getCol(array $row, int $idx): string
    {
        $val = $row[$idx] ?? '';
        return trim((string) $val);
    }

    private function toInt(string $val): int
    {
        $val = trim($val);
        if ($val === '') return 0;

        // Normaliza: "1.234,56" -> "1234.56" ; "1234.56" -> "1234.56" ; "1234" -> "1234"
        $norm = $this->normalizeNumberString($val);
        return (int) round((float) $norm);
    }

    /**
     * Devuelve string decimal con punto, apto para Woo regular_price
     */
    private function toDecimalString(string $val): string
    {
        $val = trim($val);
        if ($val === '') return '0';

        $norm = $this->normalizeNumberString($val);
        // Woo espera string, mejor formatear sin notación científica
        $f = (float)$norm;
        // Evita "12" -> "12.000000" innecesario:
        if (abs($f - round($f)) < 0.0000001) return (string)(int)round($f);

        // recorta a 6 decimales y trim zeros
        $s = number_format($f, 6, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');
        return $s === '' ? '0' : $s;
    }

    /**
     * Normaliza números con coma/punto:
     * - "1.234,56" => "1234.56"
     * - "1,234.56" => "1234.56"
     * - "1234,56"  => "1234.56"
     * - "1234.56"  => "1234.56"
     * - "1234"     => "1234"
     */
    private function normalizeNumberString(string $raw): string
    {
        $s = trim($raw);
        $s = str_replace(["\xC2\xA0", ' '], '', $s); // non-breaking space y espacios

        $hasComma = str_contains($s, ',');
        $hasDot = str_contains($s, '.');

        if ($hasComma && $hasDot) {
            // decide cuál es decimal mirando la última aparición
            $lastComma = strrpos($s, ',');
            $lastDot = strrpos($s, '.');

            if ($lastComma !== false && $lastDot !== false && $lastComma > $lastDot) {
                // coma decimal: quita puntos miles, cambia coma por punto
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                // punto decimal: quita comas miles
                $s = str_replace(',', '', $s);
            }
            return $s;
        }

        if ($hasComma) {
            // asume coma decimal
            $s = str_replace('.', '', $s); // por si vienen miles con punto
            $s = str_replace(',', '.', $s);
            return $s;
        }

        // solo punto o nada: asume punto decimal o entero
        // por si viniera miles con coma ya lo quitamos arriba; aquí solo limpiamos comillas raras
        return $s;
    }

    private function openProviderLog()
    {
        $dir = storage_path('updates-from-provider');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $filename = 'SYNC-' . now()->format('d-m-Y H:i') . '.log';
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        return @fopen($path, 'a');
    }

    private function writeProviderLog(string $line): void
    {
        if (!is_resource($this->providerLogHandle)) return;
        $ts = now()->format('Y-m-d H:i:s');
        @fwrite($this->providerLogHandle, '[' . $ts . '] ' . $line . PHP_EOL);
    }
}
