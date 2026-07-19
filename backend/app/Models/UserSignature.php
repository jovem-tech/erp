<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSignature extends Model
{
    protected $table = 'usuario_assinaturas';

    protected $guarded = [];

    protected $casts = [
        'usuario_id' => 'integer',
        'largura' => 'integer',
        'altura' => 'integer',
        'ativa' => 'boolean',
        'criada_por' => 'integer',
        'revogada_em' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id', 'id');
    }
}
