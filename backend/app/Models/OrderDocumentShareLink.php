<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderDocumentShareLink extends Model
{
    protected $table = 'os_documento_links';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'os_id' => 'integer',
        'criado_por' => 'integer',
        'revogado_por' => 'integer',
        'acessos_count' => 'integer',
        'metadados_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'expira_em' => 'datetime',
        'revogado_em' => 'datetime',
        'ultimo_acesso_em' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'os_id', 'id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderDocumentShareLinkItem::class, 'link_id', 'id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por', 'id');
    }
}
