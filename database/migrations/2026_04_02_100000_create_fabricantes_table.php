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
        Schema::create('fabricantes', function (Blueprint $table) {
            $table->id();

            // Mapeo TEN
            $table->unsignedBigInteger('ten_id_numero')->unique();
            $table->string('ten_nombre');

            // Mapeo Woo (marca)
            $table->unsignedBigInteger('woocommerce_marca_id')->nullable()->index();
            $table->string('woocommerce_marca_nombre')->nullable();

            // Control sync
            $table->string('sync_status', 20)->default('pending')->index(); // pending|synced|error|disabled
            $table->text('last_error')->nullable();
            $table->timestamp('ten_last_fetched_at')->nullable();
            $table->string('ten_hash', 64)->nullable()->index();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fabricantes');
    }
};

