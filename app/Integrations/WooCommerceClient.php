<?php

namespace App\Integrations;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WooCommerceClient
{
    private string $baseUrl;
    private string $key;
    private string $secret;
    private string $mediaUsername;
    private string $mediaPassword;

    public function __construct(?string $baseUrl = null, ?string $key = null, ?string $secret = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? config('services.woocommerce.base_url'), '/');
        $this->key     = (string)($key ?? config('services.woocommerce.client_key'));
        $this->secret  = (string)($secret ?? config('services.woocommerce.client_secret'));
        $this->mediaUsername = (string) config('services.woocommerce.media_username', '');
        $this->mediaPassword = (string) config('services.woocommerce.media_password', '');

        if ($this->baseUrl === '' || $this->key === '' || $this->secret === '') {
            throw new RuntimeException('WooCommerce config incompleta: WC_BASE_URL / WC_CLIENT_KEY / WC_CLIENT_SECRET');
        }
    }

    protected function http(): PendingRequest
    {
        // WooCommerce REST: auth por Basic (consumer_key / consumer_secret).
        // OJO: en algunos hosts requieren HTTPS para Basic Auth.
        return Http::timeout((int) config('services.woocommerce.timeout', 60))
            ->connectTimeout((int) config('services.woocommerce.connect_timeout', 10))
            ->retry(
                (int) config('services.woocommerce.retries', 3),
                (int) config('services.woocommerce.retry_sleep_ms', 250),
                fn($exception) => true
            )
            ->acceptJson()
            ->asJson()
            ->withBasicAuth($this->key, $this->secret);
    }

    protected function rawHttp(): PendingRequest
    {
        return Http::timeout((int) config('services.woocommerce.timeout', 60))
            ->connectTimeout((int) config('services.woocommerce.connect_timeout', 10))
            ->retry(
                (int) config('services.woocommerce.retries', 3),
                (int) config('services.woocommerce.retry_sleep_ms', 250),
                fn($exception) => true
            )
            ->acceptJson();
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    private function shouldRetryWithQueryAuth(Response $response): bool
    {
        if ($response->status() !== 404) {
            return false;
        }

        $body = trim($response->body());
        if ($body === '') {
            return false;
        }

        return str_contains(mb_strtolower($body), 'la p') && str_contains(mb_strtolower($body), 'no se ha encontrado');
    }

    private function withQueryAuth(array $query = []): array
    {
        return array_merge($query, [
            'consumer_key' => $this->key,
            'consumer_secret' => $this->secret,
        ]);
    }

    /**
     * GET /customers
     *
     * @return array<int, array<string, mixed>>
     */
    public function getClientes(int $perPage = 100, int $page = 1, array $params = []): array
    {
        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);

        $query = array_merge([
            'per_page' => $perPage,
            'page'     => $page,
        ], $params);

        $url = $this->baseUrl . '/customers';

        $response = $this->http()->get($url, $query);

        if (! $response->successful()) {
            Log::warning('WC customers GET failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'query' => $query,
            ]);

            throw new RuntimeException("WC customers GET failed with HTTP {$response->status()}");
        }

        $json = $response->json();

        if (is_array($json) && array_is_list($json)) {
            return $json;
        }

        Log::warning('WC customers GET unexpected response shape', [
            'url' => $url,
            'query' => $query,
            'json' => $json,
        ]);

        throw new RuntimeException('WC customers GET returned an unexpected response shape');
    }

    /**
     * GET /customers/{id}
     *
     * @return array<string, mixed>
     */
    public function getClienteById(int $id): array
    {
        $url = $this->baseUrl . '/customers/' . $id;

        $response = $this->http()->get($url);

        if (! $response->successful()) {
            Log::warning('WC customer GET failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException("WC customer GET failed with HTTP {$response->status()}");
        }

        $json = $response->json();

        if (is_array($json) && !array_is_list($json)) {
            return $json;
        }

        Log::warning('WC customer GET unexpected response shape', [
            'url' => $url,
            'json' => $json,
        ]);

        throw new RuntimeException('WC customer GET returned an unexpected response shape');
    }

    /**
     * POST /customers (crear)
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createCliente(array $payload): array
    {
        $url = $this->baseUrl . '/customers';

        $response = $this->http()->post($url, $payload);

        if (! $response->successful()) {
            Log::warning('WC customer POST failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            throw new RuntimeException("WC customer POST failed with HTTP {$response->status()}");
        }

        $json = $response->json();

        if (is_array($json) && !array_is_list($json)) {
            return $json;
        }

        Log::warning('WC customer POST unexpected response shape', [
            'url' => $url,
            'json' => $json,
        ]);

        throw new RuntimeException('WC customer POST returned an unexpected response shape');
    }

    /**
     * PUT /customers/{id} (actualizar)
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateCliente(int $id, array $payload): array
    {
        $url = $this->baseUrl . '/customers/' . $id;

        $response = $this->http()->put($url, $payload);

        if (! $response->successful()) {
            Log::warning('WC customer PUT failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            throw new RuntimeException("WC customer PUT failed with HTTP {$response->status()}");
        }

        $json = $response->json();

        if (is_array($json) && !array_is_list($json)) {
            return $json;
        }

        Log::warning('WC customer PUT unexpected response shape', [
            'url' => $url,
            'json' => $json,
        ]);

        throw new RuntimeException('WC customer PUT returned an unexpected response shape');
    }

    /**
     * GET /orders
     *
     * Docs params típicos:
     * - status: pending|processing|on-hold|completed|cancelled|refunded|failed|trash|any
     * - after / before (ISO8601): 2026-02-01T00:00:00
     * - modified_after / modified_before (ISO8601) (según versión)
     * - customer: id
     * - search
     * - orderby: date|modified|id|include|title|slug
     * - order: asc|desc
     * - per_page, page
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPedidos(int $perPage = 100, int $page = 1, array $params = []): array
    {
        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);

        $query = array_merge([
            'per_page' => $perPage,
            'page'     => $page,
        ], $params);

        $url = $this->baseUrl . '/orders';

        $response = $this->http()->get($url, $query);

        if (! $response->successful()) {
            Log::warning('WC orders GET failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'query' => $query,
            ]);

            throw new RuntimeException("WC orders GET failed with HTTP {$response->status()}");
        }

        $json = $response->json();

        if (is_array($json) && array_is_list($json)) {
            return $json;
        }

        Log::warning('WC orders GET unexpected response shape', [
            'url' => $url,
            'query' => $query,
            'json' => $json,
        ]);

        throw new RuntimeException('WC orders GET returned an unexpected response shape');
    }

    /**
     * GET /products?sku={sku}
     *
     * @return array<int, array<string, mixed>>
     */
    public function getProductosBySku(string $sku, int $perPage = 100, int $page = 1, array $params = []): array
    {
        $sku = trim($sku);
        if ($sku === '') return [];

        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);

        $query = array_merge([
            'per_page' => $perPage,
            'page'     => $page,
            'sku'      => $sku,
        ], $params);

        $url = $this->baseUrl . '/products';

        $response = $this->rawHttp()->get($url, $this->withQueryAuth($query));

        if (! $response->successful()) {
            Log::warning('WC products GET failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'query' => $query,
                'effective_url' => $url . '?' . http_build_query($this->withQueryAuth($query)),
                'query_auth_effective_url' => $url . '?' . http_build_query($this->withQueryAuth($query)),
            ]);

            throw new RuntimeException("WC products GET failed with HTTP {$response->status()}: " . trim($response->body()));
        }

        $json = $response->json();

        if (is_array($json) && array_is_list($json)) {
            return $json;
        }

        Log::warning('WC products GET unexpected response shape', [
            'url' => $url,
            'query' => $query,
            'json' => $json,
        ]);

        throw new RuntimeException('WC products GET returned an unexpected response shape');
    }

    /**
     * GET /products
     *
     * @return array<int, array<string, mixed>>
     */
    public function getProductos(int $perPage = 100, int $page = 1, array $params = []): array
    {
        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);

        $query = array_merge([
            'per_page' => $perPage,
            'page'     => $page,
        ], $params);

        $url = $this->baseUrl . '/products';

        $response = $this->rawHttp()->get($url, $this->withQueryAuth($query));

        if (! $response->successful()) {
            Log::warning('WC products GET failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'query' => $query,
                'effective_url' => $url . '?' . http_build_query($this->withQueryAuth($query)),
            ]);

            throw new RuntimeException("WC products GET failed with HTTP {$response->status()}: " . trim($response->body()));
        }

        $json = $response->json();

        if (is_array($json) && array_is_list($json)) {
            return $json;
        }

        Log::warning('WC products GET unexpected response shape', [
            'url' => $url,
            'query' => $query,
            'json' => $json,
        ]);

        throw new RuntimeException('WC products GET returned an unexpected response shape');
    }

    /**
     * GET /products/{id}
     *
     * @return array<string, mixed>
     */
    public function getProductoById(int $id): array
    {
        $url = $this->baseUrl . '/products/' . $id;

        $response = $this->rawHttp()->get($url, $this->withQueryAuth());

        if (! $response->successful()) {
            Log::warning('WC product GET failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'query_auth_effective_url' => $url . '?' . http_build_query($this->withQueryAuth()),
            ]);

            throw new RuntimeException("WC product GET failed with HTTP {$response->status()}: " . trim($response->body()));
        }

        $json = $response->json();

        if (is_array($json) && !array_is_list($json)) {
            return $json;
        }

        Log::warning('WC product GET unexpected response shape', [
            'url' => $url,
            'json' => $json,
        ]);

        throw new RuntimeException('WC product GET returned an unexpected response shape');
    }

    /**
     * POST /products (crear)
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createProducto(array $payload): array
    {
        $url = $this->baseUrl . '/products';

        $response = $this->http()->post($url, $payload);

        if (! $response->successful()) {
            Log::warning('WC product POST failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            throw new RuntimeException("WC product POST failed with HTTP {$response->status()}");
        }

        $json = $response->json();

        if (is_array($json) && !array_is_list($json)) {
            return $json;
        }

        Log::warning('WC product POST unexpected response shape', [
            'url' => $url,
            'json' => $json,
        ]);

        throw new RuntimeException('WC product POST returned an unexpected response shape');
    }

    /**
     * PUT /products/{id} (actualizar)
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateProducto(int $id, array $payload, bool $parseResponse = true): array
    {
        $url = $this->baseUrl . '/products/' . $id;

        $response = $this->rawHttp()
            ->asJson()
            ->put($url . '?' . http_build_query($this->withQueryAuth()), $payload);

        if (! $response->successful()) {
            Log::warning('WC product PUT failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
                'query_auth_effective_url' => $url . '?' . http_build_query($this->withQueryAuth()),
            ]);

            throw new RuntimeException("WC product PUT failed with HTTP {$response->status()}: " . trim($response->body()));
        }

        if (! $parseResponse) {
            return [
                'ok' => true,
                'status' => $response->status(),
                'id' => $id,
            ];
        }

        $json = $response->json();

        if (is_array($json) && !array_is_list($json)) {
            return $json;
        }

        Log::warning('WC product PUT unexpected response shape', [
            'url' => $url,
            'json' => $json,
        ]);

        throw new RuntimeException('WC product PUT returned an unexpected response shape');
    }

    /**
     * GET /products/categories
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCategoriasProductos(int $perPage = 100, int $page = 1, array $params = []): array
    {
        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);

        $query = array_merge([
            'per_page' => $perPage,
            'page'     => $page,
        ], $params);

        $url = $this->baseUrl . '/products/categories';

        $response = $this->http()->get($url, $query);

        if (! $response->successful()) {
            Log::warning('WC product categories GET failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'query' => $query,
            ]);

            throw new RuntimeException("WC product categories GET failed with HTTP {$response->status()}");
        }

        $json = $response->json();

        if (is_array($json) && array_is_list($json)) {
            return $json;
        }

        Log::warning('WC product categories GET unexpected response shape', [
            'url' => $url,
            'query' => $query,
            'json' => $json,
        ]);

        throw new RuntimeException('WC product categories GET returned an unexpected response shape');
    }

    /**
     * GET /products/categories?slug={slug}
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCategoriasProductosBySlug(string $slug, int $perPage = 100, int $page = 1, array $params = []): array
    {
        $slug = trim($slug);
        if ($slug === '') return [];

        return $this->getCategoriasProductos($perPage, $page, array_merge($params, ['slug' => $slug]));
    }

    /**
     * GET /products/categories/{id}
     *
     * @return array<string, mixed>
     */
    public function getCategoriaProductoById(int $id): array
    {
        $url = $this->baseUrl . '/products/categories/' . $id;

        $response = $this->http()->get($url);

        if (! $response->successful()) {
            Log::warning('WC product category GET failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException("WC product category GET failed with HTTP {$response->status()}");
        }

        $json = $response->json();

        if (is_array($json) && !array_is_list($json)) {
            return $json;
        }

        Log::warning('WC product category GET unexpected response shape', [
            'url' => $url,
            'json' => $json,
        ]);

        throw new RuntimeException('WC product category GET returned an unexpected response shape');
    }

    /**
     * Verifica si existe una categoría por ID.
     */
    public function categoriaProductoExists(int $id): bool
    {
        $url = $this->baseUrl . '/products/categories/' . $id;

        $response = $this->http()->get($url);

        if ($response->status() === 404) {
            return false;
        }

        if (! $response->successful()) {
            Log::warning('WC product category GET failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException("WC product category GET failed with HTTP {$response->status()}");
        }

        $json = $response->json();

        if (is_array($json) && !array_is_list($json)) {
            return !empty($json['id']);
        }

        Log::warning('WC product category GET unexpected response shape', [
            'url' => $url,
            'json' => $json,
        ]);

        throw new RuntimeException('WC product category GET returned an unexpected response shape');
    }

    /**
     * POST /products/categories
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createCategoriaProducto(array $payload): array
    {
        $url = $this->baseUrl . '/products/categories';

        $response = $this->http()->post($url, $payload);

        if (! $response->successful()) {
            Log::warning('WC product category POST failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            throw new RuntimeException("WC product category POST failed with HTTP {$response->status()}");
        }

        $json = $response->json();

        if (is_array($json) && !array_is_list($json)) {
            return $json;
        }

        Log::warning('WC product category POST unexpected response shape', [
            'url' => $url,
            'json' => $json,
        ]);

        throw new RuntimeException('WC product category POST returned an unexpected response shape');
    }

    /**
     * PUT /products/categories/{id}
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateCategoriaProducto(int $id, array $payload): array
    {
        $url = $this->baseUrl . '/products/categories/' . $id;

        $response = $this->http()->put($url, $payload);

        if (! $response->successful()) {
            Log::warning('WC product category PUT failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            throw new RuntimeException("WC product category PUT failed with HTTP {$response->status()}");
        }

        $json = $response->json();

        if (is_array($json) && !array_is_list($json)) {
            return $json;
        }

        Log::warning('WC product category PUT unexpected response shape', [
            'url' => $url,
            'json' => $json,
        ]);

        throw new RuntimeException('WC product category PUT returned an unexpected response shape');
    }

    /**
     * GET /products/brands
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMarcasProductos(int $perPage = 100, int $page = 1, array $params = []): array
    {
        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);

        $query = array_merge([
            'per_page' => $perPage,
            'page'     => $page,
        ], $params);

        $url = $this->baseUrl . '/products/brands';
        $response = $this->http()->get($url, $query);

        if (! $response->successful()) {
            Log::warning('WC product brands GET failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'query' => $query,
            ]);

            throw new RuntimeException("WC product brands GET failed with HTTP {$response->status()}");
        }

        $json = $response->json();
        if (is_array($json) && array_is_list($json)) {
            return $json;
        }

        Log::warning('WC product brands GET unexpected response shape', [
            'url' => $url,
            'query' => $query,
            'json' => $json,
        ]);

        throw new RuntimeException('WC product brands GET returned an unexpected response shape');
    }

    /**
     * POST /products/brands
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createMarcaProducto(array $payload): array
    {
        $url = $this->baseUrl . '/products/brands';
        $response = $this->http()->post($url, $payload);

        if (! $response->successful()) {
            Log::warning('WC product brand POST failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            throw new RuntimeException("WC product brand POST failed with HTTP {$response->status()}");
        }

        $json = $response->json();
        if (is_array($json) && !array_is_list($json)) {
            return $json;
        }

        Log::warning('WC product brand POST unexpected response shape', [
            'url' => $url,
            'json' => $json,
        ]);

        throw new RuntimeException('WC product brand POST returned an unexpected response shape');
    }

    /**
     * @param array<int,array<string,mixed>> $updates
     * @return array<string,mixed>
     */
    public function updateProductosBatch(array $updates, bool $parseResponse = true): array
    {
        if (empty($updates)) {
            return ['update' => []];
        }

        $payload = ['update' => array_values($updates)];
        $url = $this->baseUrl . '/products/batch';
        $response = $this->rawHttp()
            ->asJson()
            ->post($url . '?' . http_build_query($this->withQueryAuth()), $payload);

        if (! $response->successful()) {
            Log::warning('WC products batch POST failed', [
                'url' => $url,
                'status' => $response->status(),
                'payload_count' => count($updates),
                'body' => $response->body(),
                'effective_url' => $url . '?' . http_build_query($this->withQueryAuth()),
            ]);

            throw new RuntimeException("WC products batch POST failed with HTTP {$response->status()}: " . trim($response->body()));
        }

        if (! $parseResponse) {
            return ['ok' => true, 'status' => $response->status(), 'count' => count($updates)];
        }

        $json = $response->json();
        if (is_array($json)) {
            return $json;
        }

        Log::warning('WC products batch POST unexpected response shape', [
            'url' => $url,
            'json' => $json,
        ]);

        throw new RuntimeException('WC products batch POST returned an unexpected response shape');
    }

    /**
     * @return array<string,mixed>
     */
    public function uploadMedia(string $filename, string $contents, string $mimeType = 'application/octet-stream'): array
    {
        $filename = trim($filename);
        if ($filename === '') {
            throw new RuntimeException('WP media upload requiere filename');
        }

        $url = $this->resolveWordPressMediaUrl();
        $request = $this->rawHttp();

        if ($this->mediaUsername !== '' && $this->mediaPassword !== '') {
            $request = $request->withBasicAuth($this->mediaUsername, $this->mediaPassword);
        } else {
            $request = $request->withBasicAuth($this->key, $this->secret);
        }

        $response = $request
            ->attach('file', $contents, $filename, ['Content-Type' => $mimeType])
            ->post($url);

        if (! $response->successful()) {
            Log::warning('WP media POST failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'filename' => $filename,
                'mime_type' => $mimeType,
                'using_media_credentials' => $this->mediaUsername !== '' && $this->mediaPassword !== '',
            ]);

            throw new RuntimeException("WP media POST failed with HTTP {$response->status()}: " . trim($response->body()));
        }

        $json = $response->json();
        if (is_array($json) && !array_is_list($json)) {
            return $json;
        }

        Log::warning('WP media POST unexpected response shape', [
            'url' => $url,
            'json' => $json,
            'filename' => $filename,
        ]);

        throw new RuntimeException('WP media POST returned an unexpected response shape');
    }

    private function resolveWordPressMediaUrl(): string
    {
        $mediaBase = preg_replace('#/wp-json/wc/[^/]+/?$#', '/wp-json/wp/v2', $this->baseUrl);
        if (!is_string($mediaBase) || $mediaBase === $this->baseUrl) {
            throw new RuntimeException('No se pudo derivar la URL de WP Media desde WC_BASE_URL');
        }

        return rtrim($mediaBase, '/') . '/media';
    }
}
