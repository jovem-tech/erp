<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderDocument extends Model
{
    protected $table = 'os_documentos';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'os_id' => 'integer',
        'versao' => 'integer',
        'gerado_por' => 'integer',
        'assinado_por' => 'integer',
        'assinado_em' => 'datetime',
        'metadados_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'arquivado_em' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'os_id', 'id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gerado_por', 'id');
    }

    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assinado_por', 'id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(OrderDocumentFile::class, 'documento_id', 'id');
    }

    public function snapshot(): HasOne
    {
        return $this->hasOne(OrderDocumentSnapshot::class, 'documento_id', 'id');
    }
}
