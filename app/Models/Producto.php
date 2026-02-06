<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Producto extends Model
{
    use SoftDeletes;

    protected $table = 'productos';

    /**
     * Si quieres protegerte de mass assignment,
     * lo más cómodo aquí es usar $guarded = [] en un proyecto interno.
     * Si prefieres estricto, cambia a $fillable.
     */
    protected $guarded = [];

    protected $casts = [
        'ten_web_control_stock' => 'boolean',
        'ten_bloqueado'         => 'boolean',
        'ten_last_fetched_at'   => 'datetime',

        'ten_precio'            => 'decimal:9',
        'ten_peso'              => 'decimal:9',
        'ten_porc_impost'       => 'decimal:9',
        'ten_porc_recargo'      => 'decimal:9',
    ];

    /**
     * Scopes útiles para sync
     */
    public function scopePendientes($query)
    {
        return $query->where('sync_status', 'pending');
    }

    public function scopeConError($query)
    {
        return $query->where('sync_status', 'error');
    }

    public function scopeSincronizados($query)
    {
        return $query->where('sync_status', 'synced');
    }

    /**
     * Helpers de búsqueda típicos (TEN / Woo)
     */
    public function scopeByTenId($query, int|string $tenId)
    {
        return $query->where('ten_id', (string)$tenId);
    }

    public function scopeByWooId($query, int|string $wooId)
    {
        return $query->where('woocommerce_id', (string)$wooId);
    }

    public function scopeBySku($query, string $sku)
    {
        return $query->where('woocommerce_sku', $sku)
            ->orWhere('ten_codigo', $sku)
            ->orWhere('ten_referencia', $sku);
    }

    /**
     * Normalmente en TEN "Bloqueado=1" significa NO vendible/sincronizable.
     */
    public function getEstaBloqueadoAttribute(): bool
    {
        return (bool) $this->ten_bloqueado;
    }

    public function getNombreAttribute(): ?string
    {
        return $this->ten_web_nombre;
    }

    /**
     * Para decidir un identificador "principal" de producto cuando hay varios.
     */
    public function getIdentificadorAttribute(): ?string
    {
        return $this->ten_codigo
            ?? $this->woocommerce_sku
            ?? ($this->ten_id ? ('TEN#' . $this->ten_id) : null)
            ?? ($this->woocommerce_id ? ('WOO#' . $this->woocommerce_id) : null);
    }
}
