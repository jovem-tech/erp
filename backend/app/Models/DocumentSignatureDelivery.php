<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSignatureDelivery extends Model
{
    protected $table = 'documento_assinatura_notificacoes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'solicitacao_id' => 'integer',
            'tentativas' => 'integer',
            'ultima_tentativa_em' => 'datetime',
            'enviada_em' => 'datetime',
        ];
    }

    public function signatureRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentSignatureRequest::class, 'solicitacao_id', 'id');
    }
}
