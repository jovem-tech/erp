<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceiroCartaoOperadora extends Model
{
    protected $table = 'financeiro_cartao_operadoras';

    protected $guarded = [];

    protected $casts = [
        'prazo_padrao_dias' => 'integer',
        'ativo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('ativo', true);
    }

    public function taxas(): HasMany
    {
        return $this->hasMany(FinanceiroCartaoTaxa::class, 'operadora_id', 'id');
    }
}
