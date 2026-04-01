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
        if (!Schema::hasColumn('productos', 'categoria_ten_codigo')) {
            return;
        }

        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn('categoria_ten_codigo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('productos', 'categoria_ten_codigo')) {
            return;
        }

        Schema::table('productos', function (Blueprint $table) {
            $table->string('categoria_ten_codigo')->nullable()->index();
        });
    }
};
