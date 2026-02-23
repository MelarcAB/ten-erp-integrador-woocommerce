<?php

namespace App\Console\Commands;

use App\Integrations\TenClient;
use App\Models\Producto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class TestWCLinkCategoriesProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test-w-c-link-categories-products
        {--limit=0 : Máximo de productos a procesar (0 = todos)}
        {--chunk=200 : Tamaño de chunk para recorrer}
        {--dry-run : No guarda cambios}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rellena productos.categoria_ten_id consultando la categoría en TEN (tblProductos.Categoria) para productos con categoria_ten_id = NULL';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $marker = '[TEN_LINK_CATEGORIA_PRODUCTO v1]';
        $client = app(TenClient::class);

        $limit = (int) $this->option('limit');
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $q = Producto::query()
            ->whereNull('categoria_ten_id')
            ->whereNotNull('ten_id')
            ->orderBy('id');

        if ($limit > 0) {
            $q->limit($limit);
        }

        $total = (clone $q)->count();

        $this->info("Pendientes: {$total} (chunk={$chunk})" . ($dryRun ? ' [DRY RUN]' : ''));
        Log::info($marker . ' start', ['pending' => $total, 'chunk' => $chunk, 'limit' => $limit, 'dry_run' => $dryRun]);

        if ($total === 0) {
            return self::SUCCESS;
        }

        $updated = 0;
        $notFound = 0;
        $errors = 0;

        $q->chunkById($chunk, function ($productos) use ($client, $dryRun, $marker, &$updated, &$notFound, &$errors) {
            foreach ($productos as $producto) {
                try {
                    $tenId = (int) $producto->ten_id;
                    if ($tenId <= 0) {
                        continue;
                    }

                    $categoria = $client->getCategoryFromProduct($tenId);

                    if ($categoria === null) {
                        $notFound++;
                        $this->line("TEN sin categoría para producto id={$producto->id} ten_id={$tenId}");
                        continue;
                    }

                    if ($dryRun) {
                        $updated++;
                        $this->line("DRY: set categoria_ten_id={$categoria} en producto id={$producto->id} ten_id={$tenId}");
                        continue;
                    }

                    $producto->categoria_ten_id = $categoria;
                    $producto->save();
                    $updated++;

                    $this->line("OK producto id={$producto->id} ten_id={$tenId} -> categoria_ten_id={$categoria}");
                } catch (Throwable $e) {
                    $errors++;
                    $this->error("Error en producto id={$producto->id} ten_id={$producto->ten_id}: {$e->getMessage()}");
                    Log::error($marker . ' failed', [
                        'producto_id' => $producto->id,
                        'ten_id' => $producto->ten_id,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        });

        $this->info("Resumen: updated={$updated} | not_found={$notFound} | errors={$errors}");
        Log::info($marker . ' done', compact('updated', 'notFound', 'errors'));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
