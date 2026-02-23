<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ProdFullSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:prod-full-sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->syncCategorias();
    }

    private function syncCategorias()
    {
        $this->info('Sincronizando categorías...');
        $exitCode = $this->call('app:prod-import-categories');
        if ($exitCode === 0) {
            $this->info('Categorías sincronizadas correctamente.');
        } else {
            $this->error('Error al sincronizar categorías.');
        }
    }
}
