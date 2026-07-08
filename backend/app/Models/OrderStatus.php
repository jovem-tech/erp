<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrderStatus extends Model
{
    /**
     * Dos 3 codigos de closureCodes(), somente este representa reparo
     * efetivamente entregue e cobrado do cliente. Devolvido sem reparo e
     * descartado nao geram cobranca — nao devem contar como receita em
     * nenhum relatorio (DRE por competencia, DRE de caixa, fluxo de caixa).
     */
    public const REVENUE_CLOSURE_CODE = 'entregue_reparado';

    /**
     * Valor de `os_status.grupo_macro` que define os status de encerramento da
     * OS. E a definicao canonica de closureCodes() — usar esta constante em vez
     * de repetir a string 'encerrado' em queries/filtros.
     */
    public const CLOSURE_MACRO_GROUP = 'encerrado';

    protected $table = 'os_status';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'ordem_fluxo' => 'integer',
        'status_final' => 'boolean',
        'status_pausa' => 'boolean',
        'gera_evento_crm' => 'boolean',
        'ativo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function activeCodes(): array
    {
        return static::query()
            ->where('ativo', 1)
            ->orderBy('ordem_fluxo')
            ->pluck('codigo')
            ->map(static fn ($code): string => trim((string) $code))
            ->filter(static fn (string $code): bool => $code !== '')
            ->values()
            ->all();
    }

    /**
     * Codigos que de fato encerram o atendimento da OS (grupo_macro =
     * 'encerrado'): Equipamento Entregue, Devolvido Sem Reparo, Equipamento
     * Descartado. Estes 3 sao os UNICOS status que devem ser aplicados via
     * OrderClosureService::close() (fluxo de baixa da OS) — nunca via
     * OrderWorkflowService::updateStatus()/updateOrder() diretamente. Ver
     * skill sistema-erp-os-fluxo-fechamento para o racional completo.
     *
     * @return array<int, string>
     */
    public static function closureCodes(): array
    {
        return static::query()
            ->where('ativo', 1)
            ->where('grupo_macro', self::CLOSURE_MACRO_GROUP)
            ->orderBy('ordem_fluxo')
            ->pluck('codigo')
            ->map(static fn ($code): string => trim((string) $code))
            ->filter(static fn (string $code): bool => $code !== '')
            ->values()
            ->all();
    }

    public static function activeByCode(string $code): ?self
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        return static::query()
            ->where('codigo', $code)
            ->where('ativo', 1)
            ->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('ativo', 1);
    }
}
