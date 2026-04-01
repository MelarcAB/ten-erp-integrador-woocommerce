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
        Schema::create('producto_categorias_ten', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('producto_ten_id')->index();
            $table->unsignedBigInteger('categoria_ten_id')->index();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('is_primary')->default(false)->index();
            $table->timestamps();

            $table->unique(['producto_ten_id', 'categoria_ten_id'], 'producto_ten_categoria_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('producto_categorias_ten');
    }
};
