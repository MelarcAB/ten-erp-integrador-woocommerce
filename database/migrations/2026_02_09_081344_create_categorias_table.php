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
        Schema::create('categorias', function (Blueprint $table) {
          
            // --- Identificadores / mapeo ---
            $table->unsignedBigInteger('ten_id_numero')->nullable()->index();           // TEN: IdNumero (sin UNIQUE)
            $table->string('ten_codigo')->nullable()->index();                          // TEN: Codigo

            $table->unsignedBigInteger('woocommerce_categoria_id')->nullable()->index();        // Woo: term_id
            $table->unsignedBigInteger('woocommerce_categoria_padre_id')->nullable()->index();  // Woo: parent term_id

            // --- Control sync ---
            $table->string('sync_status', 20)->default('pending')->index();             // pending|synced|error|disabled
            $table->boolean('enable_sync')->default(false)->index();                    // control manual

            // --- Datos TEN ---
            $table->string('ten_nombre')->nullable();                                   // TEN: Nombre
            $table->string('ten_web_nombre')->nullable();                               // TEN: WebNombre
            $table->unsignedBigInteger('ten_categoria_padre')->nullable()->index();     // TEN: CategoriaPadre

            $table->unsignedBigInteger('ten_ultimo_usuario')->nullable();               // TEN: tenUltimoUsuario
            $table->timestamp('ten_ultimo_cambio')->nullable();                         // TEN: tenUltimoCambio
            $table->unsignedBigInteger('ten_alta_usuario')->nullable();                 // TEN: tenAltaUsuario
            $table->timestamp('ten_alta_fecha')->nullable();                            // TEN: tenAltaFecha

            $table->boolean('ten_web_sincronizar')->default(false);                     // TEN: WebSincronizar (0/1)
            $table->boolean('ten_bloqueado')->default(false);                           // TEN: tenBloqueado (0/1)

            $table->decimal('ten_usr_peso', 18, 9)->nullable();                         // TEN: USR_Peso

            // --- Trazabilidad / cambios ---
            $table->timestamp('ten_last_fetched_at')->nullable();
            $table->string('ten_hash', 64)->nullable()->index();
            $table->text('last_error')->nullable();

            $table->timestamps();
            $table->softDeletes();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categorias');
    }
};
