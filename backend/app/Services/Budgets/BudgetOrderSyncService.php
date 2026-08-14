<?php

namespace App\Services\Budgets;

use App\Models\Budget;
use App\Models\BudgetApproval;
use App\Models\BudgetItem;
use App\Models\BudgetStatusHistory;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderStatus;
use App\Models\OrderStatusHistory;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Orders\OrderEventService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BudgetOrderSyncService
{
    public function __construct(
        private readonly OrderEventService $orderEventService,
        private readonly NotificationDispatchService $notificationDispatchService
    ) {
    }

    /**
     * Atualiza status e valores da OS vinculada de acordo com o orçamento.
     */
    public function syncFromBudget(Budget $budget, ?int $userId = null): void
    {
        $orderId = (int) ($budget->os_id ?? 0);
        if ($orderId <= 0) {
            return;
        }

        $order = Order::query()->find($orderId);
        if (! $order instanceof Order) {
            return;
        }

        $this->syncOrderFinancials($budget, $orderId);

        $currentStatus = strtolower(trim((string) ($order->status ?? '')));
        $currentFlowState = strtolower(trim((string) ($order->estado_fluxo ?? '')));

        $targetStatus = $this->targetOrderStatus((string) ($budget->status ?? ''));
        if ($targetStatus === null || $currentStatus === $targetStatus) {
            return;
        }

        // OS cancelada só é reaberta quando o orçamento volta a um estado ativo.
        if (
            ($currentStatus === 'cancelado' || $currentFlowState === 'cancelado')
            && $targetStatus === 'cancelado'
        ) {
            return;
        }

        $statusRow = OrderStatus::activeByCode($targetStatus);
        if (! $statusRow instanceof OrderStatus) {
            return;
        }

        $flowState = trim((string) ($statusRow->estado_fluxo_padrao ?? '')) ?: 'em_atendimento';
        $now = now();

        // Congelamento de prazo (SLA) — mesma regra de OrderWorkflowService::
        // updateStatus(), mas aplicada silenciosamente (sem modal): este
        // caminho é sempre automático, disparado pela sincronização de status
        // do orçamento, sem um usuário interagindo no instante da troca.
        $enteringDeadlineFreeze = in_array($targetStatus, OrderStatus::DEADLINE_FREEZE_CODES, true)
            && ! in_array($currentStatus, OrderStatus::DEADLINE_FREEZE_CODES, true);
        $leavingDeadlineFreeze = in_array($currentStatus, OrderStatus::DEADLINE_FREEZE_CODES, true)
            && ! in_array($targetStatus, OrderStatus::DEADLINE_FREEZE_CODES, true);
        $prazoAnterior = $order->data_previsao;
        $novoPrazo = $leavingDeadlineFreeze ? $now->copy()->addDays(7)->toDateString() : null;

        $orderUpdate = [
            'status' => $targetStatus,
            'estado_fluxo' => $flowState,
            'status_atualizado_em' => $now,
            'updated_at' => $now,
        ];

        if ($enteringDeadlineFreeze) {
            $orderUpdate['data_conclusao'] = $now;
        }

        if ($leavingDeadlineFreeze) {
            $orderUpdate['data_conclusao'] = null;
            $orderUpdate['data_previsao'] = $novoPrazo;
        }

        Order::query()
            ->whereKey($orderId)
            ->update($orderUpdate);

        $observacao = sprintf(
            'Status sincronizado automaticamente pelo orçamento %s (%s).',
            trim((string) ($budget->numero ?? ('#' . (int) $budget->id))),
            Budget::statusLabel((string) ($budget->status ?? ''))
        );

        if (Schema::hasTable('os_status_historico')) {
            OrderStatusHistory::query()->create([
                'os_id' => $orderId,
                'status_anterior' => $currentStatus !== '' ? $currentStatus : null,
                'status_novo' => $targetStatus,
                'estado_fluxo' => $flowState,
                'usuario_id' => $userId,
                'observacao' => $observacao,
                'created_at' => $now,
            ]);
        }

        $this->orderEventService->record(
            $orderId,
            OrderEvent::CATEGORIA_STATUS,
            OrderEvent::TIPO_STATUS_SINCRONIZADO_ORCAMENTO,
            'Status sincronizado pelo orçamento',
            $observacao,
            [
                'orcamento_id' => (int) $budget->id,
                'orcamento_numero' => trim((string) ($budget->numero ?? '')),
                'orcamento_status' => (string) ($budget->status ?? ''),
                'status_anterior' => $currentStatus !== '' ? $currentStatus : null,
                'status_novo' => $targetStatus,
            ],
            $userId,
            OrderEvent::ORIGEM_AUTOMACAO,
            $now
        );

        if ($leavingDeadlineFreeze) {
            $this->orderEventService->record(
                $orderId,
                OrderEvent::CATEGORIA_STATUS,
                OrderEvent::TIPO_PRAZO_REDEFINIDO,
                'Prazo redefinido',
                null,
                [
                    'prazo_anterior' => $prazoAnterior !== null ? $prazoAnterior->toDateString() : null,
                    'prazo_novo' => $novoPrazo,
                    'motivo' => 'reabertura_automatica_orcamento',
                ],
                $userId,
                OrderEvent::ORIGEM_AUTOMACAO,
                $now
            );
        }
    }

    /**
     * Propaga apenas os valores do orçamento para a OS, sem alterar o status da
     * OS. Usado na geração de OS a partir de um orçamento avulso aprovado
     * (OrderWorkflowService::createOrder), onde o técnico define o status da OS
     * e queremos preservar essa escolha.
     */
    public function syncFinancialsFromBudget(Budget $budget, int $orderId): void
    {
        $this->syncOrderFinancials($budget, $orderId);
    }

    /**
     * Cancela automaticamente o(s) orçamento(s) ainda abertos vinculados a uma
     * OS que acabou de sair do fluxo sem reparo (ver
     * `OrderStatus::flowExitCodes()`: Irreparável, Irreparável Disponível para
     * Retirada, Reparo Recusado ou Cancelado) — não faz sentido manter um
     * orçamento pendente de aprovação para um reparo que não vai mais
     * acontecer. Chamado por `OrderWorkflowService::updateStatus()` e
     * `updateOrder()` logo após a troca de status da OS já ter sido
     * persistida.
     *
     * Direção oposta a `syncFromBudget()` (orçamento → OS) — por isso vive
     * aqui, e não em `BudgetApprovalService`: manter os dois sentidos de
     * sincronização no mesmo serviço evita depender de
     * `OrderWorkflowService` (que `BudgetApprovalService` alcança
     * indiretamente via `OrderDocumentCenterService`, fechando um ciclo de
     * injeção de dependência).
     *
     * Propositalmente NÃO chama `syncFromBudget()` de volta: o status da OS
     * já foi definido deliberadamente pelo técnico neste mesmo request, então
     * ressincronizar sobrescreveria (ex.: Irreparável virando Cancelado só
     * porque o orçamento também virou cancelado).
     *
     * Orçamentos já em status terminal (convertido/cancelado/rejeitado) são
     * ignorados silenciosamente — decisão já tomada, nada a fazer.
     */
    public function cancelBudgetsForOrderFlowExit(int $orderId, ?int $userId, string $orderStatusLabel): void
    {
        $budgets = Budget::query()
            ->where('os_id', $orderId)
            ->whereNotIn('status', [Budget::STATUS_CONVERTED, Budget::STATUS_CANCELLED, Budget::STATUS_REJECTED])
            ->get();

        foreach ($budgets as $budget) {
            $this->cancelOneBudgetForOrderFlowExit($budget, $userId, $orderStatusLabel);
        }
    }

    private function cancelOneBudgetForOrderFlowExit(Budget $budget, ?int $userId, string $orderStatusLabel): void
    {
        DB::transaction(function () use ($budget, $userId, $orderStatusLabel): void {
            $budget->refresh();

            $status = trim((string) ($budget->status ?? ''));
            if (in_array($status, [Budget::STATUS_CONVERTED, Budget::STATUS_CANCELLED, Budget::STATUS_REJECTED], true)) {
                return;
            }

            $previousStatus = $status !== '' ? $status : Budget::STATUS_DRAFT;
            $cancelledAt = now();
            $decisionMessage = sprintf(
                'Orçamento cancelado automaticamente: a OS foi movida para "%s" (saída de fluxo sem reparo).',
                $orderStatusLabel
            );

            $budget->forceFill([
                'status' => Budget::STATUS_CANCELLED,
                'cancelado_em' => $cancelledAt,
            ])->save();

            BudgetApproval::query()->create([
                'orcamento_id' => (int) $budget->id,
                'token_publico' => (string) ($budget->token_publico ?? ''),
                'acao' => 'cancelado',
                'origem' => 'sistema',
                'usuario_id' => $userId,
                'usuario_nome' => 'Sistema',
                'resposta_cliente' => null,
                'observacao' => $decisionMessage,
                'ip_origem' => null,
                'user_agent' => null,
                'created_at' => $cancelledAt,
            ]);

            BudgetStatusHistory::query()->create([
                'orcamento_id' => (int) $budget->id,
                'status_anterior' => $previousStatus,
                'status_novo' => Budget::STATUS_CANCELLED,
                'observacao' => $decisionMessage,
                'origem' => 'sistema',
                'alterado_por' => $userId,
                'created_at' => $cancelledAt,
            ]);

            $osId = (int) ($budget->os_id ?? 0);
            if ($osId > 0) {
                $this->orderEventService->record(
                    $osId,
                    OrderEvent::CATEGORIA_ORCAMENTO,
                    OrderEvent::TIPO_ORCAMENTO_CANCELADO,
                    'Orçamento cancelado automaticamente',
                    $decisionMessage,
                    [
                        'orcamento_id' => (int) $budget->id,
                        'numero' => (string) $budget->numero,
                        'motivo' => 'os_saida_fluxo',
                        'os_status_novo' => $orderStatusLabel,
                    ],
                    $userId,
                    OrderEvent::ORIGEM_AUTOMACAO,
                    $cancelledAt
                );
            }

            $order = $osId > 0 ? Order::query()->find($osId) : null;

            $this->notificationDispatchService->toUsers(
                [
                    (int) ($budget->responsavel_id ?? 0),
                    (int) ($budget->criado_por ?? 0),
                    (int) ($order->tecnico_id ?? 0),
                ],
                [
                    'kind' => 'orcamento.cancelled',
                    'title' => 'Orçamento cancelado automaticamente',
                    'body' => sprintf(
                        'O orçamento %s foi cancelado automaticamente: a OS foi movida para "%s".',
                        $budget->numero,
                        $orderStatusLabel
                    ),
                    'route' => '/orcamentos/' . (int) $budget->id,
                    'icon' => 'receipt',
                    'orcamento_id' => (int) $budget->id,
                    'os_id' => $osId,
                ]
            );
        });
    }

    /**
     * Propaga os valores do orçamento para a OS vinculada (coluna Valor da listagem).
     * Orçamentos rejeitados/cancelados não sobrescrevem os valores já registrados.
     */
    private function syncOrderFinancials(Budget $budget, int $orderId): void
    {
        $budgetStatus = trim((string) ($budget->status ?? ''));
        if (in_array($budgetStatus, [Budget::STATUS_REJECTED, Budget::STATUS_CANCELLED], true)) {
            return;
        }

        $itemTotals = BudgetItem::query()
            ->where('orcamento_id', (int) $budget->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo_item = 'servico' THEN total ELSE 0 END), 0) as total_servicos")
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo_item = 'peca' THEN total ELSE 0 END), 0) as total_pecas")
            ->first();

        Order::query()
            ->whereKey($orderId)
            ->update([
                'valor_mao_obra' => round((float) ($itemTotals->total_servicos ?? 0), 2),
                'valor_pecas' => round((float) ($itemTotals->total_pecas ?? 0), 2),
                'valor_total' => round((float) ($budget->subtotal ?? 0), 2),
                'desconto' => round((float) ($budget->desconto ?? 0), 2),
                'valor_final' => round((float) ($budget->total ?? 0), 2),
                'updated_at' => now(),
            ]);
    }

    private function targetOrderStatus(string $budgetStatus): ?string
    {
        return match (trim($budgetStatus)) {
            Budget::STATUS_DRAFT,
            Budget::STATUS_PENDING_SEND,
            Budget::STATUS_PENDING,
            Budget::STATUS_RESEND,
            Budget::STATUS_EXPIRED => 'aguardando_orcamento',
            Budget::STATUS_SENT,
            Budget::STATUS_WAITING_REPLY,
            Budget::STATUS_WAITING_PACKAGE => 'aguardando_autorizacao',
            Budget::STATUS_APPROVED,
            Budget::STATUS_CONVERTED => 'aguardando_reparo',
            Budget::STATUS_REJECTED,
            Budget::STATUS_CANCELLED => 'cancelado',
            default => null,
        };
    }
}
