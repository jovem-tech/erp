<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Forma de pagamento aceita em um orçamento.
 *
 * Guarda código, rótulo e tipo congelados no momento da emissão: renomear ou
 * excluir a forma no catálogo não reescreve o que já foi proposto ao cliente.
 */
class BudgetPaymentMethod extends Model
{
    protected $table = 'orcamento_formas_pagamento';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'orcamento_id' => 'integer',
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
