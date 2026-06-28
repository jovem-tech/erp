<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChecklistModelo extends Model
{
    protected $table = 'checklist_modelos';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'checklist_tipo_id' => 'integer',
        'tipo_equipamento_id' => 'integer',
        'ordem' => 'integer',
        'ativo' => 'boolean',
    ];

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(ChecklistTipo::class, 'checklist_tipo_id', 'id');
    }

    public function tipoEquipamento(): BelongsTo
    {
        return $this->belongsTo(EquipmentType::class, 'tipo_equipamento_id', 'id');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ChecklistItem::class, 'checklist_modelo_id', 'id')->orderBy('ordem');
    }
}
