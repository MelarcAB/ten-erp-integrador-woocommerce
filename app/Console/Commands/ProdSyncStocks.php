<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Integrations\TenClient;
use App\Models\Producto;
class ProdSyncStocks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:prod-sync-stocks';

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

        $this->info('Iniciando sincronización de stocks con TenClient...');
        $tenClient = new TenClient();
        $stocks = $tenClient->getStocks();
        foreach ($stocks as $stock) {
            $stockNumberToInt = isset($stock['Stock']) ? (int)$stock['Stock'] : null;
            $this->info("Producto ID: {$stock['IdProducto']}, Stock: {$stockNumberToInt}");
            //buscar el producto segun el campo ten_id y actualizar su stock
            $prod = Producto::where('ten_id', $stock['IdProducto'])->first();
            if ($prod) {
                // Antes de guardar, aseguramos que el stock nunca sea negativo
                $prod->stock = max(0, $stockNumberToInt);
                $prod->ten_web_control_stock = true;
                $prod->save();
                $this->info("Producto ID: {$prod->id} actualizado con stock: {$prod->stock}");

                // Actualizar también en WooCommerce si el producto está enlazado
                if ($prod->woocommerce_id) {
                    $wooClient = app(\App\Integrations\WooCommerceClient::class);
                    try {
                        $wooClient->updateProducto((int)$prod->woocommerce_id, [
                            'manage_stock' => true,
                            'stock_quantity' => $prod->stock,
                        ]);
                        $this->info("Stock actualizado en Woo para producto WooID: {$prod->woocommerce_id}");
                    } catch (\Throwable $e) {
                        $this->warn("Error actualizando stock en Woo para producto WooID: {$prod->woocommerce_id}: " . $e->getMessage());
                    }
                }
            } else {
                $this->warn("Producto con ten_id: {$stock['IdProducto']} no encontrado en la base de datos.");
            }

        }

        $this->info('Sincronización de stocks completada.');
    }
}
