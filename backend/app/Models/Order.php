<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $table = 'os';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'cliente_id' => 'integer',
        'equipamento_id' => 'integer',
        'tecnico_id' => 'integer',
        'status_atualizado_em' => 'datetime',
        'data_abertura' => 'datetime',
        'data_entrada' => 'datetime',
        'data_previsao' => 'date',
        'data_conclusao' => 'datetime',
        'data_entrega' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeAssignedToTechnician(Builder $query, int $technicianId): Builder
    {
        return $query->where('os.tecnico_id', $technicianId);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'cliente_id', 'id');
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equipamento_id', 'id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tecnico_id', 'id');
    }

    public function statusCatalog(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class, 'status', 'codigo');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class, 'os_id', 'id');
    }

    public function procedureHistory(): HasMany
    {
        return $this->hasMany(OrderProcedureHistory::class, 'os_id', 'id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(OrderPhoto::class, 'os_id', 'id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(OrderDocument::class, 'os_id', 'id');
    }
}
