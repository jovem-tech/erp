<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parcela do reembolso — specs/029-devolucao-troca/spec.md.
 *
 * O dinheiro volta pela mesma forma em que entrou, rateado proporcionalmente
 * entre os pagamentos da venda original.
 */
class SaleReturnPayment extends Model
{
    protected $table = 'venda_devolucao_pagamentos';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'venda_devolucao_id' => 'integer',
        'venda_pagamento_id' => 'integer',
        'conta_financeira_id' => 'integer',
        'movimento_id' => 'integer',
        'valor' => 'float',
        'valor_taxa_nao_estornada' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class, 'venda_devolucao_id', 'id');
    }

    public function salePayment(): BelongsTo
    {
        return $this->belongsTo(SalePayment::class, 'venda_pagamento_id', 'id');
    }
}
