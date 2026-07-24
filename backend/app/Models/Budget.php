<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Budget extends Model
{
    public const ADJUSTMENT_MODE_VALUE = 'valor';
    public const ADJUSTMENT_MODE_PERCENT = 'percentual';

    public const TYPE_PREVIEW = 'previo';
    public const TYPE_ASSISTANCE = 'assistencia';

    public const STATUS_DRAFT = 'rascunho';
    public const STATUS_PENDING_SEND = 'pendente_envio';
    public const STATUS_SENT = 'enviado';
    public const STATUS_WAITING_REPLY = 'aguardando_resposta';
    public const STATUS_WAITING_PACKAGE = 'aguardando_pacote';
    public const STATUS_PACKAGE_APPROVED = 'pacote_aprovado';
    public const STATUS_PENDING = 'pendente';
    public const STATUS_APPROVED = 'aprovado';
    public const STATUS_RESEND = 'reenviar_orcamento';
    public const STATUS_PENDING_OS = 'pendente_abertura_os';
    public const STATUS_REJECTED = 'rejeitado';
    public const STATUS_EXPIRED = 'vencido';
    public const STATUS_CANCELLED = 'cancelado';
    public const STATUS_CONVERTED = 'convertido';

    protected $table = 'orcamentos';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'versao' => 'integer',
        'cliente_id' => 'integer',
        'contato_id' => 'integer',
        'os_id' => 'integer',
        'equipamento_id' => 'integer',
        'equipamento_tipo_id' => 'integer',
        'equipamento_marca_id' => 'integer',
        'equipamento_modelo_id' => 'integer',
        'envolve_equipamento' => 'boolean',
        'conversa_id' => 'integer',
        'responsavel_id' => 'integer',
        'criado_por' => 'integer',
        'atualizado_por' => 'integer',
        'validade_dias' => 'integer',
        'subtotal' => 'float',
        'desconto' => 'float',
        'desconto_percentual' => 'float',
        'acrescimo' => 'float',
        'acrescimo_percentual' => 'float',
        'total' => 'float',
        'convertido_id' => 'integer',
        'validade_data' => 'date',
        'token_expira_em' => 'datetime',
        'enviado_em' => 'datetime',
        'aprovado_em' => 'datetime',
        'rejeitado_em' => 'datetime',
        'cancelado_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function publicApprovalUrlForToken(string $token): ?string
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $base = rtrim(trim((string) config('app.public_url')), '/');
        if ($base === '') {
            return route('budgets.public.show', ['token' => $token], true);
        }

        return $base . route('budgets.public.show', ['token' => $token], false);
    }

    public function publicApprovalUrl(): ?string
    {
        return self::publicApprovalUrlForToken((string) ($this->token_publico ?? ''));
    }

    public static function statusOptions(): array
    {
        return [
            ['value' => self::STATUS_DRAFT, 'label' => 'Rascunho', 'color' => '#6b7280'],
            ['value' => self::STATUS_PENDING_SEND, 'label' => 'Pendente de envio', 'color' => '#3b82f6'],
            ['value' => self::STATUS_SENT, 'label' => 'Enviado', 'color' => '#2563eb'],
            ['value' => self::STATUS_WAITING_REPLY, 'label' => 'Aguardando resposta', 'color' => '#8b5cf6'],
            ['value' => self::STATUS_WAITING_PACKAGE, 'label' => 'Aguardando pacote', 'color' => '#f59e0b'],
            ['value' => self::STATUS_PACKAGE_APPROVED, 'label' => 'Pacote aprovado', 'color' => '#22c55e'],
            ['value' => self::STATUS_PENDING, 'label' => 'Pendente', 'color' => '#f97316'],
            ['value' => self::STATUS_APPROVED, 'label' => 'Aprovado', 'color' => '#16a34a'],
            ['value' => self::STATUS_RESEND, 'label' => 'Reenviar orçamento', 'color' => '#0ea5e9'],
            ['value' => self::STATUS_PENDING_OS, 'label' => 'Aprovado (pendente de OS)', 'color' => '#0f766e'],
            ['value' => self::STATUS_REJECTED, 'label' => 'Rejeitado', 'color' => '#dc2626'],
            ['value' => self::STATUS_EXPIRED, 'label' => 'Vencido', 'color' => '#6b7280'],
            ['value' => self::STATUS_CANCELLED, 'label' => 'Cancelado', 'color' => '#111827'],
            ['value' => self::STATUS_CONVERTED, 'label' => 'Convertido', 'color' => '#0f172a'],
        ];
    }

    public static function statusLabels(): array
    {
        return array_column(self::statusOptions(), 'label', 'value');
    }

    public static function statusLabel(?string $value): string
    {
        $value = trim((string) $value);

        return self::statusLabels()[$value] ?? ($value !== '' ? ucfirst(str_replace('_', ' ', $value)) : 'Rascunho');
    }

    public static function typeOptions(): array
    {
        return [
            ['value' => self::TYPE_PREVIEW, 'label' => 'Orçamento prévio'],
            ['value' => self::TYPE_ASSISTANCE, 'label' => 'Orçamento com equipamento na assistência'],
        ];
    }

    public static function typeLabel(?string $value): string
    {
        $value = trim((string) $value);

        foreach (self::typeOptions() as $option) {
            if ((string) ($option['value'] ?? '') === $value) {
                return (string) ($option['label'] ?? ucfirst($value));
            }
        }

        return $value !== '' ? ucfirst($value) : 'Orçamento prévio';
    }

    public static function originOptions(): array
    {
        return [
            ['value' => 'manual', 'label' => 'Manual'],
            ['value' => 'os', 'label' => 'Ordem de serviço'],
            ['value' => 'conversa', 'label' => 'Conversa'],
            ['value' => 'cliente', 'label' => 'Cliente'],
        ];
    }

    public static function originLabel(?string $value): string
    {
        $value = trim((string) $value);

        foreach (self::originOptions() as $option) {
            if ((string) ($option['value'] ?? '') === $value) {
                return (string) ($option['label'] ?? ucfirst($value));
            }
        }

        return $value !== '' ? ucfirst($value) : 'Manual';
    }

    public function scopeWithSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($search): void {
            $builder->where('orcamentos.numero', 'like', '%' . $search . '%')
                ->orWhere('orcamentos.titulo', 'like', '%' . $search . '%')
                ->orWhere('orcamentos.cliente_nome_avulso', 'like', '%' . $search . '%')
                ->orWhereHas('client', static function (Builder $clientQuery) use ($search): void {
                    $clientQuery->where('nome_razao', 'like', '%' . $search . '%')
                        ->orWhere('cpf_cnpj', 'like', '%' . $search . '%')
                        ->orWhere('telefone1', 'like', '%' . $search . '%');
                })
                ->orWhereHas('equipment', static function (Builder $equipmentQuery) use ($search): void {
                    $equipmentQuery->where('resumo_tecnico', 'like', '%' . $search . '%')
                        ->orWhere('numero_serie', 'like', '%' . $search . '%')
                        ->orWhere('imei', 'like', '%' . $search . '%');
                })
                ->orWhereHas('order', static function (Builder $orderQuery) use ($search): void {
                    $orderQuery->where('numero_os', 'like', '%' . $search . '%')
                        ->orWhere('relato_cliente', 'like', '%' . $search . '%')
                        ->orWhere('diagnostico_tecnico', 'like', '%' . $search . '%')
                        ->orWhere('solucao_aplicada', 'like', '%' . $search . '%');
                })
                ->orWhereHas('items', static function (Builder $itemQuery) use ($search): void {
                    $itemQuery->where('descricao', 'like', '%' . $search . '%')
                        ->orWhere('observacoes', 'like', '%' . $search . '%');
                });
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'os_id', 'id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id', 'id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por', 'id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atualizado_por', 'id');
    }

    public function revisionBase(): BelongsTo
    {
        return $this->belongsTo(self::class, 'orcamento_revisao_de_id', 'id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BudgetItem::class, 'orcamento_id', 'id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(BudgetStatusHistory::class, 'orcamento_id', 'id');
    }

    public function sends(): HasMany
    {
        return $this->hasMany(BudgetSend::class, 'orcamento_id', 'id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(BudgetApproval::class, 'orcamento_id', 'id');
    }
}
