<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pagamento de uma venda de balcão.
 *
 * Existe apesar de `financeiro_movimentos` guardar o essencial porque troco e
 * valor recebido são conferência de gaveta, não movimento financeiro, e porque
 * o snapshot de taxa/líquido permite margem líquida por venda sem passar por
 * `financeiro_movimentos_cartao`. `movimento_id` é o ponteiro de reconciliação.
 */
class SalePayment extends Model
{
    public const MODE_CREDIT = 'credito';
    public const MODE_DEBIT = 'debito';

    protected $table = 'venda_pagamentos';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'venda_id' => 'integer',
        'conta_financeira_id' => 'integer',
        'operadora_id' => 'integer',
        'bandeira_id' => 'integer',
        'movimento_id' => 'integer',
        'parcelas' => 'integer',
        'ordem' => 'integer',
        'valor' => 'float',
        'valor_recebido' => 'float',
        'troco' => 'float',
        'valor_taxa' => 'float',
        'valor_liquido' => 'float',
        'data_pagamento' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'venda_id', 'id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(FinanceiroMovimento::class, 'movimento_id', 'id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceiroConta::class, 'conta_financeira_id', 'id');
    }
}
