<?php

namespace App\Integrations;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WooCommerceClient
{
    private string $baseUrl;
    private string $key;
    private string $secret;

    public function __construct(?string $baseUrl = null, ?string $key = null, ?string $secret = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? config('services.woocommerce.base_url'), '/');
        $this->key     = (string)($key ?? config('services.woocommerce.client_key'));
        $this->secret  = (string)($secret ?? config('services.woocommerce.client_secret'));

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

        $response = $this->http()->get($url, $query);

        if (! $response->successful()) {
            Log::warning('WC products GET failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'query' => $query,
            ]);

            throw new RuntimeException("WC products GET failed with HTTP {$response->status()}");
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

        $response = $this->http()->get($url);

        if (! $response->successful()) {
            Log::warning('WC product GET failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException("WC product GET failed with HTTP {$response->status()}");
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
    public function updateProducto(int $id, array $payload): array
    {
        $url = $this->baseUrl . '/products/' . $id;

        $response = $this->http()->put($url, $payload);

        if (! $response->successful()) {
            Log::warning('WC product PUT failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            throw new RuntimeException("WC product PUT failed with HTTP {$response->status()}");
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
}
