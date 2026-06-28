<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStatusTransition extends Model
{
    protected $table = 'os_status_transicoes';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'status_origem_id' => 'integer',
        'status_destino_id' => 'integer',
        'ativo' => 'boolean',
    ];

    public function origem(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class, 'status_origem_id', 'id');
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class, 'status_destino_id', 'id');
    }
}
