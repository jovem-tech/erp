<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $table = 'os_itens';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'os_id' => 'integer',
        'quantidade' => 'float',
        'valor_unitario' => 'float',
        'valor_total' => 'float',
        'preco_custo_referencia' => 'float',
        'preco_venda_referencia' => 'float',
        'created_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'os_id', 'id');
    }
}
