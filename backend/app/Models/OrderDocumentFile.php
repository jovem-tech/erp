<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDocumentFile extends Model
{
    protected $table = 'os_documento_arquivos';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'documento_id' => 'integer',
        'tamanho_bytes' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(OrderDocument::class, 'documento_id', 'id');
    }
}
