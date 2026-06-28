<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FinanceiroCartaoBandeira extends Model
{
    protected $table = 'financeiro_cartao_bandeiras';

    protected $guarded = [];

    protected $casts = [
        'ordem_exibicao' => 'integer',
        'ativo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('ativo', true);
    }
}
