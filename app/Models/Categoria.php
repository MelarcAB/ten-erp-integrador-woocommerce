<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Categoria extends Model
{
    use SoftDeletes;

    protected $table = 'categorias';

    /**
     * La tabla categorias NO tiene columna id.
     * Usamos ten_id_numero como clave primaria natural.
     */
    protected $primaryKey = 'ten_id_numero';
    public $incrementing = false;
    protected $keyType = 'int';

    /**
     * Campos asignables en mass-assignment
     */
    protected $fillable = [
        // Identificadores / mapeo
        'ten_id_numero',
        'ten_codigo',
        'woocommerce_categoria_id',
        'woocommerce_categoria_padre_id',

        // Control sync
        'sync_status',
        'enable_sync',

        // Datos TEN
        'ten_nombre',
        'ten_web_nombre',
        'ten_categoria_padre',
        'ten_ultimo_usuario',
        'ten_ultimo_cambio',
        'ten_alta_usuario',
        'ten_alta_fecha',
        'ten_web_sincronizar',
        'ten_bloqueado',
        'ten_usr_peso',

        // Trazabilidad
        'ten_last_fetched_at',
        'ten_hash',
        'last_error',
    ];

    /**
     * Casts de tipos
     */
    protected $casts = [
        'enable_sync'           => 'boolean',
        'ten_web_sincronizar'   => 'boolean',
        'ten_bloqueado'         => 'boolean',

        'ten_usr_peso'          => 'decimal:9',

        'ten_ultimo_cambio'     => 'datetime',
        'ten_alta_fecha'        => 'datetime',
        'ten_last_fetched_at'   => 'datetime',
    ];

    /**
     * Relaciones internas (árbol TEN)
     */
    public function padreTen()
    {
        return $this->belongsTo(self::class, 'ten_categoria_padre', 'ten_id_numero');
    }

    public function hijasTen()
    {
        return $this->hasMany(self::class, 'ten_categoria_padre', 'ten_id_numero');
    }

    /**
     * Helpers de estado
     */
    public function needsWooSync(): bool
    {
        return $this->enable_sync && $this->sync_status === 'pending';
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
