<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OsMargem extends Model
{
    protected $table = 'os_margem';

    protected $guarded = [];

    protected $casts = [
        'os_id' => 'integer',
        'receita_liquida' => 'float',
        'custo_pecas' => 'float',
        'custo_comissao' => 'float',
        'margem_contribuicao' => 'float',
        'percentual_margem' => 'float',
        'calculado_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'os_id', 'id');
    }
}
