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
        $only = (string) $this->option('only');

        if (!in_array($mode, ['full', 'import', 'sync'], true)) {
            $this->error('Valor inválido para --mode. Usa: full|import|sync');
            return self::FAILURE;
        }

        if ($mode === 'full' || $mode === 'import') {
            $this->info('StartFullSync: importaciones (TEN -> APP)');

            // IMPORTS: Categorías, Productos, Stocks, Clientes, Pedidos
            $imports = [
                ['cmd' => 'app:test-ten-categories-import', 'args' => ['--dry-run' => $dryRun]],
                ['cmd' => 'app:test-ten-products-import', 'args' => ['--dry-run' => $dryRun]],
                // Stock TEN -> APP (tabla productos.stock)
                ['cmd' => 'app:test-w-c-sync-stock', 'args' => ['--dry-run' => $dryRun, '--limit' => $limit]],
                ['cmd' => 'app:test-wc-customers-import', 'args' => ['--dry-run' => $dryRun]],
                ['cmd' => 'app:test-wc-pedidos-import', 'args' => ['--dry-run' => $dryRun]],
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
                ['cmd' => 'app:test-wc-sync-categories', 'args' => ['--only' => $only, '--limit' => $limit, '--dry-run' => $dryRun]],
                ['cmd' => 'app:test-wc-sync-products', 'args' => ['--only' => $only, '--limit' => $limit, '--dry-run' => $dryRun]],
                // Stock hacia Woo: update de productos existentes
                ['cmd' => 'app:test-wc-sync-products', 'args' => ['--sync-stock' => true, '--limit' => $limit, '--dry-run' => $dryRun]],
                // Si en el futuro hay comandos de sync para clientes/pedidos, añádelos aquí.
            ];

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
