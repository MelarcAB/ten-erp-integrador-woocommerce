<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class StartFullSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:start-full-sync
        {--mode=full : full|import|sync}
        {--dry-run : No escribe en DB ni llama a APIs externas en comandos que lo soporten}
        {--limit=0 : Límite (si aplica) para comandos que soporten --limit}
        {--only=pending : Para sincronizaciones: pending|error|all (si aplica)}
        {--modified-after= : (Solo import productos TEN) Fecha "YYYY-MM-DD HH:MM:SS" para /Products/Get}
        {--items=100000 : (Solo import productos TEN) Items por página}
        {--page=0 : (Solo import productos TEN) Página}
        {--full-sync : En modo full, fuerza validación y actualización completa en Woo (equivale a --only=all + forzar categorías)}
        {--force-categories : En sincronización, reasigna categorías en Woo según pivote producto_categorias_ten (fallback categoria_ten_id) para productos ya enlazados}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Orquesta importaciones (TEN->APP) y sincronizaciones (APP->Woo) en orden; permite ejecutar por separado.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $mode = (string) $this->option('mode');
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $modifiedAfter = $this->option('modified-after');
        $items = (int) $this->option('items');
        $page = (int) $this->option('page');

        if ($mode === 'full') {
            $this->info('StartFullSync: integración completa');
            $steps = [
                // 1. Exporta clientes a TEN (incluye errores)
                ['cmd' => 'app:ten-sync-customers', 'args' => ['--retry-errors' => true]],
                // 2. Importa clientes desde Woo
                ['cmd' => 'app:test-wc-customers-import', 'args' => []],
                // 3. Importa direcciones (incluye del primer pedido si no tiene)
                ['cmd' => 'app:test-wc-customer-addresses-import', 'args' => []],
                // 4. Importa pedidos y líneas
                ['cmd' => 'app:test-wc-pedidos-import', 'args' => ['--per-page' => 100, '--page' => 1, '--status' => 'any', '--modified-after' => $modifiedAfter ?: '2022-01-01T00:00:00']],
                // 5. Importa categorías y productos desde TEN
                ['cmd' => 'app:test-ten-categories-import', 'args' => []],
                ['cmd' => 'app:test-ten-products-import', 'args' => [
                    '--modified-after' => $modifiedAfter ?: '2022-01-01 00:00:00',
                    '--items' => $items,
                    '--page' => $page,
                ]],
                // 6. Importa stock desde TEN
                ['cmd' => 'app:test-w-c-sync-stock', 'args' => ['--limit' => $limit]],
                // 7. Linkea categorías-productos (rellena categoria_ten_id y pivote producto_categorias_ten)
                ['cmd' => 'app:test-wc-link-categories-products', 'args' => []],
                // 8. Sincroniza categorías a Woo
                ['cmd' => 'app:test-wc-sync-categories', 'args' => ['--only' => 'all', '--limit' => $limit]],
                // 9. Sincroniza productos a Woo
                ['cmd' => 'app:test-wc-sync-products', 'args' => ['--only' => 'all', '--limit' => $limit]],
                // 10. Fuerza categorías correctas en productos
                ['cmd' => 'app:test-wc-sync-products', 'args' => ['--force-categories' => true, '--limit' => $limit]],
                // 11. Sincroniza stock a Woo
                ['cmd' => 'app:test-wc-sync-products', 'args' => ['--sync-stock' => true, '--limit' => $limit]],
                // 12. Sincroniza pedidos a TEN
                ['cmd' => 'app:test-ten-sync-pedidos', 'args' => []],
            ];
            foreach ($steps as $step) {
                $exit = $this->runStep($step['cmd'], $this->filterArgs($step['args']));
                if ($exit !== self::SUCCESS) return $exit;
            }
            $this->info('StartFullSync: OK');
            return self::SUCCESS;
        }

        if (!in_array($mode, ['full', 'import', 'sync'], true)) {
            $this->error('Valor inválido para --mode. Usa: full|import|sync');
            return self::FAILURE;
        }

        // Si el usuario pide full-sync, en modo full siempre debemos recorrer TODO.
        // Esto evita que cambios manuales en Woo (p.ej. categorías) sobrevivan al "full".
        $effectiveOnly = ($mode === 'full' && $fullSync) ? 'all' : $only;
        $effectiveForceCategories = ($mode === 'full' && $fullSync) ? true : $forceCategories;

        if ($mode === 'full' || $mode === 'import') {
            $this->info('StartFullSync: importaciones (TEN -> APP)');

            // IMPORTS: Categorías, Productos, Stocks, Clientes, Pedidos
            $imports = [
                ['cmd' => 'app:test-ten-categories-import', 'args' => ['--dry-run' => $dryRun]],
                ['cmd' => 'app:test-ten-products-import', 'args' => [
                    '--dry-run' => $dryRun,
                    '--modified-after' => $modifiedAfter,
                    '--items' => $items,
                    '--page' => $page,
                ]],
                // Stock TEN -> APP (tabla productos.stock)
                ['cmd' => 'app:test-w-c-sync-stock', 'args' => ['--dry-run' => $dryRun, '--limit' => $limit]],
                ['cmd' => 'app:test-wc-customers-import', 'args' => ['--dry-run' => $dryRun]],
                ['cmd' => 'app:test-ten-sync-pedidos', 'args' => ['--dry-run' => $dryRun]],
            ];

            foreach ($imports as $step) {
                $exit = $this->runStep($step['cmd'], $this->filterArgs($step['args']));
                if ($exit !== self::SUCCESS) return $exit;
            }
        }

        if ($mode === 'full' || $mode === 'sync') {
            $this->info('StartFullSync: sincronizaciones (APP -> Woo)');

            // SYNCS: Categorías, Productos, Stocks
            $syncs = [
                ['cmd' => 'app:test-wc-sync-categories', 'args' => ['--only' => $effectiveOnly, '--limit' => $limit, '--dry-run' => $dryRun]],
                ['cmd' => 'app:test-wc-sync-products', 'args' => ['--only' => $effectiveOnly, '--limit' => $limit, '--dry-run' => $dryRun]],
            ];

            // Pasada específica para garantizar categorías correctas en productos ya enlazados
            // (solo si el usuario lo pide explícitamente o si full-sync=true)
            if ($effectiveForceCategories) {
                $syncs[] = ['cmd' => 'app:test-wc-sync-products', 'args' => ['--force-categories' => true, '--limit' => $limit, '--dry-run' => $dryRun]];
            }

            // Stock hacia Woo: update de productos existentes
            $syncs[] = ['cmd' => 'app:test-wc-sync-products', 'args' => ['--sync-stock' => true, '--limit' => $limit, '--dry-run' => $dryRun]];
            //sync de pedidos
         //   $syncs[] = ['cmd' => 'app:test-ten-sync-pedidos', 'args' => ['--only' => $effectiveOnly, '--limit' => $limit, '--dry-run' => $dryRun]];

            foreach ($syncs as $step) {
                $exit = $this->runStep($step['cmd'], $this->filterArgs($step['args']));
                if ($exit !== self::SUCCESS) return $exit;
            }
        }

        $this->info('StartFullSync: OK');
        return self::SUCCESS;
    }

    /**
     * Ejecuta un comando Artisan y corta si falla.
     */
    private function runStep(string $command, array $args): int
    {
        $this->line("- Ejecutando: {$command} " . ($args ? json_encode($args) : ''));

        if (!array_key_exists($command, Artisan::all())) {
            $this->warn("  (omitido) No existe el comando: {$command}");
            return self::SUCCESS;
        }

        $exit = Artisan::call($command, $args, $this->output);

        if ($exit !== self::SUCCESS) {
            $this->error("  Falló: {$command} (exit={$exit})");
        }

        return (int) $exit;
    }

    /**
     * Quita args vacíos y flags false.
     */
    private function filterArgs(array $args): array
    {
        $out = [];
        foreach ($args as $k => $v) {
            if ($v === false || $v === null || $v === '') continue;
            if ($v === true) {
                $out[$k] = true;
                continue;
            }
            // No pasar --limit=0
            if ($k === '--limit' && (int) $v === 0) continue;
            $out[$k] = $v;
        }
        return $out;
    }
}
