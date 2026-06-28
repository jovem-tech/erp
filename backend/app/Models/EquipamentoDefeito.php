<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EquipamentoDefeito extends Model
{
    protected $table = 'equipamentos_defeitos';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'tipo_id' => 'integer',
        'ativo' => 'boolean',
    ];

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(EquipmentType::class, 'tipo_id', 'id');
    }

    public function procedimentos(): HasMany
    {
        return $this->hasMany(EquipamentoDefeitoProcedimento::class, 'defeito_id', 'id')->orderBy('ordem');
    }
}
