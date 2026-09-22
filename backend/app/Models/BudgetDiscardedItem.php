<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item de uma opção de manutenção que o cliente não escolheu, preservado na
 * aprovação para reaproveitamento técnico na edição. Nunca entra no escopo
 * contratado nem em nada que o cliente veja — ver a migration da tabela.
 */
class BudgetDiscardedItem extends Model
{
    protected $table = 'orcamento_itens_descartados';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'orcamento_id' => 'integer',
        'aprovacao_id' => 'integer',
        'item_original_id' => 'integer',
        'nivel_aprovado' => 'integer',
        'referencia_id' => 'integer',
        'quantidade' => 'float',
        'valor_unitario' => 'float',
        'desconto' => 'float',
        'desconto_percentual' => 'float',
        'acrescimo' => 'float',
        'acrescimo_percentual' => 'float',
        'total' => 'float',
        'ordem' => 'integer',
        'niveis' => 'array',
        'preco_custo_referencia' => 'float',
        'preco_venda_referencia' => 'float',
        'preco_base' => 'float',
        'percentual_encargos' => 'float',
        'valor_encargos' => 'float',
        'percentual_margem' => 'float',
        'valor_margem' => 'float',
        'valor_recomendado' => 'float',
        'descartado_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Colunas copiadas 1:1 de orcamento_itens na aprovação.
     *
     * @var array<int, string>
     */
    public const COPIED_COLUMNS = [
        'tipo_item', 'referencia_id', 'descricao', 'quantidade', 'valor_unitario',
        'desconto', 'desconto_tipo', 'desconto_percentual',
        'acrescimo', 'acrescimo_tipo', 'acrescimo_percentual',
        'total', 'ordem', 'niveis', 'observacoes',
        'preco_custo_referencia', 'preco_venda_referencia', 'preco_base',
        'percentual_encargos', 'valor_encargos', 'percentual_margem', 'valor_margem',
        'valor_recomendado', 'modo_precificacao',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'orcamento_id', 'id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(BudgetApproval::class, 'aprovacao_id', 'id');
    }
}
