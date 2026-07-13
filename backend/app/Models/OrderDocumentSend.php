<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDocumentSend extends Model
{
    protected $table = 'os_documento_envios';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'os_id' => 'integer',
        'documento_id' => 'integer',
        'enviado_por' => 'integer',
        'metadados_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'enviado_em' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'os_id', 'id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(OrderDocument::class, 'documento_id', 'id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por', 'id');
    }
}
