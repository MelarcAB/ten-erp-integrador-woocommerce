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
        Schema::create('clientes', function (Blueprint $table) {

            // --- Identificadores / mapeo ---
            $table->unsignedBigInteger('ten_id')->nullable()->index();          // TEN: IdTen
            $table->string('ten_codigo')->nullable()->index();                 // TEN: Codigo
            $table->unsignedBigInteger('woocommerce_id')->nullable()->index(); // Woo: customer id

            // --- Control sync ---
            $table->string('sync_status', 20)->default('pending')->index();    // pending|synced|error|disabled
            $table->text('last_error')->nullable();

            // --- Datos TEN ---
            $table->string('email')->nullable()->index();                      // TEN: Email
            $table->string('nombre')->nullable();                              // TEN: Nombre
            $table->string('apellidos')->nullable();                           // TEN: Apellidos
            $table->string('nombre_fiscal')->nullable();                       // TEN: NombreFiscal
            $table->string('nif')->nullable()->index();                        // TEN: NIF

            $table->unsignedBigInteger('ten_id_direccion_envio')->nullable();  // TEN: IdDireccionEnvio
            $table->unsignedBigInteger('ten_id_grupo_clientes')->nullable();   // TEN: IdGrupoClientes
            $table->unsignedBigInteger('ten_regimen_impuesto')->nullable();    // TEN: RegimenImpuesto

            $table->boolean('ten_persona')->default(false);                    // TEN: Persona (0/1)
            $table->unsignedBigInteger('ten_id_tarifa')->nullable();           // TEN: IdTarifa

            $table->string('ten_vendedor')->nullable();                        // TEN: Vendedor
            $table->string('ten_forma_pago')->nullable();                      // TEN: FormaPago

            $table->string('telefono')->nullable();                            // TEN: Telefono
            $table->string('telefono2')->nullable();                           // TEN: Telefono2
            $table->string('web')->nullable();                                 // TEN: Web

            $table->string('ten_calculo_iva_factura')->nullable();             // TEN: CalculoIVAFactura (parece enum/string)
            $table->boolean('ten_enviar_emails')->default(false);              // TEN: EnviarEmails (0/1)
            $table->boolean('ten_consentimiento_datos')->default(false);       // TEN: ConsentimientoDatos (0/1)

            // --- Trazabilidad / cambios ---
            $table->timestamp('ten_last_fetched_at')->nullable();
            $table->string('ten_hash', 64)->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['ten_id', 'woocommerce_id']);
            $table->index(['ten_codigo', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
