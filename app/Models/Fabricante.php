<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Fabricante extends Model
{
    use SoftDeletes;

    protected $table = 'fabricantes';

    protected $fillable = [
        'ten_id_numero',
        'ten_nombre',
        'woocommerce_marca_id',
        'woocommerce_marca_nombre',
        'sync_status',
        'last_error',
        'ten_last_fetched_at',
        'ten_hash',
    ];

    protected $casts = [
        'ten_last_fetched_at' => 'datetime',
    ];

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

