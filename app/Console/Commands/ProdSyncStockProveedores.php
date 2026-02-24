<?php

namespace App\Console\Commands;

use App\Integrations\WooCommerceClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProdSyncStockProveedores extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:prod-sync-stock-proveedores
        {--dry-run : No actualiza Woo}
        {--limit=0 : Límite de filas a procesar (0 = sin límite)}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Descarga CSV de proveedores, actualiza stock/precio en Woo por SKU.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $marker = '[STOCK_PROVEEDORES v1]';
        $this->line($marker . ' start');

        $url = 'https://tests.takeoffcomunicacion.es/stock_proveedor.csv';
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $tmp = tempnam(sys_get_temp_dir(), 'stock_prov_');
        if ($tmp === false) {
            $this->error('No se pudo crear archivo temporal.');
            return self::FAILURE;
        }

        try {
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

            $this->line('Headers: ' . implode(' | ', $header));

            $map = $this->mapHeaders($header);
            if (!isset($map['MODELO'], $map['STOCK'], $map['PVPR'])) {
                $this->error('Faltan columnas obligatorias: MODELO, STOCK, PVPR.');
                return self::FAILURE;
            }

            /** @var WooCommerceClient $woo */
            $woo = app(WooCommerceClient::class);

            $this->info('Cargando productos de WooCommerce...');
            $skuToId = [];
            $page = 1;
            $perPage = 100;
            while (true) {
                $products = $woo->getProductos($perPage, $page, ['_fields' => 'id,sku']);
                if (empty($products)) break;
                foreach ($products as $p) {
                    if (!is_array($p)) continue;
                    $sku = trim((string)($p['sku'] ?? ''));
                    $id = (int)($p['id'] ?? 0);
                    if ($sku === '' || $id <= 0) continue;
                    $skuToId[$sku] = $id;
                }
                $page++;
            }
            $this->info('Productos cargados: ' . count($skuToId));

            $this->info('Procesando filas...');
            $handle = fopen($tmp, 'r');
            if ($handle === false) {
                $this->error('No se pudo reabrir el CSV.');
                return self::FAILURE;
            }
            // skip header
            fgetcsv($handle, 0, ';');

            $processed = 0;
            $updated = 0;
            $skipped = 0;
            $errors = 0;
            $updatedSkus = [];

            while (($row = fgetcsv($handle, 0, ';')) !== false) {
                if ($limit > 0 && $processed >= $limit) break;

                $sku = $this->getCol($row, $map['MODELO']);
                if ($sku === '') {
                    $skipped++;
                    $processed++;
                    continue;
                }

                $stockRaw = $this->getCol($row, $map['STOCK']);
                $pvprRaw = $this->getCol($row, $map['PVPR']);

                $stock = $this->toInt($stockRaw);
                $pvpr = $this->toDecimal($pvprRaw);

                try {
                    if (!isset($skuToId[$sku])) {
                        $this->line("NO ENCONTRADO: SKU={$sku} -> Producto no encontrado en WooCommerce");
                        $skipped++;
                        $processed++;
                        continue;
                    }

                    $wooId = (int) $skuToId[$sku];

                    if ($dryRun) {
                        $this->line("DRY RUN: SKU={$sku} Woo#{$wooId} stock={$stock} pvpr={$pvpr}");
                        $updated++;
                        $updatedSkus[] = $sku;
                        $processed++;
                        continue;
                    }

                    $woo->updateProducto($wooId, [
                        'manage_stock' => true,
                        'stock_quantity' => $stock,
                        'regular_price' => $pvpr,
                    ]);

                    $this->line("UPDATED: SKU={$sku} Woo#{$wooId} stock={$stock} pvpr={$pvpr}");
                    $updated++;
                    $updatedSkus[] = $sku;
                } catch (Throwable $e) {
                    $errors++;
                    $this->warn("Error SKU={$sku}: " . $e->getMessage());
                    Log::warning($marker . ' update failed', [
                        'sku' => $sku,
                        'error' => $e->getMessage(),
                    ]);
                }

                $processed++;
            }

            fclose($handle);

            $this->info("OK: procesadas={$processed} | updated={$updated} | skipped={$skipped} | errors={$errors}");
            if (!empty($updatedSkus)) {
                $this->line('SKUs actualizados: ' . implode(', ', $updatedSkus));
            } else {
                $this->line('SKUs actualizados: ninguno');
            }
            return self::SUCCESS;
        } finally {
            @unlink($tmp);
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
        $val = str_replace(['.', ','], ['', '.'], $val);
        return (int) round((float) $val);
    }

    private function toDecimal(string $val): string
    {
        $val = trim($val);
        if ($val === '') return '0';
        $val = str_replace(['.', ','], ['', '.'], $val);
        return (string) $val;
    }
}
