<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceiroCartaoTaxa extends Model
{
    public const MODALIDADE_CREDITO = 'credito';
    public const MODALIDADE_DEBITO = 'debito';

    protected $table = 'financeiro_cartao_taxas';

    protected $guarded = [];

    protected $casts = [
        'operadora_id' => 'integer',
        'bandeira_id' => 'integer',
        'parcelas_inicial' => 'integer',
        'parcelas_final' => 'integer',
        'taxa_percentual' => 'float',
        'taxa_fixa' => 'float',
        'prazo_recebimento_dias' => 'integer',
        'ativo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('ativo', true);
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
