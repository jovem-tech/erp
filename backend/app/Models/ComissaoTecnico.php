<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComissaoTecnico extends Model
{
    protected $table = 'comissoes_tecnicos';

    protected $guarded = [];

    protected $casts = [
        'tecnico_id' => 'integer',
        'percentual_padrao' => 'float',
        'ativo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tecnico(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tecnico_id', 'id');
    }
}
