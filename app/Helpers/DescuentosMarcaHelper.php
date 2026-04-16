<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DescuentosMarcaHelper
{
    public static function getDescuentos(): array
    {
        $url = 'https://ferrate.pd-12.com/wp-json/takeoff/v1/brand-rates';

        $response = Http::timeout(60)
            ->connectTimeout(10)
            ->retry(3, 250, fn () => true)
            ->acceptJson()
            ->get($url);

        if (! $response->successful()) {
            Log::warning('Brand rates GET failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException("Brand rates GET failed with HTTP {$response->status()}");
        }

        $json = $response->json();

        if (is_array($json) && array_is_list($json)) {
            return $json;
        }

        if (is_array($json)) {
            foreach (['data', 'Data', 'result', 'Result', 'rows', 'Rows'] as $key) {
                if (isset($json[$key]) && is_array($json[$key])) {
                    return $json[$key];
                }
            }
        }

        Log::warning('Brand rates GET unexpected response shape', [
            'url' => $url,
            'json' => $json,
        ]);

        throw new RuntimeException('Brand rates GET returned an unexpected response shape');
    }
}
