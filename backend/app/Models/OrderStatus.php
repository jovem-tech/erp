<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrderStatus extends Model
{
    /**
     * Dos encerramentos de closureCodes(), somente este representa reparo
     * efetivamente entregue e COBRADO do cliente (OS com valor > 0 e
     * lancamentos). Os demais reparos entregues sem cobranca
     * (entregue_reparado_sem_custo, entregue_reparado_garantia), assim como
     * devolvido sem reparo e descartado, NAO geram cobranca — nao devem contar
     * como receita em nenhum relatorio (DRE por competencia, DRE de caixa,
     * fluxo de caixa, margem por OS, comissao).
     */
    public const REVENUE_CLOSURE_CODE = 'entregue_reparado_pago';

    /**
     * Os tres encerramentos que representam "reparo entregue" (equipamento
     * reparado e devolvido ao cliente), independentemente de ter havido
     * cobranca: pago, sem custo e garantia. Todos contam como
     * "entrega" nos indicadores operacionais (card "Equipamento Entregue" do
     * dashboard, grafico mensal de entregues reparadas) e geram os mesmos
     * documentos de um reparo (laudo + comprovante de entrega). NAO confundir
     * com REVENUE_CLOSURE_CODE (so o pago gera receita) — usar esta lista
     * apenas para contagem operacional / geracao documental, nunca para somar
     * receita.
     */
    public const REPAIRED_DELIVERY_CODES = [
        'entregue_reparado_pago',
        'entregue_reparado_sem_custo',
        'entregue_reparado_garantia',
    ];

    /**
     * Valor de `os_status.grupo_macro` que define os status de encerramento da
     * OS. E a definicao canonica de closureCodes() — usar esta constante em vez
     * de repetir a string 'encerrado' em queries/filtros.
     */
    public const CLOSURE_MACRO_GROUP = 'encerrado';

    /**
     * Subconjunto de closureCodes() com impacto financeiro real: equipamento
     * de fato entregue e cobrado, devolvido ou descartado. Mais estreito de
     * proposito — closureCodes() tambem inclui 'cancelado' (alcancavel so por
     * sincronizacao automatica de orcamento rejeitado via
     * BudgetOrderSyncService::syncFromBudget(), sem nenhum lancamento
     * financeiro) e os encerramentos gratuitos entregue_reparado_sem_custo /
     * entregue_reparado_garantia (reparo entregue sem cobranca — nao tem
     * titulo/lancamento a reconciliar). Usar esta constante em qualquer guard
     * que dependa de "a OS teve lancamento financeiro de verdade" — edicao de
     * orcamento em OS encerrada, cancelamento de titulo com pergunta de
     * motivo, etc.
     */
    public const FINANCIAL_IMPACT_CLOSURE_CODES = ['entregue_reparado_pago', 'devolvido_sem_reparo', 'descartado'];

    /**
     * Status que "congelam" o prazo (SLA) de entrega da OS: ao entrar em
     * qualquer um destes, data_conclusao é carimbada e resolveDeadlineState()
     * para de contar. Ao sair de um destes para um status fora da lista, o
     * prazo é redefinido (ver OrderWorkflowService::updateStatus(),
     * BudgetOrderSyncService::syncFromBudget() e OrderClosureService::cancelClosure()).
     * Independente de status_final/closureCodes()/grupo_macro — é um conceito
     * novo e próprio (ex.: entregue_pagamento_pendente tem status_final=false
     * mas está nesta lista).
     */
    public const DEADLINE_FREEZE_CODES = [
        'entregue_pagamento_pendente', 'reparo_concluido', 'reparado_disponivel_loja',
        'garantia_concluida', 'irreparavel_disponivel_loja', 'irreparavel',
        'reparo_recusado', 'entregue_reparado_pago', 'entregue_reparado_sem_custo',
        'entregue_reparado_garantia', 'devolvido_sem_reparo',
        'descartado', 'cancelado',
    ];

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
