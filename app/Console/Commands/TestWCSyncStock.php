<?php

namespace App\Console\Commands;

use App\Integrations\TenClient;
use App\Models\Producto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class TestWCSyncStock extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test-w-c-sync-stock
        {--dry-run : No escribe en DB}
        {--limit=0 : Límite de filas de stock a procesar (0 = sin límite)}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync stock desde TEN (/Stocks/Get) hacia campo productos.stock (int)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $marker = '[TEN_STOCK_SYNC v1]';
        $this->line($marker . ' start');
        Log::info($marker . ' start');

        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        /** @var TenClient $client */
        $client = app(TenClient::class);

        try {
            $this->info('Llamando a TEN /Stocks/Get ...');
            $rows = $this->getStocks($client);
        } catch (Throwable $e) {
            $this->error('Error TEN: ' . $e->getMessage());
            Log::error($marker . ' TEN call failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        if (!is_array($rows)) {
            $this->error('TEN /Stocks/Get devolvió un formato inesperado');
            Log::error($marker . ' unexpected response', ['rows' => $rows]);
            return self::FAILURE;
        }

        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        $total = count($rows);
        $this->info("Recibidos: {$total} (dry-run=" . ($dryRun ? '1' : '0') . ")");

        $updated = 0;
        $notFound = 0;
        $skippedInvalid = 0;
        $same = 0;

        foreach ($rows as $r) {
            if (!is_array($r)) {
                $skippedInvalid++;
                continue;
            }

            $tenId = isset($r['IdProducto']) ? (string) $r['IdProducto'] : '';
            $stockRaw = $r['Stock'] ?? null;

            $tenId = trim($tenId);
            if ($tenId === '') {
                $skippedInvalid++;
                continue;
            }

            $stockInt = $this->stockToInt($stockRaw);

            $p = Producto::query()->where('ten_id', $tenId)->first();
            if (!$p) {
                $notFound++;
                continue;
            }

            $current = (int)($p->stock ?? 0);
            if ($current === $stockInt) {
                $same++;
                continue;
            }

            if ($dryRun) {
                $this->line("TEN#{$tenId} stock {$current} -> {$stockInt} (dry)");
                $updated++;
                continue;
            }

            $p->stock = $stockInt;
            $p->save();
            $updated++;
        }

        $this->info("OK fin. updated={$updated} | same={$same} | not_found={$notFound} | skipped_invalid={$skippedInvalid}");
        Log::info($marker . ' done', compact('updated', 'same', 'notFound', 'skippedInvalid'));

        return self::SUCCESS;
    }

    /**
     * TEN devuelve Stock como string decimal (p.ej. "0.000000000").
     * Necesitamos guardarlo como INTEGER.
     */
    private function stockToInt(mixed $value): int
    {
        if ($value === null) return 0;

        if (is_int($value)) return $value;
        if (is_float($value)) return (int) round($value);

        $s = trim((string) $value);
        if ($s === '') return 0;

        // Normalizar coma decimal si viniera así
        $s = str_replace(',', '.', $s);

        if (!is_numeric($s)) return 0;

        $n = (int) floor((float) $s);

        // La columna en MySQL es INT UNSIGNED. TEN puede devolver negativos (p.ej. -1) para indicar sin stock/no control.
        // Normalizamos a 0 para evitar QueryException por "Out of range".
        return max(0, $n);
    }

    /**
     * POST /Stocks/Get
     *
     * @return array<int, array<string, mixed>>
     */
    private function getStocks(TenClient $client): array
    {
        // Fallback: reproducir el patrón del TenClient (base_url + POST /Stocks/Get)
        $baseUrl = rtrim((string) config('services.ten.base_url', ''), '/');
        if ($baseUrl === '') {
            throw new RuntimeException('services.ten.base_url no está configurado');
        }

        $url = $baseUrl . '/Stocks/Get';

        $resp = Http::timeout((int) config('services.ten.timeout', 60))
            ->connectTimeout((int) config('services.ten.connect_timeout', 10))
            ->retry(
                (int) config('services.ten.retries', 3),
                (int) config('services.ten.retry_sleep_ms', 250),
                fn() => true
            )
            ->acceptJson()
            ->asJson()
            ->post($url, []);

        if (!$resp->successful()) {
            Log::warning('TEN Stocks/Get failed', [
                'url' => $url,
                'status' => $resp->status(),
                'body' => $resp->body(),
            ]);
            throw new RuntimeException("TEN Stocks/Get failed with HTTP {$resp->status()}");
        }

        $json = $resp->json();

        // TEN puede devolver directamente [] o envolverlo
        if (is_array($json) && array_is_list($json)) {
            return $json;
        }

        if (is_array($json)) {
            foreach (['Stocks', 'stocks', 'Data', 'data', 'Result', 'result', 'Rows', 'rows'] as $key) {
                if (isset($json[$key]) && is_array($json[$key])) {
                    return $json[$key];
                }
            }
        }

        Log::warning('TEN Stocks/Get unexpected response shape', [
            'url' => $url,
            'json' => $json,
        ]);

        throw new RuntimeException('TEN Stocks/Get returned an unexpected response shape');
    }
}
