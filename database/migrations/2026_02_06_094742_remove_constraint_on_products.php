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
        Schema::table('productos', function (Blueprint $table) {
            // OJO: el nombre del índice suele ser "productos_woocommerce_id_unique"
            // Si en tu DB tiene otro nombre, ajusta el string.
            $table->dropUnique('productos_woocommerce_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
