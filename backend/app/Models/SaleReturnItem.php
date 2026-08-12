<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item devolvido — specs/029-devolucao-troca/spec.md.
 *
 * `valor_total` é o valor de lista da linha; `valor_reembolsado` é o que o
 * cliente recebe de volta, já com o rateio do desconto geral da venda.
 */
class SaleReturnItem extends Model
{
    protected $table = 'venda_devolucao_itens';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'venda_devolucao_id' => 'integer',
        'venda_item_id' => 'integer',
        'quantidade' => 'float',
        'valor_unitario' => 'float',
        'valor_total' => 'float',
        'valor_reembolsado' => 'float',
        'custo_unitario' => 'float',
        'custo_total' => 'float',
        'retorna_estoque' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class, 'venda_devolucao_id', 'id');
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class, 'venda_item_id', 'id');
    }
}
