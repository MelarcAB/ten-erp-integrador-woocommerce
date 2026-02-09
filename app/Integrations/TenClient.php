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
