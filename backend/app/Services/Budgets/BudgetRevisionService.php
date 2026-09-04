<?php

namespace App\Services\Budgets;

use App\Models\Budget;
use App\Models\BudgetItem;
use App\Models\BudgetStatusHistory;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderStatus;
use App\Models\User;
use App\Services\Financeiro\FinanceiroService;
use App\Services\Financeiro\OsMargemService;
use App\Services\Orders\OrderEventService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Gerencia o ciclo de "revisão" de um orçamento já convertido: uma linha
 * separada em `orcamentos` (mesma tabela, ligada via
 * `orcamento_revisao_de_id`) que carrega uma proposta de mudança de valor
 * e/ou cliente, percorre o fluxo de aprovação já existente
 * (BudgetApprovalService) como um orçamento comum, e só é copiada de volta
 * para o orçamento convertido original quando o cliente aprova.
 *
 * Deliberadamente não depende de BudgetWorkflowService nem de
 * BudgetApprovalService (os dois passam a depender deste serviço) — fechar
 * esse ciclo de injeção é o que este desenho evita.
 */
class BudgetRevisionService
{
    public function __construct(
        private readonly BudgetCommercialTermsService $budgetCommercialTermsService,
        private readonly OrderEventService $orderEventService,
        private readonly BudgetOrderSyncService $budgetOrderSyncService,
        private readonly FinanceiroService $financeiroService,
        private readonly OsMargemService $osMargemService,
    ) {}

    public function isRevision(Budget $budget): bool
    {
        return (int) ($budget->orcamento_revisao_de_id ?? 0) > 0;
    }

    /**
     * Existe alguma revisão de $base ainda não decidida (nem rejeitada, nem
     * cancelada, nem vencida) e ainda não aplicada de volta ao base?
     */
    public function hasUnresolvedRevision(Budget $base): bool
    {
        return $this->pendingRevisionFor($base) instanceof Budget;
    }

    public function pendingRevisionFor(Budget $base): ?Budget
    {
        return Budget::query()
            ->where('orcamento_revisao_de_id', $base->id)
            ->whereNotIn('status', [Budget::STATUS_REJECTED, Budget::STATUS_CANCELLED, Budget::STATUS_EXPIRED])
            ->whereNull('aplicada_em')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Cria uma nova revisão a partir do orçamento convertido $base, com os
     * campos financeiros/cliente (Budget::CONVERTED_REVISION_FIELDS, exceto
     * `itens`) já resolvidos em $revisionAttributes. Itens não são tratados
     * aqui — o clone dos itens do base é só o ponto de partida; quando o
     * payload também mexe em itens, o chamador
     * (BudgetWorkflowService::updateConvertedBudget()) reaproveita seus
     * próprios syncItems()/recalculateBudgetFinancials() sobre a revisão
     * retornada, do mesmo jeito que já faz para um orçamento comum — assim
     * a lógica de precificação/catálogo continua existindo em um lugar só.
     *
     * @param  array<string, mixed>  $revisionAttributes
     */
    public function spawnRevision(Budget $base, User $user, array $revisionAttributes): Budget
    {
        return DB::transaction(function () use ($base, $user, $revisionAttributes): Budget {
            $nextVersao = 1 + (int) Budget::query()
                ->where(function (Builder $query) use ($base): void {
                    $query->whereKey($base->id)->orWhere('orcamento_revisao_de_id', $base->id);
                })
                ->max('versao');

            $revision = $base->replicate([
                'numero', 'versao', 'status', 'orcamento_revisao_de_id',
                'token_publico', 'token_expira_em', 'enviado_em', 'aprovado_em',
                'rejeitado_em', 'cancelado_em', 'motivo_rejeicao',
                'convertido_tipo', 'convertido_id', 'aplicada_em',
                'created_at', 'updated_at',
            ]);

            $revision->numero = sprintf('%s-R%d', (string) $base->numero, $nextVersao);
            $revision->versao = $nextVersao;
            $revision->orcamento_revisao_de_id = (int) $base->id;
            $revision->status = Budget::STATUS_RESEND;
            $revision->criado_por = (int) $user->id;
            $revision->atualizado_por = (int) $user->id;

            foreach (Budget::CONVERTED_REVISION_FIELDS as $field) {
                if ($field === 'itens' || ! array_key_exists($field, $revisionAttributes)) {
                    continue;
                }
                $revision->{$field} = $revisionAttributes[$field];
            }

            $revision->save();

            $this->cloneItems($base, $revision);
            $this->cloneCommercialTerms($base, $revision);

            BudgetStatusHistory::query()->create([
                'orcamento_id' => $revision->id,
                'status_anterior' => null,
                'status_novo' => $revision->status,
                'observacao' => sprintf(
                    'Revisão de valores/cliente proposta a partir do orçamento convertido %s.',
                    $base->numero
                ),
                'origem' => 'sistema',
                'alterado_por' => (int) $user->id,
                'created_at' => now(),
            ]);

            if ((int) ($base->os_id ?? 0) > 0) {
                $this->orderEventService->record(
                    (int) $base->os_id,
                    OrderEvent::CATEGORIA_ORCAMENTO,
                    OrderEvent::TIPO_ORCAMENTO_REVISAO_PROPOSTA,
                    'Revisão de orçamento proposta',
                    sprintf(
                        'Nova versão %s proposta a partir do orçamento convertido %s — aguardando aprovação do cliente.',
                        $revision->numero,
                        $base->numero
                    ),
                    [
                        'orcamento_base_id' => (int) $base->id,
                        'orcamento_base_numero' => (string) $base->numero,
                        'revisao_id' => (int) $revision->id,
                        'revisao_numero' => (string) $revision->numero,
                    ],
                    (int) $user->id
                );
            }

            return $revision->refresh();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function itemRowsFor(Budget $source, int $targetBudgetId): array
    {
        $source->loadMissing('items');
        $now = now();

        return $source->items->map(function (BudgetItem $item) use ($targetBudgetId, $now): array {
            $data = $item->toArray();
            unset($data['id']);
            $data['orcamento_id'] = $targetBudgetId;
            $data['created_at'] = $now;
            $data['updated_at'] = $now;

            return $data;
        })->all();
    }

    private function cloneItems(Budget $base, Budget $revision): void
    {
        $rows = $this->itemRowsFor($base, (int) $revision->id);
        if ($rows !== []) {
            BudgetItem::query()->insert($rows);
        }
    }

    private function cloneCommercialTerms(Budget $base, Budget $revision): void
    {
        $base->loadMissing('paymentMethods');
        $codes = $base->paymentMethods->sortBy('ordem')->pluck('forma_codigo')->map(strval(...))->all();
        $this->budgetCommercialTermsService->syncPaymentMethods($revision, $codes);
    }

    /**
     * Aplica ao orçamento base uma revisão que acabou de ser aprovada pelo
     * cliente. Chamado de dentro da MESMA transação de
     * BudgetApprovalService::finalizeApproval() — não abre transação própria
     * de propósito, para o merge e o registro da aprovação da revisão serem
     * atômicos. O orçamento base permanece `convertido` o tempo todo: só os
     * campos financeiros/cliente (e os itens) são copiados de volta; status
     * nunca é tocado.
     */
    public function applyApprovedRevision(Budget $revision, ?int $actorUserId): void
    {
        $base = $revision->revisionBase;
        if (! $base instanceof Budget || (string) ($base->status ?? '') !== Budget::STATUS_CONVERTED) {
            return;
        }

        foreach (Budget::CONVERTED_REVISION_FIELDS as $field) {
            if ($field === 'itens') {
                continue;
            }
            $base->{$field} = $revision->{$field};
        }
        $base->versao = $revision->versao;
        $base->atualizado_por = $actorUserId;
        $base->save();

        BudgetItem::query()->where('orcamento_id', $base->id)->delete();
        $rows = $this->itemRowsFor($revision, (int) $base->id);
        if ($rows !== []) {
            BudgetItem::query()->insert($rows);
        }

        $revision->forceFill(['aplicada_em' => now()])->save();

        $osId = (int) ($base->os_id ?? 0);
        if ($osId <= 0) {
            return;
        }

        // Só valores, nunca status — a OS já avançou (ou não) por fora do
        // ciclo de aprovação da revisão. Ver o guard equivalente em
        // BudgetOrderSyncService::syncFromBudget().
        $this->budgetOrderSyncService->syncFinancialsFromBudget($base, $osId);

        $order = Order::query()->find($osId);
        if (
            $order instanceof Order
            && in_array(trim((string) $order->status), OrderStatus::FINANCIAL_IMPACT_CLOSURE_CODES, true)
        ) {
            $this->financeiroService->correctReceivableTitleForOrder($order, (float) $base->total);
        }

        $this->osMargemService->calcularParaOs($osId);

        $this->orderEventService->record(
            $osId,
            OrderEvent::CATEGORIA_ORCAMENTO,
            OrderEvent::TIPO_ORCAMENTO_REVISAO_APLICADA,
            'Revisão de orçamento aplicada',
            sprintf(
                'Revisão %s aprovada pelo cliente e aplicada ao orçamento convertido %s (novo total R$ %s).',
                $revision->numero,
                $base->numero,
                number_format((float) $base->total, 2, ',', '.')
            ),
            [
                'orcamento_base_id' => (int) $base->id,
                'orcamento_base_numero' => (string) $base->numero,
                'revisao_id' => (int) $revision->id,
                'revisao_numero' => (string) $revision->numero,
                'total_novo' => round((float) $base->total, 2),
            ],
            $actorUserId
        );
    }
}
