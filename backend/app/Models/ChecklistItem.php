<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistItem extends Model
{
    protected $table = 'checklist_itens';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'checklist_modelo_id' => 'integer',
        'ordem' => 'integer',
        'ativo' => 'boolean',
    ];

    public function modelo(): BelongsTo
    {
        return $this->belongsTo(ChecklistModelo::class, 'checklist_modelo_id', 'id');
    }
}
