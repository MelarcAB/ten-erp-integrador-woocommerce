<?php

namespace App\Console\Commands;

use App\Integrations\WooCommerceClient;
use App\Models\Categoria;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProdDiffWooCategorias extends Command
{
    protected $signature = 'app:prod-diff-categorias-woo
        {--per-page=100 : Items por página al leer categorías de Woo}
        {--max-pages=0 : Máximo de páginas a leer (0 = sin límite)}
    ';

    protected $description = 'Muestra categorías existentes en WooCommerce que no están mapeadas en la base de datos local.';

    public function handle(): int
    {
        $marker = '[PROD_DIFF_WOO_CATEGORIAS v1]';
        $this->line($marker . ' start');
        Log::info($marker . ' start');

        $perPage = max(1, min(100, (int) $this->option('per-page')));
        $maxPages = max(0, (int) $this->option('max-pages'));

        /** @var WooCommerceClient $woo */
        $woo = app(WooCommerceClient::class);

        $wooCategories = [];
        $page = 1;
        $pagesDone = 0;

        while (true) {
            if ($maxPages > 0 && $pagesDone >= $maxPages) {
                break;
            }

            try {
                $rows = $woo->getCategoriasProductos($perPage, $page, ['_fields' => 'id,name']);
            } catch (Throwable $e) {
                $this->error('Error al leer categorías de Woo: ' . $e->getMessage());
                Log::error($marker . ' woo categories read failed', [
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);
                return self::FAILURE;
            }

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $name = trim((string) ($row['name'] ?? ''));
                $wooCategories[$id] = $name;
            }

            $page++;
            $pagesDone++;
        }

        $wooIds = array_keys($wooCategories);
        $this->info('Categorías Woo leídas: ' . count($wooIds));
        if (empty($wooIds)) {
            $this->info('No hay categorías en WooCommerce.');
            return self::SUCCESS;
        }

        $dbWooIds = Categoria::query()
            ->whereNotNull('woocommerce_categoria_id')
            ->pluck('woocommerce_categoria_id')
            ->map(static fn ($v) => (int) $v)
            ->filter(static fn ($v) => $v > 0)
            ->unique()
            ->values()
            ->all();
        $dbWooIdSet = array_fill_keys($dbWooIds, true);

        $missing = [];
        foreach ($wooCategories as $id => $name) {
            if (!isset($dbWooIdSet[$id])) {
                $missing[$id] = $name;
            }
        }
        ksort($missing);

        $this->line('--- Categorías en Woo que NO están en BD local ---');
        foreach ($missing as $id => $name) {
            $this->line("WooID={$id} | Nombre={$name}");
        }

        $this->info('Total faltantes: ' . count($missing));
        Log::info($marker . ' done', [
            'woo_total' => count($wooIds),
            'db_mapped_total' => count($dbWooIds),
            'missing_total' => count($missing),
        ]);

        return self::SUCCESS;
    }
}

