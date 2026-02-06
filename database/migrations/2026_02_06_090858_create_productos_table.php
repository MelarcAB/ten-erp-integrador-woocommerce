<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();

            // --- Identificadores / mapeo ---
            $table->unsignedBigInteger('ten_id')->nullable()->unique();            // TEN: Id
            $table->string('ten_codigo')->nullable()->index();                     // TEN: Codigo
            $table->unsignedBigInteger('woocommerce_id')->nullable()->unique();    // Woo: product/variation id (según tu estrategia)
            $table->string('woocommerce_sku')->nullable()->index();                // Woo: SKU (si lo tienes)

            //ean
            $table->string('ten_ean')->nullable()->index();                          // TEN: EAN (si lo tienes)
            $table->string('ten_upc')->nullable()->index();                          // TEN: UPC (si lo tienes)
            $table->string('woocommerce_ean')->nullable()->index();                  // Woo: EAN (si lo tienes)
            $table->string('woocommerce_upc')->nullable()->index();                  // Woo: UPC (si lo tienes)


            // --- Datos TEN (lo que realmente devuelve el ERP) ---
            $table->unsignedBigInteger('ten_id_grupo_productos')->nullable();      // TEN: IdGrupoProductos
            $table->string('ten_web_nombre')->nullable();                          // TEN: Web-Nombre
            $table->text('ten_web_descripcion_corta')->nullable();                 // TEN: Web-DescripcionCorta
            $table->longText('ten_web_descripcion_larga')->nullable();             // TEN: Web-DescripcionLarga
            $table->boolean('ten_web_control_stock')->default(false);              // TEN: Web-ControlStock (0/1)

            $table->decimal('ten_precio', 18, 9)->nullable();                      // TEN: Precio
            $table->boolean('ten_bloqueado')->default(false);                      // TEN: Bloqueado (0/1)

            $table->unsignedBigInteger('ten_fabricante')->nullable();              // TEN: Fabricante
            $table->string('ten_referencia')->nullable();                          // TEN: Referencia
            $table->string('ten_catalogo')->nullable();                            // TEN: Catalogo
            $table->integer('ten_prioridad')->default(0);                          // TEN: Prioridad
            $table->string('ten_fraccionar_formato_venta')->nullable();            // TEN: FraccionarFormatoVenta (parece enum/string)
            $table->decimal('ten_peso', 18, 9)->nullable();                        // TEN: Peso
            $table->decimal('ten_porc_impost', 18, 9)->nullable();                 // TEN: PorcImpost
            $table->decimal('ten_porc_recargo', 18, 9)->nullable();                // TEN: PorcRecargo

            // --- Sync / control (solo lectura desde TEN, pero guardamos trazabilidad) ---
            $table->timestamp('ten_last_fetched_at')->nullable();                  // última vez que lo leíste de TEN
            $table->string('ten_hash', 64)->nullable()->index();                   // hash del payload para detectar cambios
            $table->string('sync_status', 20)->default('pending');                 // pending|synced|error|disabled
            $table->text('last_error')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['ten_id', 'woocommerce_id']);
            $table->index(['ten_codigo', 'woocommerce_sku']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
