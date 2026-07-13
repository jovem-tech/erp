<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDocumentShareLinkItem extends Model
{
    protected $table = 'os_documento_link_itens';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'link_id' => 'integer',
        'documento_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function link(): BelongsTo
    {
        return $this->belongsTo(OrderDocumentShareLink::class, 'link_id', 'id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(OrderDocument::class, 'documento_id', 'id');
    }
}
