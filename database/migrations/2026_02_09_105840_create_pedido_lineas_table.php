<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_lineas', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Relación interna (sin foreign key para simplificar)
            $table->unsignedBigInteger('pedido_id')->index();

            // --- Identificadores Woo ---
            $table->unsignedBigInteger('woocommerce_line_item_id')->nullable()->index(); // line_items[].id
            $table->unsignedBigInteger('woocommerce_order_id')->nullable()->index();    // redundante pero útil para debug

            // --- Producto Woo (y posible mapeo interno) ---
            $table->unsignedBigInteger('woocommerce_product_id')->nullable()->index();
            $table->unsignedBigInteger('woocommerce_variation_id')->nullable()->index();
            $table->string('sku')->nullable()->index();

            // Si ya tienes tabla productos, puedes guardar el id interno también
            $table->unsignedBigInteger('producto_id')->nullable()->index(); // productos.id (si existe)

            // --- Datos de línea ---
            $table->string('name')->nullable();
            $table->unsignedInteger('quantity')->default(0);

            $table->string('tax_class')->nullable();
            $table->decimal('subtotal', 18, 9)->nullable();
            $table->decimal('subtotal_tax', 18, 9)->nullable();
            $table->decimal('total', 18, 9)->nullable();
            $table->decimal('total_tax', 18, 9)->nullable();

            // Extras
            $table->string('global_unique_id')->nullable()->index();
            $table->decimal('price', 18, 9)->nullable();
            $table->unsignedBigInteger('image_id')->nullable();
            $table->text('image_src')->nullable();

            $table->json('taxes')->nullable();
            $table->json('meta_data')->nullable();

            // --- TEN (si luego exportas líneas a TEN) ---
            $table->string('ten_codigo')->nullable()->index();
            $table->unsignedBigInteger('ten_id')->nullable()->index();

            // --- Sync ---
            $table->string('sync_status', 20)->default('pending')->index();
            $table->text('last_error')->nullable();
            $table->timestamp('ten_last_fetched_at')->nullable();
            $table->string('ten_hash', 64)->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            // Sin UNIQUEs por tu petición.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_lineas');
    }
};
