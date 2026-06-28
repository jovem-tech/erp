<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetItem extends Model
{
    protected $table = 'orcamento_itens';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'orcamento_id' => 'integer',
        'referencia_id' => 'integer',
        'quantidade' => 'float',
        'valor_unitario' => 'float',
        'desconto' => 'float',
        'acrescimo' => 'float',
        'total' => 'float',
        'ordem' => 'integer',
        'preco_custo_referencia' => 'float',
        'preco_venda_referencia' => 'float',
        'preco_base' => 'float',
        'percentual_encargos' => 'float',
        'valor_encargos' => 'float',
        'percentual_margem' => 'float',
        'valor_margem' => 'float',
        'valor_recomendado' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'orcamento_id', 'id');
    }
}
