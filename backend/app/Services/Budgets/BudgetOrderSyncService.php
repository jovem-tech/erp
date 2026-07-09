<?php

namespace App\Services\Budgets;

use App\Models\Budget;
use App\Models\BudgetItem;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\OrderStatusHistory;
use Illuminate\Support\Facades\Schema;

class BudgetOrderSyncService
{
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

        Order::query()
            ->whereKey($orderId)
            ->update([
                'status' => $targetStatus,
                'estado_fluxo' => $flowState,
                'status_atualizado_em' => $now,
                'updated_at' => $now,
            ]);

        if (Schema::hasTable('os_status_historico')) {
            OrderStatusHistory::query()->create([
                'os_id' => $orderId,
                'status_anterior' => $currentStatus !== '' ? $currentStatus : null,
                'status_novo' => $targetStatus,
                'estado_fluxo' => $flowState,
                'usuario_id' => $userId,
                'observacao' => sprintf(
                    'Status sincronizado automaticamente pelo orçamento %s (%s).',
                    trim((string) ($budget->numero ?? ('#' . (int) $budget->id))),
                    Budget::statusLabel((string) ($budget->status ?? ''))
                ),
                'created_at' => $now,
            ]);
        }
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
