<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSignatureRequest extends Model
{
    protected $table = 'documento_solicitacoes_assinatura';

    protected $guarded = [];

    protected $casts = [
        'os_id' => 'integer',
        'solicitada_por' => 'integer',
        'usuario_responsavel_id' => 'integer',
        'assinatura_solicitante_id' => 'integer',
        'documento_id' => 'integer',
        'expira_em' => 'datetime',
        'assinada_em' => 'datetime',
        'cancelada_em' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'os_id', 'id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitada_por', 'id');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_responsavel_id', 'id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(OrderDocument::class, 'documento_id', 'id');
    }

    public function requesterSignature(): BelongsTo
    {
        return $this->belongsTo(UserSignature::class, 'assinatura_solicitante_id', 'id');
    }
}
