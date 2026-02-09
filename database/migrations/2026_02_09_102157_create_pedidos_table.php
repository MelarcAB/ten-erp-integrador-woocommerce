<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $table) {
            $table->bigIncrements('id');

            // --- Identificadores Woo ---
            $table->unsignedBigInteger('woocommerce_id')->nullable()->index();      // Woo order id (34576)
            $table->unsignedBigInteger('woocommerce_parent_id')->nullable()->index();
            $table->string('woocommerce_number')->nullable()->index();              // "34576"
            $table->string('woocommerce_order_key')->nullable()->index();           // wc_order_xxx

            // --- Relación con cliente/direcciones (internas) ---
            $table->unsignedBigInteger('woocommerce_customer_id')->nullable()->index(); // customer_id (1)
            $table->unsignedBigInteger('cliente_id')->nullable()->index();              // tu id interno (si lo resuelves por woo id / email)
            $table->unsignedBigInteger('direccion_1_id')->nullable()->index();          // cliente_direcciones.id (billing)
            $table->unsignedBigInteger('direccion_2_id')->nullable()->index();          // cliente_direcciones.id (shipping)

            // --- Estado / Sync ---
            $table->string('status', 30)->nullable()->index(); // processing, completed, etc
            $table->string('sync_status', 20)->default('pending')->index(); // pending|synced|error|disabled
            $table->text('last_error')->nullable();

            // --- Totales / moneda ---
            $table->string('currency', 10)->nullable()->index(); // EUR
            $table->boolean('prices_include_tax')->default(false);

            // Totales vienen como string en Woo: guardamos en DECIMAL para querys
            $table->decimal('discount_total', 18, 9)->nullable();
            $table->decimal('discount_tax', 18, 9)->nullable();
            $table->decimal('shipping_total', 18, 9)->nullable();
            $table->decimal('shipping_tax', 18, 9)->nullable();
            $table->decimal('cart_tax', 18, 9)->nullable();
            $table->decimal('total', 18, 9)->nullable();
            $table->decimal('total_tax', 18, 9)->nullable();

            // --- Pago / tracking ---
            $table->string('payment_method', 50)->nullable();
            $table->string('payment_method_title')->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('customer_ip_address')->nullable();
            $table->text('customer_user_agent')->nullable();
            $table->string('created_via')->nullable();
            $table->text('customer_note')->nullable();

            // --- Fechas Woo (ISO) ---
            $table->timestamp('wc_date_created')->nullable()->index();
            $table->timestamp('wc_date_modified')->nullable()->index();
            $table->timestamp('wc_date_completed')->nullable();
            $table->timestamp('wc_date_paid')->nullable();

            // --- Datos raw útiles para reconstruir / debug ---
            $table->json('billing')->nullable();
            $table->json('shipping')->nullable();
            $table->json('meta_data')->nullable();
            $table->string('cart_hash')->nullable()->index();
            $table->string('payment_url')->nullable();

            // --- TEN (si luego quieres rellenar al exportar) ---
            $table->string('ten_codigo')->nullable()->index();
            $table->unsignedBigInteger('ten_id')->nullable()->index();

            // --- Trazabilidad / cambios ---
            $table->timestamp('ten_last_fetched_at')->nullable();
            $table->string('ten_hash', 64)->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            // Sin UNIQUEs por tu petición.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};
