<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cliente_direcciones', function (Blueprint $table) {
            $table->id();

            // --- Relaciones (SIN FK) ---
            $table->unsignedBigInteger('cliente_id')->nullable()->index();
            $table->unsignedBigInteger('woocommerce_customer_id')->nullable()->index();

            // billing | shipping
            $table->string('tipo', 20)->index();

            // --- Control sync ---
            $table->string('sync_status', 20)->default('pending')->index();
            $table->text('last_error')->nullable();

            // --- Datos Woo ---
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('company')->nullable();
            $table->string('address_1')->nullable();
            $table->string('address_2')->nullable();
            $table->string('city')->nullable();
            $table->string('postcode')->nullable();
            $table->string('state')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            // --- Datos TEN (todo nullable) ---
            $table->string('ten_codigo')->nullable()->index();
            $table->bigInteger('ten_id_ten')->nullable()->index();
            $table->string('ten_nombre')->nullable();
            $table->string('ten_apellidos')->nullable();
            $table->string('ten_direccion')->nullable();
            $table->string('ten_direccion2')->nullable();
            $table->string('ten_codigo_postal')->nullable();
            $table->string('ten_poblacion')->nullable();
            $table->string('ten_provincia')->nullable();
            $table->string('ten_pais')->nullable();
            $table->string('ten_telefono')->nullable();
            $table->string('ten_fax')->nullable();
            $table->json('ten_aditional_data')->nullable();

            // --- Trazabilidad ---
            $table->timestamp('ten_last_fetched_at')->nullable();
            $table->string('ten_hash', 64)->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            // índices útiles, sin uniques
            $table->index(['cliente_id', 'tipo']);
            $table->index(['woocommerce_customer_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cliente_direcciones');
    }
};
