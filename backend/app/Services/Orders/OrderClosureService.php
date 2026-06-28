<?php

namespace App\Services\Orders;

use App\Models\CrmFollowup;
use App\Models\Financeiro;
use App\Models\FinanceiroMovimentoCartao;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\OsCobrancaAgendamento;
use App\Models\User;
use App\Services\Channels\Whatsapp\WhatsappMessagingService;
use App\Services\Financeiro\FinanceiroCartaoService;
use App\Services\Financeiro\FinanceiroService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class OrderClosureService
{
    private const NO_REPAIR_STATUSES = ['devolvido_sem_reparo', 'descartado'];

    private const COLLECTION_SCHEDULE_DAYS = [1, 3, 5];

    private const RETURN_FOLLOWUP_DEFAULT_DAYS = 180;

    private const PENDING_PAYMENT_STATUS = 'entregue_pagamento_pendente';

    public function __construct(
        private readonly OrderWorkflowService $orderWorkflowService,
        private readonly FinanceiroService $financeiroService,
        private readonly FinanceiroCartaoService $financeiroCartaoService,
        private readonly WhatsappMessagingService $whatsappMessagingService
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
        $recebimentos = $this->normalizeReceipts(
            is_array($payload['recebimentos'] ?? null) ? $payload['recebimentos'] : [],
            $isNoRepairClosure
        );

        // Simula os recebimentos em cartao ANTES da transacao: falha rapido sem
        // efeito colateral nenhum se a combinacao operadora/bandeira/parcelas
        // nao tiver taxa ativa configurada.
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
                return ['result' => 'invalid_card_payment', 'message' => $exception->getMessage()];
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
                $titulo = $this->ensureReceivableTitle($order, $dataEntrega);

                foreach ($recebimentos as $recebimento) {
                    $movementSummary = $this->financeiroService->registerMovement($titulo, [
                        'valor_movimento' => $recebimento['valor'],
                        'data_movimento' => $recebimento['data_pagamento'] ?? $dataEntrega,
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
                $temSaldoPendente = $saldoAberto > 0.009 && ! $isNoRepairClosure;

                $statusAplicado = $temSaldoPendente ? self::PENDING_PAYMENT_STATUS : $encerrarComo;

                $statusResult = $this->orderWorkflowService->updateStatus(
                    (int) $order->id,
                    $actor,
                    $statusAplicado,
                    $observacao !== '' ? $observacao : null
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

                return ['result' => 'ok'];
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
            $notificacaoEnviada = $this->sendClosureNotification($order, $statusRow);
        }

        $updatedOrder = Order::query()->with(['client', 'statusCatalog'])->find($order->id);

        return [
            'result' => 'ok',
            'order' => $updatedOrder instanceof Order ? $this->mapOrderSummary($updatedOrder) : null,
            'notificacao_enviada' => $notificacaoEnviada,
        ];
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
    private function normalizeReceipts(array $rawReceipts, bool $isNoRepairClosure): array
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

            $classificacao = trim((string) ($raw['classificacao_recebimento'] ?? 'baixa'));
            if ($isNoRepairClosure || ! in_array($classificacao, ['baixa', 'adiantamento', 'sinal'], true)) {
                $classificacao = 'baixa';
            }

            $normalized[] = [
                'valor' => $valor,
                'classificacao_recebimento' => $classificacao,
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

        Financeiro::query()->create([
            'os_id' => (int) $order->id,
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

        return $created;
    }

    private function cancelPendingCollections(int $orderId): int
    {
        return OsCobrancaAgendamento::query()
            ->where('os_id', $orderId)
            ->whereIn('status', [OsCobrancaAgendamento::STATUS_PENDENTE, OsCobrancaAgendamento::STATUS_ERRO])
            ->update(['status' => OsCobrancaAgendamento::STATUS_CANCELADO, 'updated_at' => Carbon::now()]);
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
            'tipo' => Financeiro::TIPO_RECEBER,
            'categoria' => 'Serviço',
            'descricao' => 'Cobrança da OS ' . $order->numero_os,
            'valor' => round((float) ($order->valor_final ?? 0), 2),
            'data_vencimento' => $dataEntrega,
        ]);
    }

    private function sendClosureNotification(Order $order, OrderStatus $statusRow): bool
    {
        $order->loadMissing('client');
        $telefone = trim((string) ($order->client?->telefone1 ?? ''));

        if ($telefone === '') {
            return false;
        }

        try {
            $resultado = $this->whatsappMessagingService->sendSystemMessage(
                $telefone,
                'Olá! Sua OS ' . $order->numero_os . ' foi encerrada como "'
                    . $statusRow->nome . '". Qualquer dúvida, estamos à disposição.',
                [],
                trim((string) ($order->client?->nome_razao ?? '')) ?: null,
                (int) ($order->cliente_id ?? 0) > 0 ? (int) $order->cliente_id : null,
                [
                    'origin' => 'os_closure',
                    'os_id' => (int) $order->id,
                    'status_codigo' => (string) ($statusRow->codigo ?? ''),
                ]
            );

            return (bool) ($resultado['ok'] ?? false);
        } catch (Throwable $exception) {
            logger()->warning('[API V1][ORDERS][CLOSURE] Falha ao notificar cliente por WhatsApp', [
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
        return OrderStatus::query()
            ->active()
            ->where('status_final', true)
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
