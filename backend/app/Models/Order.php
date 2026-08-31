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
        'tempo_tecnico_horas' => 'float',
        'data_entrega' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Data em que a OS reconhece receita: a entrega, caindo para a conclusao
     * quando a entrega nao foi registrada.
     *
     * O COALESCE nao e defensivo a toa — ha 16 OS fechadas com receita e sem
     * `data_entrega` no banco. Filtrar so por `data_entrega`, como o DRE fazia,
     * some com elas do relatorio.
     */
    public const REVENUE_DATE_SQL = 'COALESCE(os.data_entrega, os.data_conclusao)';

    public function scopeAssignedToTechnician(Builder $query, int $technicianId): Builder
    {
        return $query->where('os.tecnico_id', $technicianId);
    }

    /**
     * OS cujo fechamento gera receita.
     *
     * Cobre os DOIS caminhos de "entregue e cobravel": o status atual e, para a
     * OS entregue com pendencia financeira, o status final ja definido em
     * `status_final_pendente_pagamento`. Uma OS entregue que o cliente ainda nao
     * pagou e faturamento do mes da entrega — o que falta nela e caixa, nao
     * receita.
     *
     * Definicao unica compartilhada com o painel, que aplica a mesma regra sobre
     * query builder em DashboardSummaryService::applyRevenueDeliveryScope().
     * Enquanto o DRE olhava so `status`, os dois relatorios discordavam sobre o
     * faturamento do mesmo mes.
     */
    public function scopeReceitaReconhecida(Builder $query): Builder
    {
        return $query->where(static function (Builder $scope): void {
            $scope
                ->where('os.status', OrderStatus::REVENUE_CLOSURE_CODE)
                ->orWhere('os.status_final_pendente_pagamento', OrderStatus::REVENUE_CLOSURE_CODE);
        });
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

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class, 'os_id', 'id');
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
