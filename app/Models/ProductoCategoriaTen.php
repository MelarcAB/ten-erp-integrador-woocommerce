<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoCategoriaTen extends Model
{
    protected $table = 'producto_categorias_ten';

    protected $guarded = [];

    protected $casts = [
        'producto_ten_id' => 'integer',
        'categoria_ten_id' => 'integer',
        'orden' => 'integer',
        'is_primary' => 'boolean',
    ];
}
