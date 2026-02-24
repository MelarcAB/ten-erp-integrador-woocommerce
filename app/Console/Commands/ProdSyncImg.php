<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProdSyncImg extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:prod-sync-img
        {--products-per-page=100 : Items por página para productos Woo}
        {--products-max-pages=0 : Máximo de páginas a leer (0 = sin límite)}
        {--dry-run : No actualiza Woo ni borra en FTP}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Lee imágenes en FTP, busca producto por SKU en Woo y actualiza imagen';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $marker = '[PROD_SYNC_IMG v1]';
        $this->line($marker . ' start');

        $host = (string) env('IMG_FTP_HOST', '');
        $user = (string) env('IMG_FTP_USERNAME', '');
        $pass = (string) env('IMG_FTP_PASSWORD', '');
        $timeout = (int) env('IMG_FTP_TIMEOUT', 30);
        $port = (int) env('IMG_FTP_PORT', 21);
        $passive = filter_var(env('IMG_FTP_PASSIVE', true), FILTER_VALIDATE_BOOLEAN);
        $publicBase = (string) env('IMG_FTP_PUBLIC_URL', '');

        $productsPerPage = (int) $this->option('products-per-page');
        $productsMaxPages = (int) $this->option('products-max-pages');
        $dryRun = (bool) $this->option('dry-run');

        if ($host === '' || $user === '' || $pass === '') {
            $this->error('Faltan variables IMG_FTP_HOST / IMG_FTP_USERNAME / IMG_FTP_PASSWORD en .env');
            return self::FAILURE;
        }
        if ($publicBase === '') {
            $this->error('Falta IMG_FTP_PUBLIC_URL (base pública HTTP/HTTPS para construir la URL de imagen)');
            return self::FAILURE;
        }

        $this->info("Conectando a FTP {$host}:{$port} (timeout={$timeout}s, passive=" . ($passive ? 'true' : 'false') . ')');

        $conn = @ftp_connect($host, $port, $timeout);
        if (!$conn) {
            $this->error('No se pudo conectar al servidor FTP');
            Log::error($marker . ' ftp connect failed', ['host' => $host, 'port' => $port]);
            return self::FAILURE;
        }

        try {
            if (!@ftp_login($conn, $user, $pass)) {
                $this->error('Login FTP fallido');
                Log::error($marker . ' ftp login failed', ['host' => $host, 'user' => $user]);
                return self::FAILURE;
            }

            @ftp_pasv($conn, $passive);

            // 1) Cargar todos los productos de Woo y mapear por SKU
            $this->info('Cargando productos de WooCommerce...');
            /** @var \App\Integrations\WooCommerceClient $woo */
            $woo = app(\App\Integrations\WooCommerceClient::class);

            $skuToId = [];
            $page = 1;
            $pagesDone = 0;
            $totalProducts = 0;

            while (true) {
                if ($productsMaxPages > 0 && $pagesDone >= $productsMaxPages) {
                    $this->info("Límite de páginas de productos alcanzado: {$productsMaxPages}");
                    break;
                }

                try {
                    $products = $woo->getProductos($productsPerPage, $page, ['_fields' => 'id,sku']);
                } catch (Throwable $e) {
                    $this->error('Error Woo al listar productos: ' . $e->getMessage());
                    Log::error($marker . ' woo products list failed', ['page' => $page, 'error' => $e->getMessage()]);
                    return self::FAILURE;
                }

                if (empty($products)) break;

                foreach ($products as $p) {
                    if (!is_array($p)) continue;
                    $sku = trim((string)($p['sku'] ?? ''));
                    $id = (int)($p['id'] ?? 0);
                    if ($sku === '' || $id <= 0) continue;
                    $skuToId[$sku] = $id;
                    $totalProducts++;
                }

                $page++;
                $pagesDone++;
            }

            $this->info("Productos cargados: {$totalProducts} (SKUs únicos=" . count($skuToId) . ')');

            // 2) Listar archivos en FTP raíz
            $this->info('Leyendo contenido de la raíz FTP...');
            $list = @ftp_nlist($conn, '.');
            if (!is_array($list)) {
                $this->warn('No se pudo listar la raíz (nlist). Intentando rawlist...');
                $raw = @ftp_rawlist($conn, '.');
                if (is_array($raw)) {
                    $list = $this->parseRawListFilenames($raw);
                }
            }

            if (!is_array($list)) {
                $this->error('No se pudo listar el contenido de la raíz.');
                return self::FAILURE;
            }

            $imgExts = ['jpg', 'jpeg', 'png','gif', 'webp'];
            $processed = 0;
            $updated = 0;
            $skipped = 0;
            $deleted = 0;

            foreach ($list as $item) {
                $filename = basename((string) $item);
                if ($filename === '' || $filename === '.' || $filename === '..') continue;

                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (!in_array($ext, $imgExts, true)) continue;

                $sku = pathinfo($filename, PATHINFO_FILENAME);
                $this->line("Archivo: {$filename} | SKU: {$sku}");

                if ($sku === '' || !isset($skuToId[$sku])) {
                    $skipped++;
                    continue;
                }

                $wooId = (int) $skuToId[$sku];
                $imageUrl = rtrim($publicBase, '/') . '/' . rawurlencode($filename);

                if ($dryRun) {
                    $this->line("DRY RUN: actualizaría Woo#{$wooId} con {$imageUrl} y borraría {$filename}");
                    $processed++;
                    continue;
                }

                try {
                    if (!$this->urlExists($imageUrl)) {
                        $this->warn("URL no disponible: {$imageUrl}");
                        $skipped++;
                        continue;
                    }

                    $woo->updateProducto($wooId, [
                        'images' => [
                            ['src' => $imageUrl, 'position' => 0],
                        ],
                    ]);
                    $updated++;

                    if (@ftp_delete($conn, $item)) {
                        $deleted++;
                    } else {
                        $this->warn("No se pudo borrar {$filename} del FTP.");
                    }
                } catch (Throwable $e) {
                    $this->warn("Error actualizando Woo#{$wooId} para {$filename}: " . $e->getMessage());
                    Log::warning($marker . ' woo update failed', [
                        'woocommerce_id' => $wooId,
                        'sku' => $sku,
                        'filename' => $filename,
                        'error' => $e->getMessage(),
                    ]);
                }

                $processed++;
            }

            $this->info("OK: procesados={$processed} | updated={$updated} | skipped={$skipped} | deleted={$deleted}");
            return self::SUCCESS;
        } finally {
            @ftp_close($conn);
        }
    }

    /**
     * @param array<int,string> $raw
     * @return array<int,string>
     */
    private function parseRawListFilenames(array $raw): array
    {
        $out = [];
        foreach ($raw as $line) {
            if (!is_string($line) || trim($line) === '') continue;
            $parts = preg_split('/\s+/', $line);
            if (!$parts) continue;
            $out[] = (string) end($parts);
        }
        return $out;
    }

    private function urlExists(string $url): bool
    {
        try {
            $resp = Http::timeout(10)->head($url);
            return $resp->successful();
        } catch (Throwable $e) {
            Log::warning('[PROD_SYNC_IMG v1] url check failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
