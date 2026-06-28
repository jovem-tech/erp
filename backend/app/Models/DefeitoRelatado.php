<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DefeitoRelatado extends Model
{
    protected $table = 'defeitos_relatados';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'tipo_equipamento_id' => 'integer',
        'ordem_exibicao' => 'integer',
        'ativo' => 'boolean',
    ];

    public function tipoEquipamento(): BelongsTo
    {
        return $this->belongsTo(EquipmentType::class, 'tipo_equipamento_id', 'id');
    }
}
