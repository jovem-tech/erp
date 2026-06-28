<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EquipamentoDefeitoProcedimento extends Model
{
    use SoftDeletes;

    protected $table = 'equipamento_defeito_procedimentos';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'defeito_id' => 'integer',
        'ordem' => 'integer',
    ];

    public function defeito(): BelongsTo
    {
        return $this->belongsTo(EquipamentoDefeito::class, 'defeito_id', 'id');
    }
}
