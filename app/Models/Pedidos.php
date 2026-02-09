<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pedidos extends Model
{
    use SoftDeletes;

    protected $table = 'pedidos';

    protected $fillable = [
        'woocommerce_id',
        'woocommerce_parent_id',
        'woocommerce_number',
        'woocommerce_order_key',

        'woocommerce_customer_id',
        'cliente_id',
        'direccion_1_id',
        'direccion_2_id',

        'status',
        'sync_status',
        'last_error',

        'currency',
        'prices_include_tax',
        'discount_total',
        'discount_tax',
        'shipping_total',
        'shipping_tax',
        'cart_tax',
        'total',
        'total_tax',

        'payment_method',
        'payment_method_title',
        'transaction_id',
        'customer_ip_address',
        'customer_user_agent',
        'created_via',
        'customer_note',

        'wc_date_created',
        'wc_date_modified',
        'wc_date_completed',
        'wc_date_paid',

        'billing',
        'shipping',
        'meta_data',
        'cart_hash',
        'payment_url',

        'ten_codigo',
        'ten_id',
        'ten_last_fetched_at',
        'ten_hash',
    ];

    protected $casts = [
        'prices_include_tax' => 'boolean',

        'discount_total' => 'decimal:9',
        'discount_tax' => 'decimal:9',
        'shipping_total' => 'decimal:9',
        'shipping_tax' => 'decimal:9',
        'cart_tax' => 'decimal:9',
        'total' => 'decimal:9',
        'total_tax' => 'decimal:9',

        'wc_date_created' => 'datetime',
        'wc_date_modified' => 'datetime',
        'wc_date_completed' => 'datetime',
        'wc_date_paid' => 'datetime',

        'billing' => 'array',
        'shipping' => 'array',
        'meta_data' => 'array',

        'ten_last_fetched_at' => 'datetime',
    ];

    public function lineas()
    {
        return $this->hasMany(PedidoLineas::class, 'pedido_id');
    }
}
