<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Direcciones extends Model
{
    use SoftDeletes;

    protected $table = 'cliente_direcciones';

    protected $fillable = [
        'cliente_id',
        'woocommerce_customer_id',
        'tipo',

        'sync_status',
        'last_error',

        // Woo
        'first_name',
        'last_name',
        'company',
        'address_1',
        'address_2',
        'city',
        'postcode',
        'state',
        'country',
        'email',
        'phone',

        // TEN
        'ten_codigo',
        'ten_id_ten',
        'ten_nombre',
        'ten_apellidos',
        'ten_direccion',
        'ten_direccion2',
        'ten_codigo_postal',
        'ten_poblacion',
        'ten_provincia',
        'ten_pais',
        'ten_telefono',
        'ten_fax',
        'ten_aditional_data',

        'ten_last_fetched_at',
        'ten_hash',
    ];

    protected $casts = [
        'ten_last_fetched_at' => 'datetime',
        'ten_aditional_data' => 'array',
    ];
}
