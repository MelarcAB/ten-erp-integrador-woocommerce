<?php

namespace App\Integrations;

use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TenClient
{
    private string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        // Puedes moverlo a config/services.php si quieres.
        $this->baseUrl = rtrim($baseUrl ?? config('services.ten.base_url', 'http://81.42.251.21:2223'), '/');
    }

    protected function http(): PendingRequest
    {
        return Http::timeout((int) config('services.ten.timeout', 60))
            ->connectTimeout((int) config('services.ten.connect_timeout', 10))
            ->retry(
                (int) config('services.ten.retries', 3),
                (int) config('services.ten.retry_sleep_ms', 250),
                function ($exception) {
                    // Reintentar en timeouts/errores de red
                    return true;
                }
            )
            ->acceptJson()
            ->asJson();
    }

    /**
     * POST /Products/Get
     *
     * @return array<int, array<string, mixed>>  Lista de productos (arrays) tal y como lo devuelve TEN
     */
    public function getProducts(?Carbon $modifiedAfter = null, int $items = 100000, int $page = 0): array
    {
        $modifiedAfter ??= now()->subWeeks(2);

        // Formato esperado por TEN: "YYYY-MM-DD HH:MM:SS"
        $modifiedAfterStr = $modifiedAfter->format('Y-m-d H:i:s');

        $payload = [
            'ModifiedAfter' => $modifiedAfterStr,
            'Paginate' => [
                'items' => $items,
                'page'  => $page,
            ],
        ];

        $url = $this->baseUrl . '/Products/Get';

        $response = $this->http()->post($url, $payload);

        if (! $response->successful()) {
            Log::warning('TEN Products/Get failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            throw new RuntimeException("TEN Products/Get failed with HTTP {$response->status()}");
        }

        $json = $response->json();

        // TEN a veces devuelve { Products: [...] } y otras directamente [...]
        if (is_array($json) && array_is_list($json)) {
            return $json;
        }

        if (is_array($json)) {
            foreach (['Products', 'products', 'Data', 'data', 'Result', 'result'] as $key) {
                if (isset($json[$key]) && is_array($json[$key])) {
                    return $json[$key];
                }
            }
        }

        Log::warning('TEN Products/Get unexpected response shape', [
            'url' => $url,
            'payload' => $payload,
            'json' => $json,
        ]);

        throw new RuntimeException('TEN Products/Get returned an unexpected response shape');
    }

    /**
     * POST /Customers/Get
     *
     * @return array<int, array<string, mixed>> Lista de clientes (arrays) tal y como lo devuelve TEN
     */
    public function getCustomers(?Carbon $modifiedAfter = null, int $items = 100000, int $page = 0): array
    {
        $modifiedAfter ??= now()->subWeeks(2);

        $payload = [
            'ModifiedAfter' => $modifiedAfter->format('Y-m-d H:i:s'),
            'Paginate' => [
                'items' => $items,
                'page' => $page,
            ],
        ];

        $url = $this->baseUrl . '/Customers/Get';
        $response = $this->http()->post($url, $payload);

        if (! $response->successful()) {
            Log::warning('TEN Customers/Get failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            throw new RuntimeException("TEN Customers/Get failed with HTTP {$response->status()}");
        }

        return $this->extractListFromTenResponse($response->json(), ['Customers', 'customers']);
    }

    /**
     * Endpoint legacy que nos has indicado (minúsculas): POST /customers/get
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCustomersLegacy(?Carbon $modifiedAfter = null): array
    {
        $modifiedAfter ??= now()->subWeeks(2);

        $payload = [
            'ModifiedAfter' => $modifiedAfter->format('Y-m-d H:i:s'),
        ];

        $url = $this->baseUrl . '/customers/get';
        $response = $this->http()->post($url, $payload);

        if (! $response->successful()) {
            Log::warning('TEN customers/get failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            throw new RuntimeException("TEN customers/get failed with HTTP {$response->status()}");
        }

        return $this->extractListFromTenResponse($response->json(), ['Customers', 'customers']);
    }

    /**
     * POST /Customers/Set
     *
     * @param array<int, array<string, mixed>> $customers
     * @return array<string, mixed>
     */
    public function setCustomers(array $customers): array
    {
        $payload = [
            'Customers' => array_values($customers),
        ];

        $url = $this->baseUrl . '/Customers/Set';
        $response = $this->http()->post($url, $payload);

        if (! $response->successful()) {
            Log::warning('TEN Customers/Set failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            throw new RuntimeException("TEN Customers/Set failed with HTTP {$response->status()}");
        }

        $json = $response->json();

        // OJO: si json() devuelve null (body vacío/no-JSON), devolvemos raw explícito para diagnóstico
        if ($json === null) {
            return [
                'raw' => null,
                'http_status' => $response->status(),
                'body' => $response->body(),
            ];
        }

        return is_array($json) ? $json : ['raw' => $json];
    }

    /**
     * @param mixed $json
     * @param array<int, string> $preferredKeys
     * @return array<int, array<string, mixed>>
     */
    private function extractListFromTenResponse(mixed $json, array $preferredKeys = []): array
    {
        // TEN a veces devuelve directamente [...]
        if (is_array($json) && array_is_list($json)) {
            return $json;
        }

        if (is_array($json)) {
            foreach (array_merge($preferredKeys, ['Data', 'data', 'Result', 'result', 'Rows', 'rows', 'Items', 'items']) as $key) {
                if (isset($json[$key]) && is_array($json[$key])) {
                    return $json[$key];
                }
            }
        }

        Log::warning('TEN unexpected response shape', ['json' => $json]);
        throw new RuntimeException('TEN returned an unexpected response shape');
    }

    /**
     * POST /Query/Get
     *
     * @param int $limit TOP N
     * @return array<int, array<string, mixed>>  Lista de categorías (arrays)
     */
    public function getCategorias(int $limit = 100000): array
    {
        $empresaId = (int) config('services.ten.empresa_id', env('TEN_EMPRESA_ID'));

        if ($empresaId <= 0) {
            throw new RuntimeException('TEN_EMPRESA_ID no está configurado o es inválido.');
        }

        // Nota: TOP requiere int. Nada de concatenar strings raras.
        $limit = max(1, $limit);

        $query = "SELECT TOP {$limit} * FROM tblCategoriasWeb WHERE IdEmpresa = {$empresaId}";

        $payload = [
            'query' => $query,
        ];

        $url = $this->baseUrl . '/Query/Get';

        $response = $this->http()->post($url, $payload);

        if (! $response->successful()) {
            Log::warning('TEN Query/Get failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            throw new RuntimeException("TEN Query/Get failed with HTTP {$response->status()}");
        }

        $json = $response->json();

        // TEN puede devolver directamente [] o envolverlo
        if (is_array($json) && array_is_list($json)) {
            return $json;
        }

        if (is_array($json)) {
            foreach (['Rows', 'rows', 'Data', 'data', 'Result', 'result'] as $key) {
                if (isset($json[$key]) && is_array($json[$key])) {
                    return $json[$key];
                }
            }
        }

        Log::warning('TEN Query/Get unexpected response shape', [
            'url' => $url,
            'payload' => $payload,
            'json' => $json,
        ]);

        throw new RuntimeException('TEN Query/Get returned an unexpected response shape');
    }
}
