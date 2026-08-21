<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceiroCartaoCredito extends Model
{
    protected $table = 'financeiro_cartoes_credito';

    protected $guarded = [];

    protected $casts = [
        'dia_fechamento' => 'integer',
        'dia_vencimento' => 'integer',
        'conta_financeira_id' => 'integer',
        'ativo' => 'boolean',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('ativo', true);
    }

    public function despesas(): HasMany
    {
        return $this->hasMany(Financeiro::class, 'cartao_credito_id');
    }

    /**
     * Conta de Contas e Saldos vinculada ao cartão — de onde o dinheiro sai.
     */
    public function contaFinanceira(): BelongsTo
    {
        return $this->belongsTo(FinanceiroConta::class, 'conta_financeira_id', 'id');
    }
}
