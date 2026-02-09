<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PedidoLineas extends Model
{
    use SoftDeletes;

    protected $table = 'pedido_lineas';

    protected $fillable = [
        'pedido_id',

        'woocommerce_line_item_id',
        'woocommerce_order_id',

        'woocommerce_product_id',
        'woocommerce_variation_id',
        'sku',
        'producto_id',

        'name',
        'quantity',
        'tax_class',
        'subtotal',
        'subtotal_tax',
        'total',
        'total_tax',

        'global_unique_id',
        'price',
        'image_id',
        'image_src',

        'taxes',
        'meta_data',

        'ten_codigo',
        'ten_id',

        'sync_status',
        'last_error',
        'ten_last_fetched_at',
        'ten_hash',
    ];

    protected $casts = [
        'quantity' => 'integer',

        'subtotal' => 'decimal:9',
        'subtotal_tax' => 'decimal:9',
        'total' => 'decimal:9',
        'total_tax' => 'decimal:9',
        'price' => 'decimal:9',

        'taxes' => 'array',
        'meta_data' => 'array',

        'ten_last_fetched_at' => 'datetime',
    ];

    public function pedido()
    {
        return $this->belongsTo(Pedidos::class, 'pedido_id');
    }
}
