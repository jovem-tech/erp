<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Forma de pagamento aceita em UM nível de manutenção do orçamento.
 *
 * Mesmo congelamento de código/rótulo/tipo de BudgetPaymentMethod. Qualquer
 * linha para (orçamento, nível) substitui a lista base inteira naquele nível;
 * nenhuma linha = o nível herda as formas do orçamento.
 */
class BudgetLevelPaymentMethod extends Model
{
    protected $table = 'orcamento_nivel_formas_pagamento';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'orcamento_id' => 'integer',
        'nivel' => 'integer',
        'forma_pagamento_id' => 'integer',
        'is_cartao' => 'boolean',
        'ordem' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'orcamento_id', 'id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(FinanceiroFormaPagamento::class, 'forma_pagamento_id', 'id');
    }
}
