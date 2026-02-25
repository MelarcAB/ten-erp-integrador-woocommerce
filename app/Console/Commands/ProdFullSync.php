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
        $start = microtime(true);

       // $this->syncCategorias();

      //  $this->syncProducts();

        $this->syncClientes();

      //  $this->syncStockByProveedor();

            $this->syncPedidos();


        $elapsedMs = (microtime(true) - $start) * 1000;
        $timeToMinutes = $elapsedMs / 60000;
        $this->info('Tiempo total: ' . number_format($timeToMinutes, 2, ',', '.') . ' minutos');
       // $this->info('Tiempo total: ' . number_format($elapsedMs, 0, ',', '.') . ' ms');
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

        //ahora primero llamamos app:prod-sync-stocks para los stocks
        $exitSyncStocks = $this->call('app:prod-sync-stocks');

        $this->info('Finalizada la importación. Ahora sincronizando productos + validando categorias de producto');



        $exitSync = $this->call('app:prod-sync-productos');


        //ahora validar si hay imagenes que sincronizar con app:prod-sync-img
        $this->info('Sincronizando imágenes de productos...');
        $exitSyncImg = $this->call('app:prod-sync-img');
        $this->info('Sincronización de imágenes de productos finalizada.');


        if ($exitSync === 0) {
            $this->info('Productos Woo sincronizados correctamente.');
        } else {
            $this->error('Error al sincronizar productos Woo.');
        }

        if ($exitSyncStocks === 0) {
            $this->info('Stocks de productos sincronizados correctamente.');
        } else {
            $this->error('Error al sincronizar stocks de productos.');
        }

        if ($exitSyncImg === 0) {
            $this->info('Imágenes de productos sincronizadas correctamente.');
        } else {
            $this->error('Error al sincronizar imágenes de productos.');
        }
    }

    private function syncClientes()
    {
        //app:prod-import-clients
        //app:prod-sync-clients
        $this->info('Sincronizando clientes (import y sync Woo)...');
        $exitImport = $this->call('app:prod-import-clients');
        if ($exitImport === 0) {
            $this->info('Importación de clientes completada. Ahora sincronizando con Woo...');
            $exitSync = $this->call('app:prod-sync-clients');
            if ($exitSync === 0) {
                $this->info('Clientes Woo sincronizados correctamente.');
            } else {
                $this->error('Error al sincronizar clientes Woo.');
            }
        } else {
            $this->error('Error al importar clientes. No se ejecuta la sincronización Woo.');
        }
    }

    private function syncStockByProveedor(){
        //php artisan app:prod-sync-stock-proveedores
        $this->info('Sincronizando stock por proveedor...');
        $exitSync = $this->call('app:prod-sync-stock-proveedores');
        if ($exitSync === 0) {
            $this->info('Stock por proveedor sincronizado correctamente.');
        } else {
            $this->error('Error al sincronizar stock por proveedor.');
        }




    }


    private function syncPedidos(){
        //app:prod-import-pedidos
        //y app:prod-sync-pedidos

        $this->info('Sincronizando pedidos (import y sync Woo)...');
        $exitImport = $this->call('app:prod-import-pedidos');
        if ($exitImport === 0) {
            $this->info('Importación de pedidos completada. Ahora sincronizando con Woo...');
            $exitSync = $this->call('app:prod-sync-pedidos');
            if ($exitSync === 0) {
                $this->info('Pedidos Woo sincronizados correctamente.');
            } else {
                $this->error('Error al sincronizar pedidos Woo.');
            }
        } else {
            $this->error('Error al importar pedidos. No se ejecuta la sincronización Woo.');
        }

    }
}
