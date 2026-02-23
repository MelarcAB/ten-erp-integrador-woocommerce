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

        $this->syncProducts();
    }

    private function syncCategorias()
    {
        $this->info('Sincronizando categorías (import y sync Woo)...');
        $exitImport = $this->call('app:prod-import-categories');
        if ($exitImport === 0) {
            $this->info('Importación de categorías completada. Ahora sincronizando con Woo...');
            $exitSync = $this->call('app:prod-sync-categorias');
            if ($exitSync === 0) {
                $this->info('Categorías Woo sincronizadas correctamente.');
            } else {
                $this->error('Error al sincronizar categorías Woo.');
            }
        } else {
            $this->error('Error al importar categorías. No se ejecuta la sincronización Woo.');
        }
    }

    private function syncProducts()
    {
        $this->info('Sincronizando productos (import y sync Woo)...');
        //pasar el argumento --modified-after=all
        $exitImport = $this->call('app:prod-import-productos', ['--modified-after' => 'all']);
       //una vez finalizada la importacion, si o si ejecuto la sincronizacion, ya que reassigna categorias
       $this->info('Finalizada la importación. Ahora sincronizando productos + validando categorias de producto');
       $exitSync = $this->call('app:prod-sync-productos');
       if ($exitSync === 0) {
           $this->info('Productos Woo sincronizados correctamente.');
       } else {
           $this->error('Error al sincronizar productos Woo.'); 
       }
    }
}
