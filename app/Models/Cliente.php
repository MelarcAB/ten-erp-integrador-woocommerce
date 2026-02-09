<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class Cliente extends Model
{
    use SoftDeletes;

    protected $table = 'clientes';

    protected $fillable = [
        // Identificadores / mapeo
        'ten_id',
        'ten_codigo',
        'woocommerce_id',

        // Control sync
        'sync_status',
        'last_error',

        // Datos TEN
        'email',
        'nombre',
        'apellidos',
        'nombre_fiscal',
        'nif',
        'ten_id_direccion_envio',
        'ten_id_grupo_clientes',
        'ten_regimen_impuesto',
        'ten_persona',
        'ten_id_tarifa',
        'ten_vendedor',
        'ten_forma_pago',
        'telefono',
        'telefono2',
        'web',
        'ten_calculo_iva_factura',
        'ten_enviar_emails',
        'ten_consentimiento_datos',

        // Trazabilidad
        'ten_last_fetched_at',
        'ten_hash',
    ];

    protected $casts = [
        'ten_persona'              => 'boolean',
        'ten_enviar_emails'        => 'boolean',
        'ten_consentimiento_datos' => 'boolean',
        'ten_last_fetched_at'      => 'datetime',
    ];

    public function needsWooSync(): bool
    {
        return $this->sync_status === 'pending';
    }

    public function markSynced(): void
    {
        $this->sync_status = 'synced';
        $this->last_error = null;
        $this->save();
    }

    public function markError(string $error): void
    {
        $this->sync_status = 'error';
        $this->last_error = $error;
        $this->save();
    }

}
