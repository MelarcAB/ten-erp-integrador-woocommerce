<?php

namespace App\Console\Commands;

use App\Integrations\WooCommerceClient;
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
    protected $description = 'Lee imágenes en FTP, mapea por SKU y sincroniza imagen principal/secundarias en Woo';

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

            $imgExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $processedFiles = 0;
            $skippedFiles = 0;

            /** @var array<string, array{woo_id:int,primary:?string,secondaries:array<int,array{filename:string,suffix:string}>}> $skuImages */
            $skuImages = [];

            foreach ($list as $item) {
                $filename = basename((string) $item);
                if ($filename === '' || $filename === '.' || $filename === '..') {
                    continue;
                }

                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (!in_array($ext, $imgExts, true)) {
                    continue;
                }

                $skuPart = pathinfo($filename, PATHINFO_FILENAME);
                $mapping = $this->mapFilenameToSku($skuPart, $skuToId);
                if ($mapping === null) {
                    $skippedFiles++;
                    continue;
                }

                $sku = $mapping['sku'];
                $wooId = (int) $mapping['woo_id'];
                $type = $mapping['type'];
                $suffix = (string) ($mapping['suffix'] ?? '');

                if (!isset($skuImages[$sku])) {
                    $skuImages[$sku] = [
                        'woo_id' => $wooId,
                        'primary' => null,
                        'secondaries' => [],
                    ];
                }

                if ($type === 'primary') {
                    $currentPrimary = $skuImages[$sku]['primary'];
                    if ($currentPrimary === null || $this->isBetterPrimaryImage($filename, $currentPrimary)) {
                        $skuImages[$sku]['primary'] = $filename;
                    }
                } else {
                    $skuImages[$sku]['secondaries'][] = [
                        'filename' => $filename,
                        'suffix' => $suffix,
                    ];
                }

                $processedFiles++;
            }

            $processedProducts = 0;
            $updatedProducts = 0;
            $skippedProducts = 0;
            $errors = 0;
            $deleted = 0;

            foreach ($skuImages as $sku => $group) {
                $processedProducts++;
                $wooId = (int) $group['woo_id'];
                $primaryFilename = $group['primary'];
                $secondaries = $group['secondaries'];
                usort($secondaries, fn ($a, $b) => $this->compareSecondarySuffix($a['suffix'], $b['suffix']));

                $existingPrimary = null;
                if ($primaryFilename === null && !empty($secondaries)) {
                    $existingPrimary = $this->fetchExistingPrimaryImage($woo, $wooId);
                }

                $imagesPayload = [];
                $filesToDelete = [];
                $nextPos = 0;

                if ($primaryFilename !== null) {
                    $primaryUrl = $this->buildPublicImageUrl($publicBase, $primaryFilename);
                    if ($this->urlExists($primaryUrl)) {
                        $imagesPayload[] = ['src' => $primaryUrl, 'position' => $nextPos];
                        $filesToDelete[] = $primaryFilename;
                        $nextPos++;
                    } else {
                        $this->warn("URL no disponible (principal): {$primaryUrl}");
                    }
                } elseif ($existingPrimary !== null) {
                    $existingId = (int) ($existingPrimary['id'] ?? 0);
                    $existingSrc = trim((string) ($existingPrimary['src'] ?? ''));
                    if ($existingId > 0) {
                        $imagesPayload[] = ['id' => $existingId, 'position' => $nextPos];
                        $nextPos++;
                    } elseif ($existingSrc !== '') {
                        $imagesPayload[] = ['src' => $existingSrc, 'position' => $nextPos];
                        $nextPos++;
                    }
                }

                foreach ($secondaries as $sec) {
                    $secondaryFilename = $sec['filename'];
                    $secondaryUrl = $this->buildPublicImageUrl($publicBase, $secondaryFilename);
                    if (!$this->urlExists($secondaryUrl)) {
                        $this->warn("URL no disponible (secundaria): {$secondaryUrl}");
                        continue;
                    }
                    $imagesPayload[] = ['src' => $secondaryUrl, 'position' => $nextPos];
                    $filesToDelete[] = $secondaryFilename;
                    $nextPos++;
                }

                if (empty($imagesPayload)) {
                    $skippedProducts++;
                    continue;
                }

                if ($dryRun) {
                    $this->line("DRY RUN: Woo#{$wooId} sku={$sku} -> images=" . count($imagesPayload) . ' | files=' . implode(', ', $filesToDelete));
                    continue;
                }

                try {
                    $woo->updateProducto($wooId, ['images' => $imagesPayload]);
                    $updatedProducts++;

                    foreach (array_values(array_unique($filesToDelete)) as $filename) {
                        if (@ftp_delete($conn, $filename)) {
                            $deleted++;
                        } else {
                            $this->warn("No se pudo borrar {$filename} del FTP.");
                        }
                    }
                } catch (Throwable $e) {
                    $errors++;
                    $this->warn("Error actualizando Woo#{$wooId} sku={$sku}: " . $e->getMessage());
                    Log::warning($marker . ' woo update failed', [
                        'woocommerce_id' => $wooId,
                        'sku' => $sku,
                        'primary' => $primaryFilename,
                        'secondary_count' => count($secondaries),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->info(
                "OK: files_processed={$processedFiles} | files_skipped={$skippedFiles}"
                . " | products_processed={$processedProducts} | products_updated={$updatedProducts}"
                . " | products_skipped={$skippedProducts} | errors={$errors} | deleted={$deleted}"
            );
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

    /**
     * @param array<string,int> $skuToId
     * @return array{sku:string,woo_id:int,type:string,suffix:?string}|null
     */
    private function mapFilenameToSku(string $filenameNoExt, array $skuToId): ?array
    {
        $filenameNoExt = trim($filenameNoExt);
        if ($filenameNoExt === '') {
            return null;
        }

        // SKU exacto => imagen principal
        if (isset($skuToId[$filenameNoExt])) {
            return [
                'sku' => $filenameNoExt,
                'woo_id' => (int) $skuToId[$filenameNoExt],
                'type' => 'primary',
                'suffix' => null,
            ];
        }

        // SKU_secundaria => por ejemplo ASDF_1, ASDF_2...
        $pos = strrpos($filenameNoExt, '_');
        if ($pos === false || $pos <= 0 || $pos >= strlen($filenameNoExt) - 1) {
            return null;
        }

        $baseSku = substr($filenameNoExt, 0, $pos);
        $suffix = substr($filenameNoExt, $pos + 1);
        if ($baseSku === '' || $suffix === '' || !isset($skuToId[$baseSku])) {
            return null;
        }

        return [
            'sku' => $baseSku,
            'woo_id' => (int) $skuToId[$baseSku],
            'type' => 'secondary',
            'suffix' => $suffix,
        ];
    }

    private function isBetterPrimaryImage(string $candidate, string $current): bool
    {
        $priority = static function (string $filename): int {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            return match ($ext) {
                'jpg' => 1,
                'jpeg' => 2,
                'png' => 3,
                'webp' => 4,
                'gif' => 5,
                default => 100,
            };
        };

        $candPrio = $priority($candidate);
        $currPrio = $priority($current);

        if ($candPrio !== $currPrio) {
            return $candPrio < $currPrio;
        }

        return strcmp($candidate, $current) < 0;
    }

    private function compareSecondarySuffix(string $a, string $b): int
    {
        $a = trim($a);
        $b = trim($b);

        $aNumeric = ctype_digit($a);
        $bNumeric = ctype_digit($b);
        if ($aNumeric && $bNumeric) {
            $intCmp = (int) $a <=> (int) $b;
            if ($intCmp !== 0) {
                return $intCmp;
            }
        } elseif ($aNumeric !== $bNumeric) {
            return $aNumeric ? -1 : 1;
        }

        return strnatcasecmp($a, $b);
    }

    private function buildPublicImageUrl(string $publicBase, string $filename): string
    {
        return rtrim($publicBase, '/') . '/' . rawurlencode($filename);
    }

    /**
     * @return array{id?:int,src?:string}|null
     */
    private function fetchExistingPrimaryImage(WooCommerceClient $woo, int $wooId): ?array
    {
        if ($wooId <= 0) {
            return null;
        }

        try {
            $product = $woo->getProductoById($wooId);
        } catch (Throwable $e) {
            Log::warning('[PROD_SYNC_IMG v1] no se pudo leer producto Woo para preservar principal', [
                'woocommerce_id' => $wooId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $images = $product['images'] ?? null;
        if (!is_array($images) || empty($images)) {
            return null;
        }

        $first = $images[0] ?? null;
        if (!is_array($first)) {
            return null;
        }

        $id = (int) ($first['id'] ?? 0);
        $src = trim((string) ($first['src'] ?? ''));

        if ($id <= 0 && $src === '') {
            return null;
        }

        return [
            'id' => $id > 0 ? $id : null,
            'src' => $src !== '' ? $src : null,
        ];
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
