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
        {--batch-size=50 : Compatibilidad legacy; ya no se usa (flujo individual por fichero)}
        {--max-retries=2 : Reintentos por producto si Woo falla}
        {--check-url : Verifica URL pública de imagen con HEAD antes de enviar a Woo}
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

        $batchSize = max(1, min(100, (int) $this->option('batch-size')));
        $maxRetries = max(0, (int) $this->option('max-retries'));
        $checkUrl = (bool) $this->option('check-url');
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
        if ($batchSize !== 50) {
            $this->line("Aviso: --batch-size={$batchSize} ignorado; el flujo ahora es individual por fichero.");
        }

        $conn = @ftp_connect($host, $port, $timeout);
        if (!$conn) {
            $this->error('No se pudo conectar al servidor FTP');
            Log::error($marker . ' ftp connect failed', ['host' => $host, 'port' => $port]);
            return self::FAILURE;
        }

        try {
            if (!@ftp_login($conn, $user, $pass)) {
                $this->error('Login FTP fallido');
                Log::error($marker . ' ftp login failed', ['host' => $host, 'user' => $user, 'password' => $pass]);
                return self::FAILURE;
            }

            @ftp_pasv($conn, $passive);

            // 1) Cliente Woo
            /** @var \App\Integrations\WooCommerceClient $woo */
            $woo = app(\App\Integrations\WooCommerceClient::class);

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

            /** @var array<int,array{filename:string,name_no_ext:string,ftp_path:string}> $imageFiles */
            $imageFiles = [];
            /** @var array<string,bool> $candidateSkus */
            $candidateSkus = [];

            foreach ($list as $item) {
                $filename = basename((string) $item);
                if ($filename === '' || $filename === '.' || $filename === '..') {
                    continue;
                }

                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (!in_array($ext, $imgExts, true)) {
                    continue;
                }

                $nameNoExt = trim((string) pathinfo($filename, PATHINFO_FILENAME));
                if ($nameNoExt === '') {
                    continue;
                }

                $variant = $this->parseFilenameVariant($nameNoExt);

                $imageFiles[] = [
                    'filename' => $filename,
                    'name_no_ext' => $nameNoExt,
                    'ftp_path' => (string) $item,
                ];
                $candidateSkus[$variant['sku']] = true;
            }

            $candidateSkuList = array_keys($candidateSkus);
            $this->info('Imágenes candidatas detectadas: ' . count($imageFiles) . ' | SKUs candidatos=' . count($candidateSkuList));

            // 3) Resolver SKU -> Woo ID consultando WooCommerce por SKU base.
            $skuToId = [];
            $wooMissBySku = [];

            if (!empty($candidateSkuList)) {
                $this->info('Resolviendo SKUs directamente en Woo: ' . count($candidateSkuList));
                $resolvedFromWoo = 0;
                foreach ($candidateSkuList as $idx => $sku) {
                    $row = $idx + 1;
                    $wooId = $this->resolveWooIdBySku($woo, $sku, $skuToId, $wooMissBySku);
                    if ($wooId > 0) {
                        $resolvedFromWoo++;
                        $this->line("SKU RESUELTO [{$row}/" . count($candidateSkuList) . "] sku={$sku} -> Woo#{$wooId}");
                    } else {
                        $this->line("SKU NO ENCONTRADO [{$row}/" . count($candidateSkuList) . "] sku={$sku}");
                    }
                }
                $this->info("SKUs resueltos en Woo: {$resolvedFromWoo}");
            }

            usort($imageFiles, function (array $a, array $b): int {
                $va = $this->parseFilenameVariant((string) $a['name_no_ext']);
                $vb = $this->parseFilenameVariant((string) $b['name_no_ext']);
                $skuCmp = strcmp((string) $va['sku'], (string) $vb['sku']);
                if ($skuCmp !== 0) {
                    return $skuCmp;
                }
                $typeCmp = ((string) $va['type'] === 'primary' ? 0 : 1) <=> ((string) $vb['type'] === 'primary' ? 0 : 1);
                if ($typeCmp !== 0) {
                    return $typeCmp;
                }
                return $this->compareSecondarySuffix((string) ($va['suffix'] ?? ''), (string) ($vb['suffix'] ?? ''));
            });

            $syncedFiles = 0;
            $alreadySyncedFiles = 0;
            $errors = 0;
            $deleted = 0;

            foreach ($imageFiles as $idx => $entry) {
                $processedFiles = $idx + 1;
                $filename = $entry['filename'];
                $nameNoExt = $entry['name_no_ext'];
                $ftpPath = $entry['ftp_path'];
                $variant = $this->parseFilenameVariant($nameNoExt);
                $sku = (string) $variant['sku'];
                $type = (string) $variant['type'];
                $suffix = (string) ($variant['suffix'] ?? '');

                $wooId = $this->resolveWooIdBySku($woo, $sku, $skuToId, $wooMissBySku);
                if ($wooId <= 0) {
                    $skippedFiles++;
                    $this->line("IMG SKIP [{$processedFiles}/" . count($imageFiles) . "] filename={$filename} sku={$sku} (sin WooID)");
                    continue;
                }

                $this->line(
                    "IMG PROCESS [{$processedFiles}/" . count($imageFiles) . "] filename={$filename} sku={$sku} woo_id={$wooId} type={$type}"
                    . ($suffix !== '' ? " suffix={$suffix}" : '')
                );

                $imageUrl = $this->buildPublicImageUrl($publicBase, $filename);
                $this->line("IMG URL filename={$filename} sku={$sku} -> {$imageUrl}");
                if ($checkUrl && !$this->urlExists($imageUrl)) {
                    $skippedFiles++;
                    $this->warn("IMG SKIP filename={$filename} sku={$sku} -> URL no disponible");
                    continue;
                }

                $existingImages = $this->fetchExistingImages($woo, $wooId);
                $existingNormalized = [];
                foreach ($existingImages as $image) {
                    $src = trim((string) ($image['src'] ?? ''));
                    if ($src !== '') {
                        $existingNormalized[] = $this->normalizeImageIdentifier($src);
                    }
                }

                $normalizedCurrent = $this->normalizeImageIdentifier($imageUrl);
                if (in_array($normalizedCurrent, $existingNormalized, true)) {
                    $this->line("IMG YA SINCRONIZADA filename={$filename} sku={$sku} -> borrando FTP");
                    $deleted += $this->deleteSyncedFiles($conn, [[
                        'ftp_path' => $ftpPath,
                        'filename' => $filename,
                    ]]);
                    $alreadySyncedFiles++;
                    continue;
                }

                $payloadImages = [];
                if ($type === 'primary') {
                    $payloadImages[] = ['src' => $imageUrl, 'position' => 0];
                    $position = 1;
                    foreach ($existingImages as $image) {
                        $id = (int) ($image['id'] ?? 0);
                        $src = trim((string) ($image['src'] ?? ''));
                        if ($src !== '' && $this->normalizeImageIdentifier($src) === $normalizedCurrent) {
                            continue;
                        }
                        if ($id > 0) {
                            $payloadImages[] = ['id' => $id, 'position' => $position++];
                        } elseif ($src !== '') {
                            $payloadImages[] = ['src' => $src, 'position' => $position++];
                        }
                    }
                } else {
                    if (!empty($existingImages)) {
                        $position = 0;
                        foreach ($existingImages as $image) {
                            $id = (int) ($image['id'] ?? 0);
                            $src = trim((string) ($image['src'] ?? ''));
                            if ($id > 0) {
                                $payloadImages[] = ['id' => $id, 'position' => $position++];
                            } elseif ($src !== '') {
                                $payloadImages[] = ['src' => $src, 'position' => $position++];
                            }
                        }
                        $payloadImages[] = ['src' => $imageUrl, 'position' => $position];
                    } else {
                        // Si no existe principal todavía, la primera secundaria pasa a principal.
                        $payloadImages[] = ['src' => $imageUrl, 'position' => 0];
                    }
                }

                if ($dryRun) {
                    $this->line("DRY RUN: filename={$filename} sku={$sku} woo_id={$wooId} images=" . count($payloadImages));
                    continue;
                }

                $updateResult = $this->updateWooProductImagesWithRetry(
                    $woo,
                    $wooId,
                    ['images' => $payloadImages],
                    $maxRetries,
                    $marker,
                    $sku,
                    $filename,
                    $imageUrl
                );

                if (!$updateResult['ok']) {
                    $fallbackUsed = false;
                    if ($this->isRemoteImageFetchError((string) ($updateResult['error'] ?? ''))) {
                        $this->warn("Fallback binario para Woo#{$wooId} sku={$sku} filename={$filename}");
                        $fallbackUsed = $this->uploadBinaryImageFallback(
                            $conn,
                            $woo,
                            $wooId,
                            $ftpPath,
                            $filename,
                            $payloadImages,
                            $maxRetries,
                            $marker,
                            $sku
                        );
                    }

                    if (!$fallbackUsed) {
                        $errors++;
                        continue;
                    }
                }

                $deleted += $this->deleteSyncedFiles($conn, [[
                    'ftp_path' => $ftpPath,
                    'filename' => $filename,
                ]]);
                $syncedFiles++;

                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }

            if ($dryRun) {
                $this->info('DRY RUN activo: no se enviaron updates a Woo ni se borraron archivos FTP.');
            }

            $this->info(
                "OK: files_processed={$processedFiles} | files_skipped={$skippedFiles}"
                . " | files_synced={$syncedFiles} | files_already_synced={$alreadySyncedFiles}"
                . " | errors={$errors} | deleted={$deleted}"
            );
            return self::SUCCESS;
        } finally {
            @ftp_close($conn);
        }
    }

    /**
     * @param array<string,int> $skuToIdCache
     * @param array<string,bool> $skuMissCache
     */
    private function resolveWooIdBySku(
        WooCommerceClient $woo,
        string $sku,
        array &$skuToIdCache,
        array &$skuMissCache
    ): int {
        $sku = trim($sku);
        if ($sku === '') {
            return 0;
        }

        if (isset($skuToIdCache[$sku])) {
            return (int) $skuToIdCache[$sku];
        }
        if (isset($skuMissCache[$sku])) {
            return 0;
        }

        try {
            $rows = $woo->getProductosBySku($sku, 1, 1);
            $first = $rows[0] ?? null;
            $wooId = (int) ($first['id'] ?? 0);
            if ($wooId > 0) {
                $skuToIdCache[$sku] = $wooId;
                $returnedSku = trim((string) ($first['sku'] ?? ''));
                if ($returnedSku !== '') {
                    $skuToIdCache[$returnedSku] = $wooId;
                }
                return $wooId;
            }
        } catch (Throwable $e) {
            Log::warning('[PROD_SYNC_IMG v1] resolve sku failed', [
                'sku' => $sku,
                'base_url' => $woo->getBaseUrl(),
                'request_url' => rtrim($woo->getBaseUrl(), '/') . '/products?' . http_build_query([
                    'per_page' => 1,
                    'page' => 1,
                    'sku' => $sku,
                ]),
                'error' => $e->getMessage(),
            ]);
        }

        $skuMissCache[$sku] = true;
        return 0;
    }

    /**
     * Los ficheros tipo SKU_2, SKU_6... se consideran imagen secundaria del SKU base.
     * Solo tratamos como secundaria cuando el sufijo es numerico para no romper SKUs
     * legitimos con guion bajo en medio.
     *
     * @return array{sku:string,type:string,suffix:?string}
     */
    private function parseFilenameVariant(string $filenameNoExt): array
    {
        $filenameNoExt = trim($filenameNoExt);
        if ($filenameNoExt === '') {
            return [
                'sku' => '',
                'type' => 'primary',
                'suffix' => null,
            ];
        }

        if (preg_match('/^(.+)_([0-9]+)$/', $filenameNoExt, $matches) === 1) {
            $baseSku = trim((string) ($matches[1] ?? ''));
            $suffix = trim((string) ($matches[2] ?? ''));
            if ($baseSku !== '' && $suffix !== '') {
                return [
                    'sku' => $baseSku,
                    'type' => 'secondary',
                    'suffix' => $suffix,
                ];
            }
        }

        return [
            'sku' => $filenameNoExt,
            'type' => 'primary',
            'suffix' => null,
        ];
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
     * @return array<int, array{id?:int,src?:string}>
     */
    private function fetchExistingImages(WooCommerceClient $woo, int $wooId): array
    {
        if ($wooId <= 0) {
            return [];
        }

        try {
            $product = $woo->getProductoById($wooId);
        } catch (Throwable $e) {
            Log::warning('[PROD_SYNC_IMG v1] no se pudo leer producto Woo para preservar principal', [
                'woocommerce_id' => $wooId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        $images = $product['images'] ?? null;
        if (!is_array($images) || empty($images)) {
            return [];
        }

        $out = [];
        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }
            $id = (int) ($image['id'] ?? 0);
            $src = trim((string) ($image['src'] ?? ''));
            if ($id <= 0 && $src === '') {
                continue;
            }
            $out[] = [
                'id' => $id > 0 ? $id : null,
                'src' => $src !== '' ? $src : null,
            ];
        }

        return $out;
    }

    private function normalizeImageIdentifier(string $src): string
    {
        $path = parse_url($src, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return trim($src);
        }

        return rawurldecode(basename($path));
    }

    /**
     * @param resource $ftpConn
     * @param array<int,array{ftp_path:string,filename:string}> $files
     */
    private function deleteSyncedFiles($ftpConn, array $files): int
    {
        $deleted = 0;
        $unique = [];
        foreach ($files as $file) {
            $ftpPath = trim((string) ($file['ftp_path'] ?? ''));
            $filename = trim((string) ($file['filename'] ?? ''));
            if ($ftpPath === '' || $filename === '') {
                continue;
            }
            $unique[$ftpPath] = [
                'ftp_path' => $ftpPath,
                'filename' => $filename,
            ];
        }

        foreach (array_values($unique) as $file) {
            $ftpPath = $file['ftp_path'];
            $filename = $file['filename'];
            if (@ftp_delete($ftpConn, $ftpPath)) {
                $deleted++;
            } else {
                $this->warn("No se pudo borrar {$filename} del FTP (path={$ftpPath}).");
            }
        }

        return $deleted;
    }
    /**
     * @param array<string,mixed> $payload
     */
    private function updateWooProductImagesWithRetry(
        WooCommerceClient $woo,
        int $wooId,
        array $payload,
        int $maxRetries,
        string $marker,
        string $sku,
        string $filename,
        string $imageUrl
    ): array {
        $attempts = max(1, $maxRetries + 1);
        $lastError = null;

        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $woo->updateProducto($wooId, $payload, false);
                return ['ok' => true, 'error' => null];
            } catch (Throwable $e) {
                $lastError = $e;
                if ($i < $attempts) {
                    usleep(300000 * $i); // backoff 0.3s, 0.6s, ...
                }
            }
        }

        $errorMessage = $lastError ? $lastError->getMessage() : 'unknown error';
        $this->warn("Error actualizando Woo#{$wooId} sku={$sku}: {$errorMessage}");
        $this->warn("IMG ERROR CONTEXT filename={$filename} sku={$sku} image_url={$imageUrl}");
        Log::warning($marker . ' woo update failed', [
            'woocommerce_id' => $wooId,
            'sku' => $sku,
            'filename' => $filename,
            'image_url' => $imageUrl,
            'payload' => $payload,
            'error' => $errorMessage,
        ]);

        return ['ok' => false, 'error' => $errorMessage];
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

    private function isRemoteImageFetchError(string $errorMessage): bool
    {
        $errorMessage = mb_strtolower(trim($errorMessage));
        if ($errorMessage === '') {
            return false;
        }

        return str_contains($errorMessage, 'woocommerce_product_image_upload_error')
            || str_contains($errorMessage, 'error recuperando la imagen remota');
    }

    /**
     * @param resource $ftpConn
     * @param array<int,array{id?:int,src?:string,position?:int}> $payloadImages
     */
    private function uploadBinaryImageFallback(
        $ftpConn,
        WooCommerceClient $woo,
        int $wooId,
        string $ftpPath,
        string $filename,
        array $payloadImages,
        int $maxRetries,
        string $marker,
        string $sku
    ): bool {
        $binary = $this->downloadFtpBinary($ftpConn, $ftpPath);
        if ($binary === null) {
            $this->warn("No se pudo descargar desde FTP para fallback binario: {$filename}");
            return false;
        }

        try {
            $media = $woo->uploadMedia($filename, $binary, $this->guessMimeType($filename));
        } catch (Throwable $e) {
            $this->warn("Error subiendo media binaria a WordPress para Woo#{$wooId} sku={$sku}: {$e->getMessage()}");
            Log::warning($marker . ' binary media upload failed', [
                'woocommerce_id' => $wooId,
                'sku' => $sku,
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        $mediaId = (int) ($media['id'] ?? 0);
        if ($mediaId <= 0) {
            $this->warn("La subida binaria no devolvió attachment válido para {$filename}");
            return false;
        }

        $payloadWithMediaId = $this->replaceImageSourceWithMediaId($payloadImages, $filename, $mediaId);
        $updateResult = $this->updateWooProductImagesWithRetry(
            $woo,
            $wooId,
            ['images' => $payloadWithMediaId],
            $maxRetries,
            $marker,
            $sku
        );

        return (bool) ($updateResult['ok'] ?? false);
    }

    /**
     * @param resource $ftpConn
     */
    private function downloadFtpBinary($ftpConn, string $ftpPath): ?string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            return null;
        }

        try {
            if (!@ftp_fget($ftpConn, $stream, $ftpPath, FTP_BINARY)) {
                return null;
            }

            rewind($stream);
            $contents = stream_get_contents($stream);
            return is_string($contents) ? $contents : null;
        } finally {
            fclose($stream);
        }
    }

    private function guessMimeType(string $filename): string
    {
        return match (strtolower((string) pathinfo($filename, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    /**
     * @param array<int,array{id?:int,src?:string,position?:int}> $payloadImages
     * @return array<int,array<string,int|string>>
     */
    private function replaceImageSourceWithMediaId(array $payloadImages, string $filename, int $mediaId): array
    {
        $normalizedFilename = $this->normalizeImageIdentifier($filename);
        $updated = [];

        foreach ($payloadImages as $image) {
            $src = trim((string) ($image['src'] ?? ''));
            $position = (int) ($image['position'] ?? 0);
            $id = (int) ($image['id'] ?? 0);

            if ($src !== '' && $this->normalizeImageIdentifier($src) === $normalizedFilename) {
                $updated[] = ['id' => $mediaId, 'position' => $position];
                continue;
            }

            if ($id > 0) {
                $updated[] = ['id' => $id, 'position' => $position];
                continue;
            }

            if ($src !== '') {
                $updated[] = ['src' => $src, 'position' => $position];
            }
        }

        return $updated;
    }
}
