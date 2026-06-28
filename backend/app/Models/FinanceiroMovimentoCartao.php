<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceiroMovimentoCartao extends Model
{
    protected $table = 'financeiro_movimentos_cartao';

    protected $guarded = [];

    protected $casts = [
        'movimento_id' => 'integer',
        'operadora_id' => 'integer',
        'bandeira_id' => 'integer',
        'taxa_id' => 'integer',
        'parcelas' => 'integer',
        'valor_bruto' => 'float',
        'taxa_percentual' => 'float',
        'taxa_fixa' => 'float',
        'valor_taxa' => 'float',
        'valor_liquido' => 'float',
        'prazo_recebimento_dias' => 'integer',
        'data_competencia' => 'date',
        'data_prevista_repasse' => 'date',
        'data_prevista_recebimento' => 'date',
        'data_credito_efetivo' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function movimento(): BelongsTo
    {
        return $this->belongsTo(FinanceiroMovimento::class, 'movimento_id', 'id');
    }

    public function operadora(): BelongsTo
    {
        return $this->belongsTo(FinanceiroCartaoOperadora::class, 'operadora_id', 'id');
    }

    public function bandeira(): BelongsTo
    {
        return $this->belongsTo(FinanceiroCartaoBandeira::class, 'bandeira_id', 'id');
    }
}
