<?php

namespace App\Services\Orders;

use App\Models\CrmFollowup;
use App\Models\Financeiro;
use App\Models\FinanceiroMovimentoCartao;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\OrderStatusHistory;
use App\Models\OsCobrancaAgendamento;
use App\Models\OsMargem;
use App\Models\User;
use App\Services\Channels\Whatsapp\WhatsappMessagingService;
use App\Services\Financeiro\FinanceiroCartaoService;
use App\Services\Financeiro\FinanceiroService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class OrderClosureService
{
    private const NO_REPAIR_STATUSES = ['devolvido_sem_reparo', 'descartado'];

    private const COLLECTION_SCHEDULE_DAYS = [1, 3, 5];

    private const RETURN_FOLLOWUP_DEFAULT_DAYS = 180;

    private const PENDING_PAYMENT_STATUS = 'entregue_pagamento_pendente';

    // Encerramento como "Equipamento Entregue": exige ao menos algum valor
    // recebido (antes desta baixa OU nesta ação). Ver close().
    private const DELIVERED_STATUS = 'equipamento_entregue';

    public function __construct(
        private readonly OrderWorkflowService $orderWorkflowService,
        private readonly FinanceiroService $financeiroService,
        private readonly FinanceiroCartaoService $financeiroCartaoService,
        private readonly WhatsappMessagingService $whatsappMessagingService,
        private readonly OrderClosurePdfService $orderClosurePdfService,
        private readonly OrderEventService $orderEventService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(int $orderId, User $actor): array
    {
        $order = Order::query()->with(['client', 'statusCatalog'])->find($orderId);

        if (! $order instanceof Order) {
            return ['result' => 'not_found'];
        }

        if (! $this->orderWorkflowService->canAccessOrder($actor, $order)) {
            return ['result' => 'forbidden'];
        }

        return [
            'result' => 'ok',
            'order' => $this->mapOrderSummary($order),
            'cliente_telefone' => trim((string) ($order->client?->telefone1 ?? '')),
            'opcoes_encerramento' => $this->closureOptions(),
            'financeiro' => $this->financialSummary($order),
            'custo_summary' => $this->buildCostSummary((int) $order->id),
            'retorno_padrao' => Carbon::now()->addDays(self::RETURN_FOLLOWUP_DEFAULT_DAYS)->toDateString(),
            'cartao' => $this->financeiroCartaoService->buildActiveDataset(),
            'status_pagamento_pendente' => $this->pendingPaymentStatusInfo(),
            'status_sem_reparo' => self::NO_REPAIR_STATUSES,
            'status_entregue' => self::DELIVERED_STATUS,
        ];
    }

    /**
     * @return array{codigo: string, nome: string}
     */
    private function pendingPaymentStatusInfo(): array
    {
        $nome = OrderStatus::query()
            ->where('codigo', self::PENDING_PAYMENT_STATUS)
            ->value('nome');

        return [
            'codigo' => self::PENDING_PAYMENT_STATUS,
            'nome' => (string) ($nome ?? 'Entregue - Pendência Financeira'),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function close(int $orderId, User $actor, array $payload): array
    {
        $order = Order::query()->find($orderId);

        if (! $order instanceof Order) {
            return ['result' => 'not_found'];
        }

        $encerrarComo = trim((string) ($payload['encerrar_como'] ?? ''));
        $statusRow = OrderStatus::query()
            ->active()
            ->where('status_final', true)
            ->where('codigo', $encerrarComo)
            ->first();

        if (! $statusRow instanceof OrderStatus) {
            return ['result' => 'invalid_status'];
        }

        $dataEntrega = $this->normalizeDate($payload['data_entrega'] ?? null);
        if ($dataEntrega === null) {
            return ['result' => 'invalid_date'];
        }

        $isNoRepairClosure = in_array($encerrarComo, self::NO_REPAIR_STATUSES, true);

        // Devolvido sem reparo / descartado nunca geram lancamento financeiro:
        // ignora qualquer recebimento enviado (defesa em profundidade — o
        // frontend ja bloqueia essa etapa, mas a regra de negocio precisa
        // valer no backend independente do que o cliente HTTP mandar).
        $recebimentos = $isNoRepairClosure
            ? []
            : $this->normalizeReceipts(is_array($payload['recebimentos'] ?? null) ? $payload['recebimentos'] : []);

        // Simula os recebimentos em cartao ANTES da transacao: falha rapido sem
        // efeito colateral nenhum se a combinacao operadora/bandeira/parcelas
        // nao tiver taxa ativa configurada.
        $simulation = $this->simulateCardPayments($recebimentos);
        if (! $simulation['ok']) {
            return ['result' => $simulation['result'], 'message' => $simulation['message']];
        }
        $recebimentos = $simulation['recebimentos'];

        // Encerrar como "Equipamento Entregue" exige que a OS tenha algum valor
        // recebido — seja de baixas/adiantamentos anteriores (valor_movimentado)
        // ou nesta ação. Pagamento parcial é aceito (o saldo restante segue como
        // pendência financeira); só bloqueia a entrega com ZERO recebido. Não se
        // aplica a devolução sem reparo / descarte nem aos demais encerramentos.
        if ($encerrarComo === self::DELIVERED_STATUS) {
            $recebidoAntes = round((float) ($this->financialSummary($order)['valor_movimentado'] ?? 0), 2);
            $recebidoNesta = array_reduce(
                $recebimentos,
                static fn (float $total, array $recebimento): float => $total + (float) ($recebimento['valor'] ?? 0),
                0.0
            );

            if ($recebidoAntes + $recebidoNesta <= 0.009) {
                return ['result' => 'delivery_requires_payment'];
            }
        }

        $observacao = trim((string) ($payload['observacao'] ?? ''));
        $agendarRetorno = filter_var($payload['agendar_retorno'] ?? false, FILTER_VALIDATE_BOOL);
        $retornoData = $this->normalizeDate($payload['retorno_data'] ?? null)
            ?? Carbon::now()->addDays(self::RETURN_FOLLOWUP_DEFAULT_DAYS)->toDateString();

        try {
            $result = DB::transaction(function () use (
                $order,
                $actor,
                $encerrarComo,
                $observacao,
                $dataEntrega,
                $recebimentos,
                $isNoRepairClosure
            ): array {
                ['titulo' => $titulo, 'saldo_aberto' => $saldoAberto] = $this->processReceipts($order, $recebimentos, $dataEntrega);
                $temSaldoPendente = $saldoAberto > 0.009 && ! $isNoRepairClosure;

                $statusAplicado = $temSaldoPendente ? self::PENDING_PAYMENT_STATUS : $encerrarComo;

                $statusResult = $this->orderWorkflowService->updateStatus(
                    (int) $order->id,
                    $actor,
                    $statusAplicado,
                    $observacao !== '' ? $observacao : null,
                    viaClosureFlow: true
                );

                if (($statusResult['result'] ?? 'error') !== 'ok') {
                    return $statusResult;
                }

                $now = Carbon::now();
                Order::query()->whereKey($order->id)->update([
                    'data_entrega' => $dataEntrega,
                    'baixa_tecnica_em' => $now,
                    'baixa_tecnica_por' => (int) $actor->id,
                    'status_final_pendente_pagamento' => $temSaldoPendente ? $encerrarComo : null,
                    'updated_at' => $now,
                ]);

                if ($temSaldoPendente) {
                    $this->schedulePendingCollections((int) $order->id, (int) $titulo->id, (int) $order->cliente_id);
                } else {
                    $this->cancelPendingCollections((int) $order->id);
                }

                $this->orderEventService->record(
                    (int) $order->id,
                    OrderEvent::CATEGORIA_REGISTRO,
                    OrderEvent::TIPO_FECHAMENTO_CONCLUIDO,
                    'Fechamento da OS concluído',
                    sprintf('Baixa concluída como "%s".', $encerrarComo)
                        . ($temSaldoPendente ? sprintf(' Saldo pendente: R$ %s.', number_format($saldoAberto, 2, ',', '.')) : ''),
                    [
                        'encerrar_como' => $encerrarComo,
                        'status_aplicado' => $statusAplicado,
                        'data_entrega' => $dataEntrega,
                        'valor_titulo' => round((float) $titulo->valor, 2),
                        'saldo_pendente' => round($saldoAberto, 2),
                        'recebimentos' => count($recebimentos),
                    ],
                    (int) $actor->id,
                    OrderEvent::ORIGEM_USUARIO,
                    $now
                );

                return [
                    'result' => 'ok',
                    'saldo_aberto' => $saldoAberto,
                    'status_aplicado' => $statusAplicado,
                    'titulo_valor' => round((float) $titulo->valor, 2),
                ];
            });
        } catch (Throwable $exception) {
            logger()->error('[API V1][ORDERS][CLOSURE] Falha ao concluir a baixa', [
                'order_id' => $orderId,
                'message' => $exception->getMessage(),
            ]);

            return ['result' => 'closure_failed'];
        }

        if (($result['result'] ?? 'error') !== 'ok') {
            return $result;
        }

        if ($agendarRetorno) {
            $this->createReturnFollowup((int) $order->id, $retornoData, (int) $actor->id);
        }

        $notificacaoEnviada = null;
        if (filter_var($payload['notificar_cliente'] ?? false, FILTER_VALIDATE_BOOL)) {
            $notificacaoEnviada = $this->sendClosureNotification(
                $order,
                (string) $result['status_aplicado'],
                $dataEntrega,
                $observacao,
                $recebimentos,
                (float) $result['saldo_aberto'],
                (float) $result['titulo_valor']
            );
        }

        $updatedOrder = Order::query()->with(['client', 'statusCatalog'])->find($order->id);

        return [
            'result' => 'ok',
            'order' => $updatedOrder instanceof Order ? $this->mapOrderSummary($updatedOrder) : null,
            'notificacao_enviada' => $notificacaoEnviada,
        ];
    }

    /**
     * Registra um Adiantamento/Sinal contra a OS SEM fechar o atendimento —
     * ao contrário de close(), nunca aplica um dos 3 OrderStatus::closureCodes().
     * Caminho paralelo a close(), usado quando a classificação da baixa (tela
     * de baixa) é "adiantamento" ou "sinal" em vez de "baixa".
     *
     * Se o equipamento foi marcado como entregue (com data), o status vira
     * 'entregue_pagamento_pendente' — fora dos 3 códigos de fechamento, então a
     * OS continua contando como aberta (tem pendência financeira). Sem marcar,
     * o status da OS não muda em nada; só o valor é lançado no financeiro.
     *
     * A OS só fecha de verdade depois, quando alguém fizer uma Baixa de
     * verdade (classificação=baixa) — aí sim escolhendo o status final real.
     * Ver skill sistema-erp-os-fluxo-fechamento, seção "Adiantamento/Sinal sem
     * fechar a OS".
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function registerAdvance(int $orderId, User $actor, array $payload): array
    {
        $order = Order::query()->find($orderId);

        if (! $order instanceof Order) {
            return ['result' => 'not_found'];
        }

        if (! $this->orderWorkflowService->canAccessOrder($actor, $order)) {
            return ['result' => 'forbidden'];
        }

        // Defesa em profundidade: uma OS ja encerrada de verdade nao recebe
        // lancamento nem mudanca de status por este caminho — o unico jeito de
        // mexer numa OS encerrada e' cancelClosure().
        if (in_array(trim((string) ($order->status ?? '')), OrderStatus::closureCodes(), true)) {
            return ['result' => 'order_is_closed'];
        }

        $recebimentos = $this->normalizeReceipts(is_array($payload['recebimentos'] ?? null) ? $payload['recebimentos'] : []);
        if ($recebimentos === []) {
            return ['result' => 'invalid_receipts'];
        }

        $simulation = $this->simulateCardPayments($recebimentos);
        if (! $simulation['ok']) {
            return ['result' => $simulation['result'], 'message' => $simulation['message']];
        }
        $recebimentos = $simulation['recebimentos'];

        $observacao = trim((string) ($payload['observacao'] ?? ''));
        $equipamentoEntregue = filter_var($payload['equipamento_entregue'] ?? false, FILTER_VALIDATE_BOOL);
        $dataEntrega = $equipamentoEntregue ? $this->normalizeDate($payload['data_entrega'] ?? null) : null;

        if ($equipamentoEntregue && $dataEntrega === null) {
            return ['result' => 'invalid_date'];
        }

        $dataReferencia = $dataEntrega ?? Carbon::now()->toDateString();
        $classificacao = trim((string) ($payload['classificacao_baixa'] ?? 'adiantamento'));

        try {
            $result = DB::transaction(function () use (
                $order,
                $actor,
                $observacao,
                $recebimentos,
                $equipamentoEntregue,
                $dataEntrega,
                $dataReferencia,
                $classificacao
            ): array {
                ['titulo' => $titulo, 'saldo_aberto' => $saldoAberto] = $this->processReceipts($order, $recebimentos, $dataReferencia);

                $totalLancado = round(array_sum(array_map(
                    static fn (array $recebimento): float => (float) ($recebimento['valor'] ?? 0),
                    $recebimentos
                )), 2);

                $this->orderEventService->record(
                    (int) $order->id,
                    OrderEvent::CATEGORIA_FINANCEIRO,
                    OrderEvent::TIPO_ADIANTAMENTO_REGISTRADO,
                    $classificacao === 'sinal' ? 'Sinal registrado' : 'Adiantamento registrado',
                    sprintf(
                        'R$ %s lançado(s) sem encerrar a OS. Saldo restante: R$ %s.',
                        number_format($totalLancado, 2, ',', '.'),
                        number_format($saldoAberto, 2, ',', '.')
                    ),
                    [
                        'classificacao' => $classificacao,
                        'valor_lancado' => $totalLancado,
                        'saldo_restante' => round($saldoAberto, 2),
                        'recebimentos' => count($recebimentos),
                        'equipamento_entregue' => $equipamentoEntregue,
                        'data_entrega' => $dataEntrega,
                    ],
                    (int) $actor->id
                );

                if ($equipamentoEntregue && $dataEntrega !== null) {
                    // viaClosureFlow: true pelo mesmo motivo de close() — o
                    // equipamento pode ser marcado como entregue a partir de
                    // QUALQUER etapa aberta da OS, entao pula a validacao do
                    // catalogo de transicoes (que so cobre alguns status de
                    // origem especificos). Seguro: entregue_pagamento_pendente
                    // nao esta em OrderStatus::closureCodes() e o status atual
                    // ja foi validado acima como fora de closureCodes(), entao
                    // nenhuma das duas checagens que viaClosureFlow pula
                    // (destino/origem encerrados) jamais seria relevante aqui.
                    $statusResult = $this->orderWorkflowService->updateStatus(
                        (int) $order->id,
                        $actor,
                        self::PENDING_PAYMENT_STATUS,
                        $observacao !== '' ? $observacao : null,
                        viaClosureFlow: true
                    );

                    if (($statusResult['result'] ?? 'error') !== 'ok') {
                        return $statusResult;
                    }

                    $now = Carbon::now();
                    Order::query()->whereKey($order->id)->update([
                        'data_entrega' => $dataEntrega,
                        'baixa_tecnica_em' => $now,
                        'baixa_tecnica_por' => (int) $actor->id,
                        'updated_at' => $now,
                    ]);

                    $this->schedulePendingCollections((int) $order->id, (int) $titulo->id, (int) $order->cliente_id);
                }

                return [
                    'result' => 'ok',
                    'saldo_aberto' => $saldoAberto,
                    'titulo_valor' => round((float) $titulo->valor, 2),
                ];
            });
        } catch (Throwable $exception) {
            logger()->error('[API V1][ORDERS][CLOSURE] Falha ao registrar adiantamento/sinal', [
                'order_id' => $orderId,
                'message' => $exception->getMessage(),
            ]);

            return ['result' => 'closure_failed'];
        }

        if (($result['result'] ?? 'error') !== 'ok') {
            return $result;
        }

        $notificacaoEnviada = null;
        if (filter_var($payload['notificar_cliente'] ?? false, FILTER_VALIDATE_BOOL)) {
            $notificacaoEnviada = $this->sendAdvanceNotification(
                $order,
                $equipamentoEntregue,
                (float) $result['saldo_aberto']
            );
        }

        // Sino: registra o recebimento parcial para o autor e o tecnico da OS
        // (a baixa de verdade ja e' coberta pela notificacao de mudanca de
        // status; adiantamento/sinal sem entrega nao muda status nenhum).
        $totalLancado = round(array_sum(array_map(
            static fn (array $recebimento): float => (float) ($recebimento['valor'] ?? 0),
            $recebimentos
        )), 2);
        $this->orderWorkflowService->notifyOrderUsers(
            $order,
            $actor,
            'os.advance_received',
            $classificacao === 'sinal' ? 'Sinal recebido' : 'Adiantamento recebido',
            sprintf(
                'R$ %s recebido na OS %s. Saldo restante: R$ %s.',
                number_format($totalLancado, 2, ',', '.'),
                (string) ($order->numero_os ?: ('#' . $order->id)),
                number_format((float) $result['saldo_aberto'], 2, ',', '.')
            ),
            [
                'icon' => 'cash-coin',
                'classificacao' => $classificacao,
                'valor_lancado' => $totalLancado,
                'saldo_restante' => round((float) $result['saldo_aberto'], 2),
            ]
        );

        $updatedOrder = Order::query()->with(['client', 'statusCatalog'])->find($order->id);

        return [
            'result' => 'ok',
            'order' => $updatedOrder instanceof Order ? $this->mapOrderSummary($updatedOrder) : null,
            'notificacao_enviada' => $notificacaoEnviada,
        ];
    }

    /**
     * Cancela a baixa de uma OS feita por engano: reverte o status para o
     * estado pre-baixa e EXCLUI completamente todos os artefatos financeiros
     * criados na ocasiao da baixa (titulo a receber, movimentos, meta de cartao
     * e despesas de taxa) — eles somem de Lancamentos, Fluxo de Caixa, DREs e
     * Margem. Ver skill sistema-erp-os-fluxo-fechamento.
     *
     * Regra de negocio: cancelar a baixa e' apenas para engano. Se o equipamento
     * realmente foi entregue/descartado e depois retornar, abre-se uma NOVA OS.
     *
     * @return array<string, mixed>
     */
    public function cancelClosure(int $orderId, User $actor, ?User $verifiedAdmin = null): array
    {
        $order = Order::query()->find($orderId);

        if (! $order instanceof Order) {
            return ['result' => 'not_found'];
        }

        if (! $this->orderWorkflowService->canAccessOrder($actor, $order)) {
            return ['result' => 'forbidden'];
        }

        $currentStatus = trim((string) ($order->status ?? ''));
        if (! in_array($currentStatus, OrderStatus::closureCodes(), true)) {
            return ['result' => 'not_closed'];
        }

        // Status pre-baixa = origem da ultima transicao que levou a OS ao status
        // fechado atual (o bloqueio de mudanca de status garante que a baixa foi
        // a ultima movimentacao de status desta OS).
        $closureHistory = OrderStatusHistory::query()
            ->where('os_id', $orderId)
            ->where('status_novo', $currentStatus)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $previousStatus = trim((string) ($closureHistory->status_anterior ?? ''));
        $previousStatusRow = $previousStatus !== '' ? OrderStatus::activeByCode($previousStatus) : null;

        if (! $previousStatusRow instanceof OrderStatus) {
            return ['result' => 'cannot_resolve_previous_status'];
        }

        $observacao = 'Baixa da OS cancelada: status revertido e lançamentos da baixa excluídos.';
        if ($verifiedAdmin instanceof User) {
            $observacao .= ' Autorizado por administrador: ' . trim((string) ($verifiedAdmin->nome ?? '')) . ' <' . trim((string) ($verifiedAdmin->email ?? '')) . '>.';
        }

        try {
            DB::transaction(function () use ($order, $orderId, $actor, $currentStatus, $previousStatus, $previousStatusRow, $observacao): void {
                // 1) Exclui os lancamentos/movimentos/taxas criados na baixa.
                $this->deleteClosureFinancials($orderId);

                // 2) Remove margem, cobrancas agendadas e followup de retorno.
                OsMargem::query()->where('os_id', $orderId)->delete();
                $this->cancelPendingCollections($orderId);
                CrmFollowup::query()
                    ->where('os_id', $orderId)
                    ->where('status', CrmFollowup::STATUS_PENDENTE)
                    ->where('origem_evento', 'like', 'os_retorno_agendado_%')
                    ->delete();

                // 3) Reverte a OS ao estado pre-baixa.
                $now = Carbon::now();
                $estadoFluxo = trim((string) ($previousStatusRow->estado_fluxo_padrao ?? '')) ?: 'em_atendimento';
                Order::query()->whereKey($orderId)->update([
                    'status' => $previousStatus,
                    'estado_fluxo' => $estadoFluxo,
                    'data_entrega' => null,
                    'baixa_tecnica_em' => null,
                    'baixa_tecnica_por' => null,
                    'status_final_pendente_pagamento' => null,
                    'status_atualizado_em' => $now,
                    'updated_at' => $now,
                ]);

                // 4) Registra a reversao no historico da OS (trilha de auditoria).
                OrderStatusHistory::query()->create([
                    'os_id' => $orderId,
                    'status_anterior' => $currentStatus,
                    'status_novo' => $previousStatus,
                    'estado_fluxo' => $estadoFluxo,
                    'usuario_id' => (int) $actor->id,
                    'observacao' => $observacao,
                    'created_at' => $now,
                ]);

                $this->orderEventService->record(
                    $orderId,
                    OrderEvent::CATEGORIA_STATUS,
                    OrderEvent::TIPO_FECHAMENTO_CANCELADO,
                    'Fechamento (baixa) cancelado',
                    $observacao,
                    [
                        'status_anterior' => $currentStatus,
                        'status_restaurado' => $previousStatus,
                    ],
                    (int) $actor->id,
                    OrderEvent::ORIGEM_USUARIO,
                    $now
                );
            });
        } catch (Throwable $exception) {
            logger()->error('[API V1][ORDERS][CLOSURE] Falha ao cancelar a baixa', [
                'order_id' => $orderId,
                'message' => $exception->getMessage(),
            ]);

            return ['result' => 'cancel_failed'];
        }

        logger()->info('[API V1][ORDERS][CLOSURE] Baixa cancelada', [
            'order_id' => $orderId,
            'user_id' => (int) $actor->id,
            'admin_verificado_id' => $verifiedAdmin instanceof User ? (int) $verifiedAdmin->id : null,
            'status_anterior' => $currentStatus,
            'status_revertido' => $previousStatus,
        ]);

        $updatedOrder = Order::query()->with(['client', 'statusCatalog'])->find($orderId);

        return [
            'result' => 'ok',
            'order' => $updatedOrder instanceof Order ? $this->mapOrderSummary($updatedOrder) : null,
            'status_revertido' => $previousStatus,
        ];
    }

    /**
     * Exclui (hard delete) os artefatos financeiros criados no fechamento da OS:
     * o(s) titulo(s) a receber e seus movimentos + meta de cartao, e as despesas
     * de taxa de cartao geradas na baixa (marcador origem_tipo).
     */
    private function deleteClosureFinancials(int $orderId): void
    {
        // Titulos a receber da OS (criados na baixa via ensureReceivableTitle,
        // unico criador) — apaga meta de cartao dos movimentos, os movimentos e
        // o proprio titulo.
        $titulosReceber = Financeiro::query()
            ->where('os_id', $orderId)
            ->where('tipo', Financeiro::TIPO_RECEBER)
            ->get();

        // Despesas de taxa de cartao registradas na baixa (registerCardFeeExpense).
        $despesasTaxa = Financeiro::query()
            ->where('os_id', $orderId)
            ->where('origem_tipo', 'os_recebimento_cartao')
            ->get();

        // Snapshot auditavel ANTES do hard delete — depois nao ha mais como
        // saber o que foi removido.
        $titulosRemovidos = [];
        $totalMovimentos = 0;

        foreach ($titulosReceber->merge($despesasTaxa) as $titulo) {
            $movimentoIds = $titulo->movimentos()->pluck('id');
            $titulosRemovidos[] = [
                'financeiro_id' => (int) $titulo->id,
                'tipo' => (string) $titulo->tipo,
                'descricao' => (string) $titulo->descricao,
                'valor' => round((float) $titulo->valor, 2),
                'movimentos' => $movimentoIds->count(),
            ];
            $totalMovimentos += $movimentoIds->count();

            if ($movimentoIds->isNotEmpty()) {
                FinanceiroMovimentoCartao::query()->whereIn('movimento_id', $movimentoIds)->delete();
            }
            $titulo->movimentos()->delete();
            $titulo->delete();
        }

        if ($titulosRemovidos !== []) {
            $this->orderEventService->record(
                $orderId,
                OrderEvent::CATEGORIA_FINANCEIRO,
                OrderEvent::TIPO_FINANCEIRO_FECHAMENTO_REMOVIDO,
                'Lançamentos do fechamento removidos',
                sprintf(
                    '%d título(s) e %d movimento(s) excluídos no cancelamento da baixa.',
                    count($titulosRemovidos),
                    $totalMovimentos
                ),
                ['titulos' => $titulosRemovidos]
            );
        }
    }

    /**
     * Processa cobrancas agendadas vencidas (D+1/D+3/D+5). Chamado pelo
     * comando agendado `app:process-pending-os-collections`.
     *
     * @return array<string, int>
     */
    public function processPendingChargeNotifications(): array
    {
        $summary = [
            'agendamentos_lidos' => 0,
            'agendamentos_enviados' => 0,
            'agendamentos_cancelados' => 0,
            'agendamentos_com_erro' => 0,
        ];

        $rows = OsCobrancaAgendamento::query()
            ->whereIn('status', [OsCobrancaAgendamento::STATUS_PENDENTE, OsCobrancaAgendamento::STATUS_ERRO])
            ->where('enviar_em', '<=', Carbon::now())
            ->orderBy('enviar_em')
            ->limit(200)
            ->get();

        $summary['agendamentos_lidos'] = $rows->count();

        foreach ($rows as $row) {
            $order = Order::query()->with('client')->find($row->os_id);
            $titulo = $row->financeiro_id ? Financeiro::query()->find($row->financeiro_id) : null;

            if (! $order instanceof Order || ! $titulo instanceof Financeiro || (string) $order->status !== self::PENDING_PAYMENT_STATUS) {
                $row->update(['status' => OsCobrancaAgendamento::STATUS_CANCELADO, 'ultima_tentativa_em' => Carbon::now()]);
                $summary['agendamentos_cancelados']++;

                continue;
            }

            $movementSummary = $this->financeiroService->movementSummary($titulo);
            $saldoAberto = round((float) ($movementSummary['valor_aberto'] ?? $titulo->valor ?? 0), 2);

            if ($saldoAberto <= 0.009) {
                $row->update(['status' => OsCobrancaAgendamento::STATUS_CANCELADO, 'ultima_tentativa_em' => Carbon::now()]);
                $summary['agendamentos_cancelados']++;

                continue;
            }

            $telefone = trim((string) ($order->client?->telefone1 ?? ''));
            if ($telefone === '') {
                $row->update([
                    'status' => OsCobrancaAgendamento::STATUS_ERRO,
                    'ultima_tentativa_em' => Carbon::now(),
                    'retorno_payload' => json_encode(['ok' => false, 'message' => 'Cliente sem telefone cadastrado.'], JSON_UNESCAPED_UNICODE),
                ]);
                $summary['agendamentos_com_erro']++;

                continue;
            }

            $mensagem = $this->buildPendingChargeMessage($order, $saldoAberto, (int) $row->prazo_dias);

            try {
                $send = $this->whatsappMessagingService->sendSystemMessage(
                    $telefone,
                    $mensagem,
                    [],
                    trim((string) ($order->client?->nome_razao ?? '')) ?: null,
                    (int) ($order->cliente_id ?? 0) > 0 ? (int) $order->cliente_id : null,
                    [
                        'origin' => 'os_pending_charge',
                        'os_id' => (int) $order->id,
                        'agendamento_id' => (int) $row->id,
                    ]
                );
            } catch (Throwable $exception) {
                $send = ['ok' => false, 'message' => $exception->getMessage()];
            }

            $update = [
                'ultima_tentativa_em' => Carbon::now(),
                'mensagem_enviada' => $mensagem,
                'retorno_payload' => json_encode($send, JSON_UNESCAPED_UNICODE),
            ];

            if ($send['ok'] ?? false) {
                $update['status'] = OsCobrancaAgendamento::STATUS_ENVIADO;
                $update['enviado_em'] = Carbon::now();
                $summary['agendamentos_enviados']++;

                $this->orderEventService->record(
                    (int) $order->id,
                    OrderEvent::CATEGORIA_MENSAGEM,
                    OrderEvent::TIPO_COBRANCA_ENVIADA,
                    'Cobrança automática enviada',
                    sprintf('Lembrete de saldo pendente (D+%d) enviado por WhatsApp.', (int) $row->prazo_dias),
                    [
                        'agendamento_id' => (int) $row->id,
                        'prazo_dias' => (int) $row->prazo_dias,
                        'saldo_pendente' => round($saldoAberto, 2),
                        'destino' => $telefone,
                    ],
                    null,
                    OrderEvent::ORIGEM_AUTOMACAO
                );
            } else {
                $update['status'] = OsCobrancaAgendamento::STATUS_ERRO;
                $summary['agendamentos_com_erro']++;
            }

            $row->update($update);
        }

        return $summary;
    }

    /**
     * @param array<int, mixed> $rawReceipts
     * @return array<int, array<string, mixed>>
     */
    private function normalizeReceipts(array $rawReceipts): array
    {
        $normalized = [];

        foreach ($rawReceipts as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $valor = round((float) ($raw['valor'] ?? 0), 2);
            if ($valor <= 0) {
                continue;
            }

            $normalized[] = [
                'valor' => $valor,
                'forma_pagamento' => trim((string) ($raw['forma_pagamento'] ?? '')),
                'data_pagamento' => $this->normalizeDate($raw['data_pagamento'] ?? null),
                'observacoes' => trim((string) ($raw['observacoes'] ?? '')),
                'operadora_id' => (int) ($raw['operadora_id'] ?? 0) > 0 ? (int) $raw['operadora_id'] : null,
                'bandeira_id' => (int) ($raw['bandeira_id'] ?? 0) > 0 ? (int) $raw['bandeira_id'] : null,
                'modalidade' => trim((string) ($raw['modalidade'] ?? '')),
                'parcelas' => max(1, (int) ($raw['parcelas'] ?? 1)),
            ];
        }

        return $normalized;
    }

    private function isCardPayment(string $formaPagamento): bool
    {
        return str_contains(strtolower($formaPagamento), 'cartao');
    }

    /**
     * Simula os recebimentos em cartão (compartilhado por close() e
     * registerAdvance()) ANTES de abrir a transação: falha rápido, sem efeito
     * colateral nenhum, se a combinação operadora/bandeira/parcelas não tiver
     * taxa ativa configurada.
     *
     * @param array<int, array<string, mixed>> $recebimentos
     * @return array{ok: true, recebimentos: array<int, array<string, mixed>>}|array{ok: false, result: string, message: string}
     */
    private function simulateCardPayments(array $recebimentos): array
    {
        foreach ($recebimentos as $index => $recebimento) {
            if (! $this->isCardPayment($recebimento['forma_pagamento'])) {
                continue;
            }

            try {
                $recebimentos[$index]['simulation'] = $this->financeiroCartaoService->simulate([
                    'valor_bruto' => $recebimento['valor'],
                    'operadora_id' => $recebimento['operadora_id'],
                    'bandeira_id' => $recebimento['bandeira_id'],
                    'modalidade' => $recebimento['modalidade'],
                    'forma_pagamento' => $recebimento['forma_pagamento'],
                    'parcelas' => $recebimento['parcelas'],
                ]);
            } catch (Throwable $exception) {
                return ['ok' => false, 'result' => 'invalid_card_payment', 'message' => $exception->getMessage()];
            }
        }

        return ['ok' => true, 'recebimentos' => $recebimentos];
    }

    /**
     * Lança os recebimentos contra o título a receber da OS (criando-o se
     * ainda não existir) — compartilhado por close() e registerAdvance(): é
     * exatamente o mesmo efeito financeiro, só muda o que acontece com o
     * status da OS ao redor desta chamada.
     *
     * @param array<int, array<string, mixed>> $recebimentos
     * @return array{titulo: Financeiro, saldo_aberto: float}
     */
    private function processReceipts(Order $order, array $recebimentos, string $dataReferencia): array
    {
        $titulo = $this->ensureReceivableTitle($order, $dataReferencia);

        foreach ($recebimentos as $recebimento) {
            $movementSummary = $this->financeiroService->registerMovement($titulo, [
                'valor_movimento' => $recebimento['valor'],
                'data_movimento' => $recebimento['data_pagamento'] ?? $dataReferencia,
                'forma_pagamento' => $recebimento['forma_pagamento'] !== '' ? $recebimento['forma_pagamento'] : null,
                'observacoes' => $recebimento['observacoes'] !== '' ? $recebimento['observacoes'] : null,
            ]);

            $movementId = (int) ($movementSummary['movement_id'] ?? 0);

            if ($movementId > 0 && isset($recebimento['simulation'])) {
                $this->registerCardMovementMeta($movementId, $recebimento['simulation'], $recebimento);
                $this->registerCardFeeExpense($order, $recebimento['simulation'], $movementId);
            }
        }

        $resumoFinanceiro = $this->financeiroService->movementSummary($titulo->refresh());
        $saldoAberto = round((float) ($resumoFinanceiro['valor_aberto'] ?? 0), 2);

        return ['titulo' => $titulo, 'saldo_aberto' => $saldoAberto];
    }

    /**
     * @param array<string, mixed> $simulation
     * @param array<string, mixed> $recebimento
     */
    private function registerCardMovementMeta(int $movementId, array $simulation, array $recebimento): void
    {
        FinanceiroMovimentoCartao::query()->create([
            'movimento_id' => $movementId,
            'operadora_id' => $simulation['operadora_id'] ?? null,
            'bandeira_id' => $simulation['bandeira_id'] ?? null,
            'taxa_id' => $simulation['taxa_id'] ?? null,
            'modalidade' => (string) ($simulation['modalidade'] ?? 'credito'),
            'parcelas' => (int) ($simulation['parcelas'] ?? 1),
            'valor_bruto' => round((float) ($simulation['valor_bruto'] ?? 0), 2),
            'taxa_percentual' => round((float) ($simulation['taxa_percentual'] ?? 0), 4),
            'taxa_fixa' => round((float) ($simulation['taxa_fixa'] ?? 0), 2),
            'valor_taxa' => round((float) ($simulation['valor_taxa'] ?? 0), 2),
            'valor_liquido' => round((float) ($simulation['valor_liquido'] ?? 0), 2),
            'prazo_recebimento_dias' => (int) ($simulation['prazo_recebimento_dias'] ?? 0),
            'data_competencia' => $recebimento['data_pagamento'] ?? null,
            'data_prevista_repasse' => $simulation['data_prevista_repasse'] ?? null,
            'data_prevista_recebimento' => $simulation['data_prevista_recebimento'] ?? null,
            'data_credito_efetivo' => $simulation['data_credito_efetivo'] ?? null,
            'observacoes' => $recebimento['observacoes'] !== '' ? $recebimento['observacoes'] : null,
        ]);
    }

    /**
     * @param array<string, mixed> $simulation
     */
    private function registerCardFeeExpense(Order $order, array $simulation, int $movementId): void
    {
        $valorTaxa = round((float) ($simulation['valor_taxa'] ?? 0), 2);
        if ($valorTaxa <= 0) {
            return;
        }

        $parcelas = (int) ($simulation['parcelas'] ?? 1);

        $taxaFinanceiro = Financeiro::query()->create([
            'os_id' => (int) $order->id,
            'avulso' => false,
            'tipo' => Financeiro::TIPO_PAGAR,
            'categoria' => 'Taxa de cartão',
            'descricao' => sprintf(
                'Taxa %s - OS %s (%s%s)',
                (string) ($simulation['operadora_nome'] ?? ''),
                (string) $order->numero_os,
                (string) ($simulation['modalidade_label'] ?? ''),
                $parcelas > 1 ? ' em ' . $parcelas . 'x' : ''
            ),
            'valor' => $valorTaxa,
            'status' => Financeiro::STATUS_PAGO,
            'origem_tipo' => 'os_recebimento_cartao',
            'origem_id' => $movementId,
            'data_vencimento' => $simulation['data_prevista_repasse'] ?? null,
            'data_pagamento' => $simulation['data_prevista_repasse'] ?? null,
            'forma_pagamento' => ($simulation['modalidade'] ?? '') === 'debito' ? 'cartao_debito' : 'cartao_credito',
            'observacoes' => 'Despesa criada automaticamente na baixa da OS para registrar o custo líquido da operadora.',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
            'dre_fixo_mensal' => false,
        ]);

        // Criado via Financeiro::create direto (nao passa por
        // FinanceiroService::create), entao emite o evento aqui.
        $this->orderEventService->record(
            (int) $order->id,
            OrderEvent::CATEGORIA_FINANCEIRO,
            OrderEvent::TIPO_TITULO_CRIADO,
            'Taxa de cartão lançada',
            (string) $taxaFinanceiro->descricao,
            [
                'financeiro_id' => (int) $taxaFinanceiro->id,
                'valor' => $valorTaxa,
                'movimento_origem_id' => $movementId,
            ],
            null,
            OrderEvent::ORIGEM_SISTEMA
        );
    }

    private function schedulePendingCollections(int $orderId, int $financeiroId, ?int $clienteId): int
    {
        $this->cancelPendingCollections($orderId);
        $created = 0;

        foreach (self::COLLECTION_SCHEDULE_DAYS as $prazoDia) {
            OsCobrancaAgendamento::query()->create([
                'os_id' => $orderId,
                'financeiro_id' => $financeiroId,
                'cliente_id' => $clienteId,
                'canal' => 'whatsapp',
                'prazo_dias' => $prazoDia,
                'enviar_em' => Carbon::now()->addDays($prazoDia)->setTime(10, 0),
                'status' => OsCobrancaAgendamento::STATUS_PENDENTE,
            ]);
            $created++;
        }

        if ($created > 0) {
            $this->orderEventService->record(
                $orderId,
                OrderEvent::CATEGORIA_FINANCEIRO,
                OrderEvent::TIPO_COBRANCAS_AGENDADAS,
                'Cobranças automáticas agendadas',
                sprintf('%d cobrança(s) por WhatsApp agendada(s) (D+%s).', $created, implode('/D+', self::COLLECTION_SCHEDULE_DAYS)),
                [
                    'quantidade' => $created,
                    'prazos_dias' => self::COLLECTION_SCHEDULE_DAYS,
                    'financeiro_id' => $financeiroId,
                ],
                null,
                OrderEvent::ORIGEM_SISTEMA
            );
        }

        return $created;
    }

    private function cancelPendingCollections(int $orderId): int
    {
        $cancelled = OsCobrancaAgendamento::query()
            ->where('os_id', $orderId)
            ->whereIn('status', [OsCobrancaAgendamento::STATUS_PENDENTE, OsCobrancaAgendamento::STATUS_ERRO])
            ->update(['status' => OsCobrancaAgendamento::STATUS_CANCELADO, 'updated_at' => Carbon::now()]);

        if ($cancelled > 0) {
            $this->orderEventService->record(
                $orderId,
                OrderEvent::CATEGORIA_FINANCEIRO,
                OrderEvent::TIPO_COBRANCAS_CANCELADAS,
                'Cobranças automáticas canceladas',
                sprintf('%d cobrança(s) pendente(s) cancelada(s).', $cancelled),
                ['quantidade' => $cancelled],
                null,
                OrderEvent::ORIGEM_SISTEMA
            );
        }

        return $cancelled;
    }

    public function createReturnFollowup(int $orderId, string $dataPrevista, ?int $usuarioId = null): ?int
    {
        $order = Order::query()->find($orderId);
        if (! $order instanceof Order) {
            return null;
        }

        $origin = 'os_retorno_agendado_' . $orderId . '_' . Carbon::parse($dataPrevista)->format('Ymd');

        if (CrmFollowup::query()->where('origem_evento', $origin)->exists()) {
            return null;
        }

        $followup = CrmFollowup::query()->create([
            'cliente_id' => (int) ($order->cliente_id ?? 0) > 0 ? (int) $order->cliente_id : null,
            'os_id' => $orderId,
            'titulo' => 'Retorno pós-serviço da OS ' . trim((string) ($order->numero_os ?: ('#' . $orderId))),
            'descricao' => 'Retorno agendado automaticamente na baixa da OS para revisar satisfação e novas necessidades do cliente.',
            'data_prevista' => Carbon::parse($dataPrevista)->setTime(10, 0),
            'status' => CrmFollowup::STATUS_PENDENTE,
            'usuario_responsavel' => $usuarioId,
            'origem_evento' => $origin,
        ]);

        $this->orderEventService->record(
            $orderId,
            OrderEvent::CATEGORIA_REGISTRO,
            OrderEvent::TIPO_RETORNO_AGENDADO,
            'Retorno pós-serviço agendado',
            sprintf('Follow-up agendado para %s.', Carbon::parse($dataPrevista)->format('d/m/Y')),
            [
                'followup_id' => (int) $followup->id,
                'data_prevista' => Carbon::parse($dataPrevista)->toDateString(),
            ],
            $usuarioId,
            OrderEvent::ORIGEM_SISTEMA
        );

        return (int) $followup->id;
    }

    private function buildPendingChargeMessage(Order $order, float $saldoAberto, int $prazoDia): string
    {
        $cliente = trim((string) ($order->client?->nome_razao ?? 'cliente'));
        $numeroOs = trim((string) ($order->numero_os ?: ('#' . $order->id)));

        return sprintf(
            'Olá, %s. A OS %s já foi concluída e ainda consta um saldo pendente de R$ %s. '
                . 'Este é um lembrete automático do %dº dia após a entrega. '
                . 'Se preferir, responda esta mensagem para combinarmos a quitação.',
            $cliente !== '' ? $cliente : 'cliente',
            $numeroOs,
            number_format($saldoAberto, 2, ',', '.'),
            $prazoDia
        );
    }

    private function ensureReceivableTitle(Order $order, string $dataEntrega): Financeiro
    {
        $titulo = Financeiro::query()
            ->where('os_id', $order->id)
            ->where('tipo', Financeiro::TIPO_RECEBER)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($titulo instanceof Financeiro) {
            return $titulo;
        }

        return $this->financeiroService->create([
            'os_id' => (int) $order->id,
            'cliente_id' => (int) $order->cliente_id,
            'avulso' => false,
            'tipo' => Financeiro::TIPO_RECEBER,
            'categoria' => 'Serviço',
            'descricao' => 'Cobrança da OS ' . $order->numero_os,
            'valor' => round((float) ($order->valor_final ?? 0), 2),
            'data_vencimento' => $dataEntrega,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $recebimentos
     */
    private function sendClosureNotification(
        Order $order,
        string $statusAplicadoCodigo,
        string $dataEntrega,
        string $observacaoEncerramento,
        array $recebimentos,
        float $saldoRestante,
        float $valorTitulo
    ): bool {
        $order->loadMissing('client');
        $telefone = trim((string) ($order->client?->telefone1 ?? ''));

        if ($telefone === '') {
            return false;
        }

        $statusNome = (string) (
            OrderStatus::query()->where('codigo', $statusAplicadoCodigo)->value('nome')
                ?? $statusAplicadoCodigo
        );

        $pdf = null;

        try {
            $pdf = $this->orderClosurePdfService->generate($order, [
                'numeroOs' => (string) $order->numero_os,
                'statusFinalNome' => $statusNome,
                'dataEntrega' => $dataEntrega,
                'observacaoEncerramento' => $observacaoEncerramento,
                'valorFinal' => round((float) ($order->valor_final ?? 0), 2),
                'valorTitulo' => round($valorTitulo, 2),
                'saldoRestante' => round($saldoRestante, 2),
                'recebimentos' => $recebimentos,
            ]);
        } catch (Throwable $exception) {
            logger()->warning('[API V1][ORDERS][CLOSURE] Falha ao gerar PDF de encerramento', [
                'order_id' => $order->id,
                'message' => $exception->getMessage(),
            ]);
        }

        $attachments = [];
        if (($pdf['ok'] ?? false) && is_string($pdf['path'] ?? null) && is_file($pdf['path'])) {
            $attachments[] = new UploadedFile(
                $pdf['path'],
                (string) ($pdf['file_name'] ?? ('OS-' . $order->numero_os . '.pdf')),
                'application/pdf',
                null,
                true
            );

            $this->orderEventService->record(
                (int) $order->id,
                OrderEvent::CATEGORIA_DOCUMENTO,
                OrderEvent::TIPO_FECHAMENTO_PDF_GERADO,
                'PDF de fechamento gerado',
                'PDF consolidado da OS gerado para envio ao cliente.',
                ['arquivo' => (string) ($pdf['file_name'] ?? '')],
                null,
                OrderEvent::ORIGEM_SISTEMA
            );
        }

        try {
            $resultado = $this->whatsappMessagingService->sendSystemMessage(
                $telefone,
                'Olá! Sua OS ' . $order->numero_os . ' foi encerrada como "'
                    . $statusNome . '". Qualquer dúvida, estamos à disposição.',
                $attachments,
                trim((string) ($order->client?->nome_razao ?? '')) ?: null,
                (int) ($order->cliente_id ?? 0) > 0 ? (int) $order->cliente_id : null,
                [
                    'origin' => 'os_closure',
                    'os_id' => (int) $order->id,
                    'status_codigo' => $statusAplicadoCodigo,
                ]
            );

            $enviado = (bool) ($resultado['ok'] ?? false);

            if ($enviado) {
                $this->orderEventService->record(
                    (int) $order->id,
                    OrderEvent::CATEGORIA_MENSAGEM,
                    OrderEvent::TIPO_WHATSAPP_ENVIADO,
                    'Comprovante de fechamento enviado',
                    'Cliente notificado por WhatsApp sobre o encerramento da OS.',
                    [
                        'origin' => 'os_closure',
                        'destino' => $telefone,
                        'status_codigo' => $statusAplicadoCodigo,
                        'com_pdf' => $attachments !== [],
                    ],
                    null,
                    OrderEvent::ORIGEM_SISTEMA
                );
            }

            return $enviado;
        } catch (Throwable $exception) {
            logger()->warning('[API V1][ORDERS][CLOSURE] Falha ao notificar cliente por WhatsApp', [
                'order_id' => $order->id,
                'message' => $exception->getMessage(),
            ]);

            return false;
        } finally {
            if (($pdf['ok'] ?? false) && is_string($pdf['path'] ?? null) && is_file($pdf['path'])) {
                @unlink($pdf['path']);
            }
        }
    }

    /**
     * Notifica o cliente de um Adiantamento/Sinal registrado via
     * registerAdvance() — mensagem simples de texto (sem PDF de encerramento,
     * já que a OS não foi encerrada).
     */
    private function sendAdvanceNotification(
        Order $order,
        bool $equipamentoEntregue,
        float $saldoRestante
    ): bool {
        $order->loadMissing('client');
        $telefone = trim((string) ($order->client?->telefone1 ?? ''));

        if ($telefone === '') {
            return false;
        }

        $numeroOs = trim((string) ($order->numero_os ?: ('#' . $order->id)));
        $mensagem = sprintf(
            'Olá! Recebemos seu pagamento referente à OS %s. Saldo restante: R$ %s.%s',
            $numeroOs,
            number_format($saldoRestante, 2, ',', '.'),
            $equipamentoEntregue ? ' O equipamento foi registrado como entregue.' : ''
        );

        try {
            $resultado = $this->whatsappMessagingService->sendSystemMessage(
                $telefone,
                $mensagem,
                [],
                trim((string) ($order->client?->nome_razao ?? '')) ?: null,
                (int) ($order->cliente_id ?? 0) > 0 ? (int) $order->cliente_id : null,
                [
                    'origin' => 'os_advance_payment',
                    'os_id' => (int) $order->id,
                ]
            );

            $enviado = (bool) ($resultado['ok'] ?? false);

            if ($enviado) {
                $this->orderEventService->record(
                    (int) $order->id,
                    OrderEvent::CATEGORIA_MENSAGEM,
                    OrderEvent::TIPO_WHATSAPP_ENVIADO,
                    'Recibo de adiantamento enviado',
                    'Cliente notificado por WhatsApp sobre o valor recebido.',
                    [
                        'origin' => 'os_advance_payment',
                        'destino' => $telefone,
                        'saldo_restante' => round($saldoRestante, 2),
                    ],
                    null,
                    OrderEvent::ORIGEM_SISTEMA
                );
            }

            return $enviado;
        } catch (Throwable $exception) {
            logger()->warning('[API V1][ORDERS][CLOSURE] Falha ao notificar cliente por WhatsApp (adiantamento)', [
                'order_id' => $order->id,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function closureOptions(): array
    {
        // `status_final=true` sozinho é amplo demais: também marca estados
        // intermediários do sub-fluxo de reparo (ex.: "Reparo Concluído",
        // "Irreparável", "Reparo Recusado") que antecedem a baixa, mas não são
        // o encerramento em si. A baixa da OS só deve oferecer os codigos
        // canonicos de OrderStatus::closureCodes() (grupo_macro = 'encerrado').
        return OrderStatus::query()
            ->active()
            ->whereIn('codigo', OrderStatus::closureCodes())
            ->orderBy('ordem_fluxo')
            ->get(['codigo', 'nome'])
            ->map(static fn (OrderStatus $status): array => [
                'codigo' => (string) $status->codigo,
                'nome' => (string) $status->nome,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function financialSummary(Order $order): array
    {
        $titulo = Financeiro::query()
            ->where('os_id', $order->id)
            ->where('tipo', Financeiro::TIPO_RECEBER)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($titulo instanceof Financeiro) {
            return $this->financeiroService->movementSummary($titulo);
        }

        $valorFinal = round((float) ($order->valor_final ?? 0), 2);

        return [
            'titulo_id' => null,
            'valor_titulo' => $valorFinal,
            'valor_movimentado' => 0.0,
            'valor_aberto' => $valorFinal,
            'total_movimentos' => 0,
            'status_resolvido' => null,
            'percentual_quitado' => 0.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCostSummary(int $orderId): array
    {
        $rows = OrderItem::query()
            ->selectRaw("tipo, COALESCE(SUM(COALESCE(preco_custo_referencia, 0) * COALESCE(quantidade, 1)), 0) as total")
            ->where('os_id', $orderId)
            ->groupBy('tipo')
            ->get();

        $summary = ['pecas' => 0.0, 'servicos' => 0.0, 'total' => 0.0];

        foreach ($rows as $row) {
            $tipo = strtolower(trim((string) $row->tipo));
            $valor = round((float) $row->total, 2);

            if ($tipo === 'peca') {
                $summary['pecas'] = $valor;
            } elseif ($tipo === 'servico') {
                $summary['servicos'] = $valor;
            }

            $summary['total'] += $valor;
        }

        $summary['total'] = round($summary['total'], 2);

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapOrderSummary(Order $order): array
    {
        return [
            'id' => (int) $order->id,
            'numero_os' => (string) $order->numero_os,
            'status' => (string) $order->status,
            'status_nome' => (string) ($order->statusCatalog?->nome ?? ''),
            'estado_fluxo' => (string) $order->estado_fluxo,
            'status_final_pendente_pagamento' => $order->status_final_pendente_pagamento !== null
                ? (string) $order->status_final_pendente_pagamento
                : null,
            'data_entrega' => $order->data_entrega?->toDateString(),
            'valor_final' => round((float) ($order->valor_final ?? 0), 2),
        ];
    }

    private function normalizeDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
