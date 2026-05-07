<?php

namespace App\Console\Commands;

use App\Integrations\WooCommerceClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ProductosPesoPorMarca extends Command
{
    protected $signature = 'app:productos-peso-por-marca
        {--batch-size=100 : Productos por batch WooCommerce (1-100)}
        {--debug-missing : Muestra categorías y pesos probados en productos sin peso resuelto}
        {--dry-run : Calcula pesos pero no actualiza WooCommerce}
    ';

    protected $description = 'Asigna peso a productos Woo con peso 0/null según pesos configurados por categoría.';

    private const PRODUCTS_ZERO_WEIGHT_URL = 'https://ferrate.com/wp-json/takeoff/v1/products-zero-weight';
    private const CATEGORY_WEIGHTS_URL = 'https://ferrate.com/wp-json/takeoff/v1/category-weights';

    /**
     * @var array<int,array<string,mixed>>
     */
    private array $categoryRowsById = [];

    public function handle(): int
    {
        $marker = '[PRODUCTOS_PESO_POR_MARCA v1]';
        $batchSize = max(1, min(100, (int) $this->option('batch-size')));
        $debugMissing = (bool) $this->option('debug-missing');
        $dryRun = (bool) $this->option('dry-run');

        $this->line($marker . ' start');
        Log::info($marker . ' start', ['batch_size' => $batchSize, 'dry_run' => $dryRun]);

        try {
            $categoryWeights = $this->fetchCategoryWeights();
            if (empty($categoryWeights)) {
                $this->warn('No hay pesos de categorías configurados. Nada que actualizar.');
                return self::SUCCESS;
            }

            $products = $this->fetchZeroWeightProducts();
            $total = count($products);
            $this->info("Productos con peso 0/null recibidos: {$total}");

            if ($total === 0) {
                return self::SUCCESS;
            }

            $updates = [];
            $withoutWeight = 0;
            $invalid = 0;

            foreach ($products as $product) {
                if (!is_array($product)) {
                    $invalid++;
                    continue;
                }

                $productId = (int) ($product['product_id'] ?? 0);
                if ($productId <= 0) {
                    $invalid++;
                    continue;
                }

                $weight = $this->resolveProductWeight($product, $categoryWeights);
                if ($weight === null) {
                    $withoutWeight++;
                    $this->line('Sin peso configurado: #' . $productId . ' ' . trim((string) ($product['name'] ?? '')));
                    if ($debugMissing) {
                        foreach ($this->describeProductCategories($product) as $line) {
                            $this->line('  ' . $line);
                        }
                    }
                    continue;
                }

                $updates[] = [
                    'id' => $productId,
                    'weight' => $weight,
                ];
            }

            $toUpdate = count($updates);
            $this->info("Productos con peso resuelto: {$toUpdate} | sin peso={$withoutWeight} | inválidos={$invalid}");

            if ($toUpdate === 0 || $dryRun) {
                if ($dryRun) {
                    $this->warn('Dry-run activo: no se ha actualizado WooCommerce.');
                }
                return self::SUCCESS;
            }

            /** @var WooCommerceClient $woo */
            $woo = app(WooCommerceClient::class);
            $updated = 0;

            foreach (array_chunk($updates, $batchSize) as $index => $chunk) {
                $chunkNumber = $index + 1;
                $this->line("Actualizando batch {$chunkNumber} (" . count($chunk) . ' productos)...');
                $woo->updateProductosBatch($chunk, false);
                $updated += count($chunk);
            }

            $this->info("Actualización completada. Productos actualizados: {$updated}");
            Log::info($marker . ' done', [
                'products_total' => $total,
                'updates' => $updated,
                'without_weight' => $withoutWeight,
                'invalid' => $invalid,
            ]);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            Log::error($marker . ' failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }
    }

    /**
     * @return array<int,string>
     */
    private function fetchCategoryWeights(): array
    {
        $rows = $this->getJson(self::CATEGORY_WEIGHTS_URL);
        if (!array_is_list($rows)) {
            throw new RuntimeException('Respuesta inesperada de category-weights.');
        }

        $weights = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $slug = strtolower(trim((string) ($row['slug'] ?? '')));
            if ($slug === 'descatalogados') {
                continue;
            }

            $categoryId = (int) ($row['category_id'] ?? 0);
            if ($categoryId > 0) {
                $this->categoryRowsById[$categoryId] = $row;
            }

            $weight = $this->normalizeWeight($row['weight'] ?? null);
            if ($categoryId <= 0 || $weight === null) {
                continue;
            }

            $weights[$categoryId] = $weight;
        }

        $this->info('Pesos de categoría configurados: ' . count($weights));

        return $weights;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchZeroWeightProducts(): array
    {
        $json = $this->getJson(self::PRODUCTS_ZERO_WEIGHT_URL);
        $items = $json['items'] ?? null;

        if (!is_array($json) || !is_array($items) || !array_is_list($items)) {
            throw new RuntimeException('Respuesta inesperada de products-zero-weight.');
        }

        return $items;
    }

    /**
     * @return array<mixed>
     */
    private function getJson(string $url): array
    {
        $response = Http::timeout(60)
            ->connectTimeout(10)
            ->retry(3, 250)
            ->acceptJson()
            ->get($url);

        if (!$response->successful()) {
            throw new RuntimeException("GET {$url} falló con HTTP {$response->status()}: " . trim($response->body()));
        }

        $json = $response->json();
        if (!is_array($json)) {
            throw new RuntimeException("GET {$url} no devolvió JSON válido.");
        }

        return $json;
    }

    /**
     * @param array<string,mixed> $product
     * @param array<int,string> $categoryWeights
     */
    private function resolveProductWeight(array $product, array $categoryWeights): ?string
    {
        $categories = $product['categories'] ?? [];
        if (!is_array($categories)) {
            return null;
        }

        $orderedCategories = [];
        foreach ($categories as $category) {
            if (!is_array($category)) {
                continue;
            }

            $slug = strtolower(trim((string) ($category['slug'] ?? '')));
            if ($slug === 'descatalogados') {
                continue;
            }

            $categoryId = (int) ($category['category_id'] ?? 0);
            if ($categoryId <= 0) {
                continue;
            }

            $hasParent = ($category['parent_id'] ?? null) !== null && (int) ($category['parent_id'] ?? 0) > 0;
            $orderedCategories[] = [
                'category_id' => $categoryId,
                'priority' => $hasParent ? 0 : 1,
            ];
        }

        usort($orderedCategories, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        foreach ($orderedCategories as $category) {
            $categoryId = (int) $category['category_id'];
            if (isset($categoryWeights[$categoryId])) {
                return $categoryWeights[$categoryId];
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $product
     * @return array<int,string>
     */
    private function describeProductCategories(array $product): array
    {
        $categories = $product['categories'] ?? [];
        if (!is_array($categories)) {
            return ['categorías inválidas'];
        }

        $lines = [];
        foreach ($categories as $category) {
            if (!is_array($category)) {
                continue;
            }

            $categoryId = (int) ($category['category_id'] ?? 0);
            $row = $this->categoryRowsById[$categoryId] ?? [];
            $rawWeight = array_key_exists('weight', $row) ? trim((string) $row['weight']) : null;
            $normalizedWeight = $this->normalizeWeight($rawWeight);

            $lines[] = 'cat_id=' . $categoryId
                . ' slug=' . trim((string) ($category['slug'] ?? ''))
                . ' parent_id=' . (($category['parent_id'] ?? null) === null ? 'null' : (string) $category['parent_id'])
                . ' weight=' . ($rawWeight === null ? 'NO_EXISTE_EN_ENDPOINT' : ($rawWeight === '' ? 'VACIO' : $rawWeight))
                . ($normalizedWeight === null ? ' (no aplicable)' : ' (aplicable)');
        }

        return $lines;
    }

    private function normalizeWeight(mixed $value): ?string
    {
        $weight = trim((string) $value);
        if ($weight === '') {
            return null;
        }

        $weight = str_replace(',', '.', $weight);
        if (!is_numeric($weight) || (float) $weight <= 0.0) {
            return null;
        }

        return $weight;
    }
}
