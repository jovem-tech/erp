<?php

namespace App\Services\Budgets;

use App\Models\Budget;
use App\Models\BudgetApproval;
use App\Models\BudgetSend;
use App\Models\BudgetStatusHistory;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderStatus;
use App\Models\User;
use App\Services\Channels\Whatsapp\PhoneNumberNormalizationService;
use App\Services\Company\CompanyProfileService;
use App\Services\Integrations\EmailIntegrationSettingsService;
use App\Services\Integrations\IntegrationSettingsService;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Orders\OrderDocumentCenterService;
use App\Services\Orders\OrderEventService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class BudgetApprovalService
{
    public function __construct(
        private readonly BudgetPdfService $budgetPdfService,
        private readonly IntegrationSettingsService $integrationSettingsService,
        private readonly EmailIntegrationSettingsService $emailIntegrationSettingsService,
        private readonly CompanyProfileService $companyProfileService,
        private readonly PhoneNumberNormalizationService $phoneNumberNormalizationService,
        private readonly BudgetOrderSyncService $budgetOrderSyncService,
        private readonly OrderEventService $orderEventService,
        private readonly OrderDocumentCenterService $orderDocumentCenterService,
        private readonly NotificationDispatchService $notificationDispatchService,
        private readonly BudgetCommercialTermsService $budgetCommercialTermsService
    ) {
    }

    /**
     * Destinatários do sino para decisão do cliente (aprovação/recusa) pelo
     * link público: responsável + criador do orçamento + técnico da OS
     * vinculada (ver documentacao/03-arquitetura-tecnica/notificacoes-sino.md).
     *
     * @return array<int, int>
     */
    private function budgetDecisionRecipients(Budget $budget): array
    {
        return [
            (int) ($budget->responsavel_id ?? 0),
            (int) ($budget->criado_por ?? 0),
            (int) ($budget->order?->tecnico_id ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function dispatchForApproval(int $budgetId, User $user, array $context = []): array
    {
        $budget = $this->loadBudget($budgetId);

        if (! $budget instanceof Budget) {
            return [
                'result' => 'not_found',
                'message' => 'Orçamento não encontrado.',
            ];
        }

        // Orçamento já decidido (aprovado/pendente de OS): o envio deixa de ser um
        // pedido de aprovação e vira só um compartilhamento do PDF/link para o
        // cliente consultar — não pode reabrir o fluxo de decisão nem mexer no
        // status atual.
        $isResolved = in_array((string) $budget->status, Budget::approvedForOrderLinkStatuses(), true);

        $canal = $this->normalizeDispatchChannel((string) ($context['canal'] ?? 'whatsapp'));
        $channels = $this->resolveDispatchChannels($canal);

        $pendencias = $this->dispatchPendencies($budget, $channels);
        if ($pendencias !== []) {
            return [
                'result' => 'validation_error',
                'message' => 'Existem pendências que impedem o envio para aprovação.',
                'details' => [
                    'send_for_approval' => $pendencias,
                ],
            ];
        }

        // Antes de gerar o PDF: o documento e a pagina publica exibem a validade,
        // entao ela precisa estar renovada para nao contradizer o prazo do link.
        $this->refreshValidityForDispatch($budget);

        $token = $this->ensurePublicToken($budget);
        $approvalLink = $this->publicUrl($token);
        $pdf = $this->budgetPdfService->generate($budget, $approvalLink, ['actor' => $user]);

        if (! ($pdf['ok'] ?? false)) {
            return [
                'result' => 'dispatch_failed',
                'message' => (string) ($pdf['message'] ?? 'Falha ao gerar o PDF do orçamento.'),
            ];
        }

        $companyName = $this->companyName();
        $sendAt = now();

        // Um resultado por canal escolhido — "ambos" tenta os dois
        // independentemente e cada um vira sua própria linha em BudgetSend,
        // igual ao histórico já esperado pela tela de orçamento.
        $results = [];
        foreach ($channels as $channel) {
            $results[$channel] = $channel === 'email'
                ? $this->dispatchEmailApproval($budget, $companyName, $approvalLink, $pdf, $isResolved)
                : $this->dispatchWhatsappApproval($budget, $companyName, $approvalLink, $pdf, $isResolved);
        }

        $dispatchOk = collect($results)->contains(static fn (array $result): bool => (bool) ($result['ok'] ?? false));
        $dispatchMessage = $this->summarizeDispatchMessage($results, $channels, $isResolved);
        // Orçamento já decidido: nunca muda de status por causa de um reenvio de
        // consulta — nem em caso de sucesso (senão reabriria a aprovação), nem em
        // caso de falha (senão derrubaria um "aprovado" para "erro de envio").
        $targetStatus = $isResolved
            ? (string) $budget->status
            : ($dispatchOk ? Budget::STATUS_WAITING_REPLY : Budget::STATUS_PENDING_SEND);

        DB::transaction(function () use (
            $budget,
            $user,
            $token,
            $pdf,
            $sendAt,
            $channels,
            $results,
            $dispatchOk,
            $targetStatus,
            $isResolved
        ): void {
            $budget->refresh();
            $previousStatus = (string) ($budget->status ?? Budget::STATUS_DRAFT);
            $expiry = $this->resolveTokenExpiry($budget);

            $osId = (int) ($budget->os_id ?? 0);
            if ($osId > 0) {
                $this->orderEventService->record(
                    $osId,
                    OrderEvent::CATEGORIA_DOCUMENTO,
                    OrderEvent::TIPO_ORCAMENTO_PDF_GERADO,
                    'PDF do orçamento gerado',
                    sprintf('PDF do orçamento %s gerado para envio ao cliente.', $budget->numero),
                    [
                        'orcamento_id' => (int) $budget->id,
                        'arquivo' => (string) ($pdf['file_name'] ?? ''),
                    ],
                    (int) $user->id
                );
            }

            foreach ($channels as $channel) {
                $result = $results[$channel];
                $channelOk = (bool) ($result['ok'] ?? false);
                $channelStatus = $channelOk ? 'enviado' : 'erro';
                $provider = trim((string) ($result['provider'] ?? ''));

                $envio = BudgetSend::query()->create([
                    'orcamento_id' => (int) $budget->id,
                    'canal' => $channel,
                    'destino' => (string) ($result['destino'] ?? ''),
                    'mensagem' => (string) ($result['mensagem'] ?? ''),
                    'documento_path' => (string) ($pdf['relative_path'] ?? ''),
                    'status' => $channelStatus,
                    'provedor' => $provider !== '' ? $provider : null,
                    'referencia_externa' => null,
                    'erro_detalhe' => $channelOk ? null : (string) ($result['erro'] ?? ''),
                    'enviado_por' => (int) $user->id,
                    'enviado_em' => $channelOk ? $sendAt : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($osId > 0 && $channelOk) {
                    $this->orderEventService->record(
                        $osId,
                        OrderEvent::CATEGORIA_ORCAMENTO,
                        OrderEvent::TIPO_ORCAMENTO_ENVIADO,
                        $isResolved ? 'Orçamento enviado para consulta' : 'Orçamento enviado para aprovação',
                        $isResolved
                            ? sprintf('Orçamento %s (já aprovado) enviado ao cliente para consulta (%s).', $budget->numero, $channel === 'email' ? 'e-mail' : 'WhatsApp')
                            : sprintf('Orçamento %s enviado ao cliente para aprovação (%s).', $budget->numero, $channel === 'email' ? 'e-mail' : 'WhatsApp'),
                        [
                            'orcamento_id' => (int) $budget->id,
                            'envio_id' => (int) $envio->id,
                            'canal' => $channel,
                            'destino' => (string) ($result['destino'] ?? ''),
                        ],
                        (int) $user->id
                    );

                    $this->orderEventService->record(
                        $osId,
                        OrderEvent::CATEGORIA_MENSAGEM,
                        $channel === 'email' ? OrderEvent::TIPO_EMAIL_ENVIADO : OrderEvent::TIPO_WHATSAPP_ENVIADO,
                        $channel === 'email' ? 'Orçamento enviado por e-mail' : 'Orçamento enviado por WhatsApp',
                        sprintf('Proposta do orçamento %s enviada com PDF anexo.', $budget->numero),
                        [
                            'origin' => 'orcamento_aprovacao',
                            'orcamento_id' => (int) $budget->id,
                            'envio_id' => (int) $envio->id,
                            'destino' => (string) ($result['destino'] ?? ''),
                        ],
                        (int) $user->id
                    );
                } elseif ($osId > 0) {
                    $this->orderEventService->record(
                        $osId,
                        OrderEvent::CATEGORIA_MENSAGEM,
                        $channel === 'email' ? OrderEvent::TIPO_EMAIL_FALHOU : OrderEvent::TIPO_WHATSAPP_FALHOU,
                        $channel === 'email' ? 'Falha ao enviar orçamento por e-mail' : 'Falha ao enviar orçamento por WhatsApp',
                        sprintf('Não foi possível enviar a proposta do orçamento %s. %s', $budget->numero, (string) ($result['erro'] ?? '')),
                        [
                            'origin' => 'orcamento_aprovacao',
                            'orcamento_id' => (int) $budget->id,
                            'envio_id' => (int) $envio->id,
                            'destino' => (string) ($result['destino'] ?? ''),
                        ],
                        (int) $user->id
                    );
                }
            }

            $budget->forceFill([
                'status' => $targetStatus,
                'token_publico' => $token,
                'token_expira_em' => $expiry,
                'enviado_em' => $dispatchOk ? $sendAt : null,
                'atualizado_por' => (int) $user->id,
            ])->save();

            if ($previousStatus !== $targetStatus) {
                $this->recordStatusHistory(
                    $budget,
                    $previousStatus,
                    $targetStatus,
                    $dispatchOk
                        ? 'Proposta enviada para aprovação do cliente.'
                        : 'Tentativa de envio da proposta registrada, mas houve falha técnica no disparo.',
                    'sistema',
                    (int) $user->id
                );
            }

            $this->syncOrderForDispatch($budget, (string) ($pdf['relative_path'] ?? ''));

            // Orçamento já decidido: não há mudança de status para reconciliar na
            // OS, e chamar isso aqui reabriria um risco real — syncFromBudget()
            // força a OS de volta para "aguardando_reparo" sempre que o orçamento
            // está "aprovado", sem checar se a OS já avançou além disso.
            if (! $isResolved) {
                $this->budgetOrderSyncService->syncFromBudget($budget, (int) $user->id);
            }
        });

        if ((int) ($budget->os_id ?? 0) > 0) {
            try {
                $this->orderDocumentCenterService->syncAfterBudgetDispatch(
                    (int) $budget->os_id,
                    (int) $budget->id,
                    (string) ($pdf['absolute_path'] ?? ''),
                    $user,
                    $approvalLink,
                    is_array($pdf['engine_result'] ?? null) ? $pdf['engine_result'] : []
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return [
            'result' => $dispatchOk ? 'ok' : 'dispatch_failed',
            'message' => $dispatchMessage,
            'dispatch' => [
                'canal' => $canal,
                'status' => $dispatchOk ? 'enviado' : 'erro',
                'destino' => (string) ($results[$channels[0]]['destino'] ?? ''),
                'public_url' => $approvalLink,
                'channels' => $results,
            ],
        ];
    }

    public function ensurePublicApprovalUrl(Budget $budget): string
    {
        return $this->publicUrl($this->ensurePublicToken($budget));
    }

    /**
     * @return array<string, mixed>
     */
    public function publicViewData(string $token): array
    {
        $budget = $this->findByToken($token);

        if (! $budget instanceof Budget) {
            return ['result' => 'not_found'];
        }

        if ($this->tokenExpired($budget)) {
            // O cliente abrindo um link morto e a confirmacao mais imediata de que
            // a proposta venceu: nao espera o agendador para refletir isso no painel.
            $this->markExpired($budget);

            return ['result' => 'expired'];
        }

        return [
            'result' => 'ok',
            'budget' => $this->publicBudgetPayload($budget),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function approveByToken(string $token, ?string $response, ?string $ipAddress, ?string $userAgent): array
    {
        $budget = $this->findByToken($token);

        if (! $budget instanceof Budget) {
            return [
                'result' => 'not_found',
                'message' => 'Proposta não encontrada.',
            ];
        }

        if ($this->tokenExpired($budget)) {
            $this->markExpired($budget);

            return [
                'result' => 'expired',
                'message' => 'O link desta proposta expirou. Solicite um novo envio à equipe responsável.',
            ];
        }

        $status = trim((string) ($budget->status ?? ''));
        if (in_array($status, [Budget::STATUS_APPROVED, Budget::STATUS_PENDING_OS, Budget::STATUS_REJECTED], true)) {
            return [
                'result' => 'already_resolved',
                'message' => 'Esta proposta já possui uma decisão registrada.',
            ];
        }

        $decisionMessage = trim((string) $response) !== '' ? trim((string) $response) : 'Aprovado pelo cliente.';

        $this->finalizeApproval($budget, $decisionMessage, [
            'origem' => 'link_publico',
            'usuario_id' => null,
            'usuario_nome' => 'Cliente',
            'approval_observacao' => 'Aprovação registrada pelo link público do orçamento.',
            'history_observacao' => 'Cliente aprovou o orçamento pelo link público.',
            'history_origem' => 'cliente',
            'ip' => $ipAddress,
            'ua' => $userAgent,
            'event_titulo' => 'Orçamento aprovado pelo cliente',
            'event_descricao' => sprintf('Cliente aprovou o orçamento %s pelo link público.', $budget->numero),
            'event_origem' => OrderEvent::ORIGEM_CLIENTE,
            'notif_title' => 'Orçamento aprovado pelo cliente',
            'notif_body' => sprintf(
                'O cliente aprovou o orçamento %s (R$ %s).',
                $budget->numero,
                number_format((float) $budget->total, 2, ',', '.')
            ),
        ]);

        return [
            'result' => 'ok',
            'message' => 'Orçamento aprovado com sucesso.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rejectByToken(string $token, ?string $reason, ?string $ipAddress, ?string $userAgent): array
    {
        $budget = $this->findByToken($token);

        if (! $budget instanceof Budget) {
            return [
                'result' => 'not_found',
                'message' => 'Proposta não encontrada.',
            ];
        }

        if ($this->tokenExpired($budget)) {
            $this->markExpired($budget);

            return [
                'result' => 'expired',
                'message' => 'O link desta proposta expirou. Solicite um novo envio à equipe responsável.',
            ];
        }

        $status = trim((string) ($budget->status ?? ''));
        if (in_array($status, [Budget::STATUS_APPROVED, Budget::STATUS_PENDING_OS, Budget::STATUS_REJECTED], true)) {
            return [
                'result' => 'already_resolved',
                'message' => 'Esta proposta já possui uma decisão registrada.',
            ];
        }

        $decisionMessage = trim((string) $reason) !== '' ? trim((string) $reason) : 'Proposta rejeitada pelo cliente.';

        $this->finalizeRejection($budget, $decisionMessage, [
            'origem' => 'link_publico',
            'usuario_id' => null,
            'usuario_nome' => 'Cliente',
            'approval_observacao' => 'Rejeição registrada pelo link público do orçamento.',
            'history_observacao' => 'Cliente rejeitou o orçamento pelo link público.',
            'history_origem' => 'cliente',
            'ip' => $ipAddress,
            'ua' => $userAgent,
            'event_titulo' => 'Orçamento recusado pelo cliente',
            'event_descricao' => sprintf('Cliente recusou o orçamento %s pelo link público. Motivo: %s', $budget->numero, $decisionMessage),
            'event_origem' => OrderEvent::ORIGEM_CLIENTE,
            'notif_title' => 'Orçamento recusado pelo cliente',
            'notif_body' => sprintf('O cliente recusou o orçamento %s.', $budget->numero),
        ]);

        return [
            'result' => 'ok',
            'message' => 'Rejeição registrada com sucesso.',
        ];
    }

    /**
     * Registra a aprovação pelo técnico ("outros meios": telefone, presencial,
     * WhatsApp etc.). Reaproveita o mesmo núcleo do link público
     * (finalizeApproval), incluindo o roteamento de status via approvedStatus()
     * — avulso aprovado vai para pendente_abertura_os.
     *
     * @return array<string, mixed>
     */
    public function approveByStaff(int $budgetId, User $actor, ?string $note): array
    {
        $budget = $this->loadBudget($budgetId);
        if (! $budget instanceof Budget) {
            return ['result' => 'not_found', 'message' => 'Orçamento não encontrado.'];
        }

        $status = trim((string) ($budget->status ?? ''));
        if (in_array($status, [Budget::STATUS_APPROVED, Budget::STATUS_PENDING_OS, Budget::STATUS_CONVERTED], true)) {
            return ['result' => 'already_resolved', 'message' => 'Este orçamento já está aprovado.'];
        }

        $decisionMessage = trim((string) $note) !== ''
            ? trim((string) $note)
            : 'Cliente aprovou o orçamento por outros meios (registrado pelo técnico).';
        $actorName = trim((string) ($actor->nome ?? '')) ?: 'Técnico';

        $newStatus = $this->finalizeApproval($budget, $decisionMessage, [
            'origem' => 'painel',
            'usuario_id' => (int) $actor->id,
            'usuario_nome' => $actorName,
            'approval_observacao' => 'Aprovação registrada pelo técnico (cliente aprovou por outros meios).',
            'history_observacao' => 'Técnico registrou a aprovação do cliente (outros meios).',
            'history_origem' => 'tecnico',
            'ip' => null,
            'ua' => null,
            'event_titulo' => 'Orçamento aprovado (registrado pelo técnico)',
            'event_descricao' => sprintf('%s registrou a aprovação do orçamento %s (outros meios).', $actorName, $budget->numero),
            'event_origem' => OrderEvent::ORIGEM_USUARIO,
            'notif_title' => 'Orçamento aprovado (registrado pelo técnico)',
            'notif_body' => sprintf(
                'O orçamento %s foi aprovado (R$ %s).',
                $budget->numero,
                number_format((float) $budget->total, 2, ',', '.')
            ),
        ]);

        return ['result' => 'ok', 'status' => $newStatus, 'message' => 'Aprovação registrada com sucesso.'];
    }

    /**
     * Registra a rejeição explícita do cliente quando comunicada por outros
     * meios (o cliente recusou por telefone/presencial e não usou o link).
     *
     * @return array<string, mixed>
     */
    public function rejectByStaff(int $budgetId, User $actor, ?string $reason): array
    {
        $budget = $this->loadBudget($budgetId);
        if (! $budget instanceof Budget) {
            return ['result' => 'not_found', 'message' => 'Orçamento não encontrado.'];
        }

        $status = trim((string) ($budget->status ?? ''));
        if (in_array($status, [Budget::STATUS_APPROVED, Budget::STATUS_PENDING_OS, Budget::STATUS_CONVERTED, Budget::STATUS_REJECTED, Budget::STATUS_CANCELLED], true)) {
            return ['result' => 'already_resolved', 'message' => 'Este orçamento não pode ser rejeitado no status atual.'];
        }

        $decisionMessage = trim((string) $reason) !== ''
            ? trim((string) $reason)
            : 'Cliente recusou o orçamento por outros meios (registrado pelo técnico).';
        $actorName = trim((string) ($actor->nome ?? '')) ?: 'Técnico';

        $this->finalizeRejection($budget, $decisionMessage, [
            'origem' => 'painel',
            'usuario_id' => (int) $actor->id,
            'usuario_nome' => $actorName,
            'approval_observacao' => 'Rejeição registrada pelo técnico (cliente recusou por outros meios).',
            'history_observacao' => 'Técnico registrou a rejeição do cliente (outros meios).',
            'history_origem' => 'tecnico',
            'ip' => null,
            'ua' => null,
            'event_titulo' => 'Orçamento recusado (registrado pelo técnico)',
            'event_descricao' => sprintf('%s registrou a rejeição do orçamento %s. Motivo: %s', $actorName, $budget->numero, $decisionMessage),
            'event_origem' => OrderEvent::ORIGEM_USUARIO,
            'notif_title' => 'Orçamento recusado (registrado pelo técnico)',
            'notif_body' => sprintf('O orçamento %s foi recusado pelo cliente.', $budget->numero),
        ]);

        return ['result' => 'ok', 'status' => Budget::STATUS_REJECTED, 'message' => 'Rejeição registrada com sucesso.'];
    }

    /**
     * Cancela o orçamento por decisão do técnico — usado quando o cliente
     * ignorou a proposta por tempo indeterminado (abandono). Não é uma decisão
     * do cliente: difere de rejeitado (recusa explícita).
     *
     * @return array<string, mixed>
     */
    public function cancelByStaff(int $budgetId, User $actor, ?string $reason): array
    {
        $budget = $this->loadBudget($budgetId);
        if (! $budget instanceof Budget) {
            return ['result' => 'not_found', 'message' => 'Orçamento não encontrado.'];
        }

        $status = trim((string) ($budget->status ?? ''));
        if (in_array($status, [Budget::STATUS_CONVERTED, Budget::STATUS_CANCELLED], true)) {
            return ['result' => 'already_resolved', 'message' => 'Este orçamento não pode ser cancelado no status atual.'];
        }

        $decisionMessage = trim((string) $reason) !== ''
            ? trim((string) $reason)
            : 'Cancelado pelo técnico: cliente não respondeu à proposta.';
        $actorName = trim((string) ($actor->nome ?? '')) ?: 'Técnico';

        $this->finalizeCancellation($budget, $decisionMessage, [
            'usuario_id' => (int) $actor->id,
            'usuario_nome' => $actorName,
        ]);

        return ['result' => 'ok', 'status' => Budget::STATUS_CANCELLED, 'message' => 'Orçamento cancelado com sucesso.'];
    }

    /**
     * Núcleo da aprovação — compartilhado pelo link público (approveByToken) e
     * pela ação do técnico (approveByStaff). O status final é resolvido por
     * approvedStatus() (avulso → pendente_abertura_os; com OS → aprovado).
     *
     * @param array<string, mixed> $ctx
     */
    private function finalizeApproval(Budget $budget, string $decisionMessage, array $ctx): string
    {
        $approvedStatus = $this->approvedStatus($budget);

        DB::transaction(function () use ($budget, $approvedStatus, $decisionMessage, $ctx): void {
            $budget->refresh();
            $previousStatus = (string) ($budget->status ?? Budget::STATUS_DRAFT);
            $approvedAt = now();

            $budget->forceFill([
                'status' => $approvedStatus,
                'aprovado_em' => $approvedAt,
                'rejeitado_em' => null,
                'motivo_rejeicao' => null,
            ])->save();

            BudgetApproval::query()->create([
                'orcamento_id' => (int) $budget->id,
                'token_publico' => (string) ($budget->token_publico ?? ''),
                'acao' => 'aprovado',
                'origem' => (string) $ctx['origem'],
                'usuario_id' => $ctx['usuario_id'] ?? null,
                'usuario_nome' => (string) $ctx['usuario_nome'],
                'resposta_cliente' => $decisionMessage,
                'observacao' => (string) $ctx['approval_observacao'],
                'ip_origem' => $ctx['ip'] ?? null,
                'user_agent' => ($ctx['ua'] ?? null) !== null ? Str::limit((string) $ctx['ua'], 255, '') : null,
                'created_at' => $approvedAt,
            ]);

            $this->recordStatusHistory(
                $budget,
                $previousStatus,
                $approvedStatus,
                (string) $ctx['history_observacao'],
                (string) $ctx['history_origem'],
                $ctx['usuario_id'] ?? null
            );

            $osId = (int) ($budget->os_id ?? 0);
            if ($osId > 0) {
                $this->orderEventService->record(
                    $osId,
                    OrderEvent::CATEGORIA_ORCAMENTO,
                    OrderEvent::TIPO_ORCAMENTO_APROVADO,
                    (string) $ctx['event_titulo'],
                    (string) $ctx['event_descricao'],
                    [
                        'orcamento_id' => (int) $budget->id,
                        'numero' => (string) $budget->numero,
                        'resposta_cliente' => $decisionMessage,
                        'ip_origem' => $ctx['ip'] ?? null,
                        'user_agent' => $ctx['ua'] ?? null,
                    ],
                    $ctx['usuario_id'] ?? null,
                    (string) $ctx['event_origem'],
                    $approvedAt
                );
            }

            $this->syncOrderForDecision($budget, true, $approvedAt);
            $this->budgetOrderSyncService->syncFromBudget($budget, $ctx['usuario_id'] ?? null);

            // Sino: avisa responsável, criador e técnico da OS — sem isso a
            // aprovação só aparecia no sino no próximo carregamento manual da
            // lista (nenhum dispatch existia aqui antes, apesar de já
            // documentado em notificacoes-sino.md).
            $this->notificationDispatchService->toUsers(
                $this->decisionRecipients($budget, $ctx['usuario_id'] ?? null),
                [
                    'kind' => 'orcamento.approved',
                    'title' => (string) $ctx['notif_title'],
                    'body' => (string) $ctx['notif_body'],
                    'route' => '/orcamentos/' . (int) $budget->id,
                    'icon' => 'receipt',
                    'orcamento_id' => (int) $budget->id,
                    'os_id' => (int) ($budget->os_id ?? 0),
                ]
            );
        });

        return $approvedStatus;
    }

    /**
     * Núcleo da rejeição — compartilhado por rejectByToken e rejectByStaff.
     *
     * @param array<string, mixed> $ctx
     */
    private function finalizeRejection(Budget $budget, string $decisionMessage, array $ctx): void
    {
        DB::transaction(function () use ($budget, $decisionMessage, $ctx): void {
            $budget->refresh();
            $previousStatus = (string) ($budget->status ?? Budget::STATUS_DRAFT);
            $rejectedAt = now();

            $budget->forceFill([
                'status' => Budget::STATUS_REJECTED,
                'rejeitado_em' => $rejectedAt,
                'aprovado_em' => null,
                'motivo_rejeicao' => $decisionMessage,
            ])->save();

            BudgetApproval::query()->create([
                'orcamento_id' => (int) $budget->id,
                'token_publico' => (string) ($budget->token_publico ?? ''),
                'acao' => 'rejeitado',
                'origem' => (string) $ctx['origem'],
                'usuario_id' => $ctx['usuario_id'] ?? null,
                'usuario_nome' => (string) $ctx['usuario_nome'],
                'resposta_cliente' => $decisionMessage,
                'observacao' => (string) $ctx['approval_observacao'],
                'ip_origem' => $ctx['ip'] ?? null,
                'user_agent' => ($ctx['ua'] ?? null) !== null ? Str::limit((string) $ctx['ua'], 255, '') : null,
                'created_at' => $rejectedAt,
            ]);

            $this->recordStatusHistory(
                $budget,
                $previousStatus,
                Budget::STATUS_REJECTED,
                (string) $ctx['history_observacao'],
                (string) $ctx['history_origem'],
                $ctx['usuario_id'] ?? null
            );

            $osId = (int) ($budget->os_id ?? 0);
            if ($osId > 0) {
                $this->orderEventService->record(
                    $osId,
                    OrderEvent::CATEGORIA_ORCAMENTO,
                    OrderEvent::TIPO_ORCAMENTO_RECUSADO,
                    (string) $ctx['event_titulo'],
                    (string) $ctx['event_descricao'],
                    [
                        'orcamento_id' => (int) $budget->id,
                        'numero' => (string) $budget->numero,
                        'resposta_cliente' => $decisionMessage,
                        'ip_origem' => $ctx['ip'] ?? null,
                        'user_agent' => $ctx['ua'] ?? null,
                    ],
                    $ctx['usuario_id'] ?? null,
                    (string) $ctx['event_origem'],
                    $rejectedAt
                );
            }

            $this->syncOrderForDecision($budget, false, $rejectedAt);
            $this->budgetOrderSyncService->syncFromBudget($budget, $ctx['usuario_id'] ?? null);

            // Sino: mesmo aviso da aprovação, ver comentário em finalizeApproval().
            $this->notificationDispatchService->toUsers(
                $this->decisionRecipients($budget, $ctx['usuario_id'] ?? null),
                [
                    'kind' => 'orcamento.rejected',
                    'title' => (string) $ctx['notif_title'],
                    'body' => (string) $ctx['notif_body'],
                    'route' => '/orcamentos/' . (int) $budget->id,
                    'icon' => 'receipt',
                    'orcamento_id' => (int) $budget->id,
                    'os_id' => (int) ($budget->os_id ?? 0),
                ]
            );
        });
    }

    /**
     * Núcleo do cancelamento (abandono) — sempre disparado pelo técnico.
     *
     * @param array<string, mixed> $ctx
     */
    private function finalizeCancellation(Budget $budget, string $decisionMessage, array $ctx): void
    {
        DB::transaction(function () use ($budget, $decisionMessage, $ctx): void {
            $budget->refresh();
            $previousStatus = (string) ($budget->status ?? Budget::STATUS_DRAFT);
            $cancelledAt = now();

            $budget->forceFill([
                'status' => Budget::STATUS_CANCELLED,
                'cancelado_em' => $cancelledAt,
            ])->save();

            BudgetApproval::query()->create([
                'orcamento_id' => (int) $budget->id,
                'token_publico' => (string) ($budget->token_publico ?? ''),
                'acao' => 'cancelado',
                'origem' => 'painel',
                'usuario_id' => $ctx['usuario_id'] ?? null,
                'usuario_nome' => (string) $ctx['usuario_nome'],
                'resposta_cliente' => null,
                'observacao' => $decisionMessage,
                'ip_origem' => null,
                'user_agent' => null,
                'created_at' => $cancelledAt,
            ]);

            $this->recordStatusHistory(
                $budget,
                $previousStatus,
                Budget::STATUS_CANCELLED,
                'Técnico cancelou o orçamento (abandono do cliente). ' . $decisionMessage,
                'tecnico',
                $ctx['usuario_id'] ?? null
            );

            $osId = (int) ($budget->os_id ?? 0);
            if ($osId > 0) {
                $this->orderEventService->record(
                    $osId,
                    OrderEvent::CATEGORIA_ORCAMENTO,
                    OrderEvent::TIPO_ORCAMENTO_CANCELADO,
                    'Orçamento cancelado pelo técnico',
                    sprintf('%s cancelou o orçamento %s. Motivo: %s', (string) $ctx['usuario_nome'], $budget->numero, $decisionMessage),
                    [
                        'orcamento_id' => (int) $budget->id,
                        'numero' => (string) $budget->numero,
                        'motivo' => $decisionMessage,
                    ],
                    $ctx['usuario_id'] ?? null,
                    OrderEvent::ORIGEM_USUARIO,
                    $cancelledAt
                );
            }

            $this->budgetOrderSyncService->syncFromBudget($budget, $ctx['usuario_id'] ?? null);

            $this->notificationDispatchService->toUsers(
                $this->decisionRecipients($budget, $ctx['usuario_id'] ?? null),
                [
                    'kind' => 'orcamento.cancelled',
                    'title' => 'Orçamento cancelado',
                    'body' => sprintf('O orçamento %s foi cancelado (sem resposta do cliente).', $budget->numero),
                    'route' => '/orcamentos/' . (int) $budget->id,
                    'icon' => 'receipt',
                    'orcamento_id' => (int) $budget->id,
                    'os_id' => (int) ($budget->os_id ?? 0),
                ]
            );
        });
    }

    /**
     * Destinatários do sino incluindo, quando houver, o técnico que registrou
     * a decisão manualmente (além de responsável/criador/técnico da OS).
     *
     * @return array<int, int>
     */
    private function decisionRecipients(Budget $budget, ?int $actorId): array
    {
        $recipients = $this->budgetDecisionRecipients($budget);
        if ($actorId !== null && $actorId > 0) {
            $recipients[] = (int) $actorId;
        }

        return $recipients;
    }

    /**
     * @return array{ok: bool, absolute_path?: string, relative_path?: string, file_name?: string, message?: string}
     */
    public function regeneratePdfByToken(string $token): array
    {
        $budget = $this->findByToken($token);

        if (! $budget instanceof Budget) {
            return [
                'ok' => false,
                'message' => 'Proposta não encontrada.',
            ];
        }

        if ($this->tokenExpired($budget)) {
            $this->markExpired($budget);

            return [
                'ok' => false,
                'result' => 'expired',
                'message' => 'O link desta proposta expirou. Solicite um novo envio à equipe responsável.',
            ];
        }

        $actor = User::query()->whereKey((int) ($budget->criado_por ?? 0))->where('ativo', true)->first();

        return $this->budgetPdfService->generate(
            $budget,
            $this->publicUrl((string) ($budget->token_publico ?? '')),
            $actor instanceof User ? ['actor' => $actor] : []
        );
    }

    private function loadBudget(int $budgetId): ?Budget
    {
        return Budget::query()
            ->with(['client', 'equipment', 'order', 'items'])
            ->find($budgetId);
    }

    private function findByToken(string $token): ?Budget
    {
        $normalized = trim($token);
        if ($normalized === '') {
            return null;
        }

        return Budget::query()
            ->with(['client', 'equipment', 'order', 'items'])
            ->where('token_publico', $normalized)
            ->first();
    }

    /**
     * @param  array<int, string>  $channels
     * @return array<int, string>
     */
    private function dispatchPendencies(Budget $budget, array $channels = ['whatsapp']): array
    {
        $pendencias = [];

        if ($this->resolveDisplayClientName($budget) === '') {
            $pendencias[] = 'Informe o cliente do orçamento antes de enviar para aprovação.';
        }

        if ($budget->items->isEmpty()) {
            $pendencias[] = 'Adicione ao menos um item no orçamento.';
        }

        if ((float) ($budget->total ?? 0) <= 0) {
            $pendencias[] = 'O total final precisa ser maior que zero para enviar a proposta ao cliente.';
        }

        if (in_array('whatsapp', $channels, true) && ! $this->hasValidWhatsappPhone($budget)) {
            $pendencias[] = 'Informe um telefone de contato com WhatsApp válido para enviar o PDF de aprovação.';
        }

        if (in_array('email', $channels, true) && ! $this->hasValidEmail($budget)) {
            $pendencias[] = 'Informe um e-mail de contato válido para enviar a proposta por e-mail.';
        }

        return $pendencias;
    }

    /**
     * @return array<int, string>
     */
    private function resolveDispatchChannels(string $canal): array
    {
        return match ($canal) {
            'email' => ['email'],
            'ambos' => ['whatsapp', 'email'],
            default => ['whatsapp'],
        };
    }

    private function normalizeDispatchChannel(?string $canal): string
    {
        $normalized = mb_strtolower(trim((string) $canal));

        return in_array($normalized, ['whatsapp', 'email', 'ambos'], true) ? $normalized : 'whatsapp';
    }

    /**
     * @param  array<string, array{ok: bool, erro?: ?string}>  $results
     * @param  array<int, string>  $channels
     */
    private function summarizeDispatchMessage(array $results, array $channels, bool $isResolved = false): string
    {
        $channelLabel = static fn (string $channel): string => $channel === 'email' ? 'e-mail' : 'WhatsApp';
        $sentVerb = $isResolved ? 'Orçamento enviado para consulta por ' : 'Proposta enviada para aprovação por ';
        $failVerb = $isResolved ? 'Falha ao enviar orçamento por ' : 'Falha ao enviar proposta por ';

        if (count($channels) === 1) {
            $channel = $channels[0];
            $result = $results[$channel];

            if ($result['ok'] ?? false) {
                return $sentVerb.$channelLabel($channel).'.';
            }

            $erro = trim((string) ($result['erro'] ?? ''));

            return $erro !== '' ? $erro : $failVerb.$channelLabel($channel).'.';
        }

        $succeeded = [];
        $failed = [];
        foreach ($channels as $channel) {
            if ($results[$channel]['ok'] ?? false) {
                $succeeded[] = $channelLabel($channel);
            } else {
                $failed[] = $channelLabel($channel);
            }
        }

        if ($failed === []) {
            return $sentVerb.implode(' e ', $succeeded).'.';
        }

        if ($succeeded === []) {
            return $isResolved
                ? 'Falha ao enviar o orçamento por '.implode(' e ', $failed).'.'
                : 'Falha ao enviar a proposta por '.implode(' e ', $failed).'.';
        }

        return ($isResolved ? 'Orçamento enviado por ' : 'Proposta enviada por ').implode(' e ', $succeeded).'. Falha ao enviar por '.implode(' e ', $failed).'.';
    }

    /**
     * @return array{ok: bool, provider: string, destino: string, mensagem: string, erro: ?string}
     */
    private function dispatchWhatsappApproval(Budget $budget, string $companyName, string $approvalLink, array $pdf, bool $isResolved = false): array
    {
        $destinationPhone = $this->resolveDestinationPhone($budget);
        $caption = $this->buildWhatsappCaption($budget, $companyName, $approvalLink, $isResolved);

        $dispatch = $this->integrationSettingsService->sendDirectMedia(
            $destinationPhone,
            (string) ($pdf['absolute_path'] ?? ''),
            'document',
            $caption,
            (string) ($pdf['file_name'] ?? null)
        );

        $ok = (bool) ($dispatch['ok'] ?? false);

        return [
            'ok' => $ok,
            'provider' => trim((string) ($dispatch['provider'] ?? '')) ?: 'whatsapp',
            'destino' => $destinationPhone,
            'mensagem' => $caption,
            'erro' => $ok ? null : (trim((string) ($dispatch['message'] ?? '')) ?: 'Falha ao enviar proposta por WhatsApp.'),
        ];
    }

    /**
     * @return array{ok: bool, provider: string, destino: string, mensagem: string, erro: ?string}
     */
    private function dispatchEmailApproval(Budget $budget, string $companyName, string $approvalLink, array $pdf, bool $isResolved = false): array
    {
        $destinationEmail = $this->resolveDestinationEmail($budget);
        $subject = $this->buildEmailSubject($budget, $companyName, $isResolved);
        $body = $this->buildEmailBody($budget, $companyName, $approvalLink, $isResolved);

        if (! $this->emailIntegrationSettingsService->operationalMailerAvailable()) {
            return [
                'ok' => false,
                'provider' => 'smtp',
                'destino' => $destinationEmail,
                'mensagem' => $body,
                'erro' => 'SMTP operacional não configurado.',
            ];
        }

        try {
            Mail::html(
                $body,
                function ($mail) use ($destinationEmail, $subject, $pdf): void {
                    $mail->to($destinationEmail)->subject($subject);

                    $absolutePath = (string) ($pdf['absolute_path'] ?? '');
                    if ($absolutePath !== '') {
                        $mail->attach($absolutePath, [
                            'as' => (string) ($pdf['file_name'] ?? 'orcamento.pdf'),
                            'mime' => 'application/pdf',
                        ]);
                    }
                }
            );
        } catch (Throwable $exception) {
            report($exception);

            return [
                'ok' => false,
                'provider' => 'smtp',
                'destino' => $destinationEmail,
                'mensagem' => $body,
                'erro' => 'Falha ao enviar a proposta por e-mail.',
            ];
        }

        return [
            'ok' => true,
            'provider' => 'smtp',
            'destino' => $destinationEmail,
            'mensagem' => $body,
            'erro' => null,
        ];
    }

    private function buildEmailSubject(Budget $budget, string $companyName, bool $isResolved = false): string
    {
        $numero = trim((string) ($budget->numero ?? ('ORC-'.(int) $budget->id)));

        return $companyName.' - Orçamento '.$numero.($isResolved ? ' (aprovado)' : '');
    }

    private function buildEmailBody(Budget $budget, string $companyName, string $approvalLink, bool $isResolved = false): string
    {
        $numero = trim((string) ($budget->numero ?? ('ORC-'.(int) $budget->id)));
        $cliente = $this->resolveDisplayClientName($budget);
        $total = 'R$ '.number_format((float) ($budget->total ?? 0), 2, ',', '.');

        return sprintf(
            '<p>%s</p><p>Segue o orçamento <strong>%s</strong>%s%s.</p><p>Total da proposta: <strong>%s</strong>.</p><p>%s</p><p><a href="%s">%s</a></p>',
            e($companyName),
            e($numero),
            $isResolved ? ' (já aprovado)' : '',
            $cliente !== '' ? ' para '.e($cliente) : '',
            e($total),
            $isResolved ? 'O PDF em anexo e o link abaixo estão disponíveis para sua consulta:' : 'Analise o PDF em anexo e responda a proposta pelo link abaixo:',
            e($approvalLink),
            e($approvalLink)
        );
    }

    private function ensurePublicToken(Budget $budget): string
    {
        $current = trim((string) ($budget->token_publico ?? ''));
        if ($current !== '') {
            return $current;
        }

        do {
            $candidate = Str::random(64);
        } while (Budget::query()->where('token_publico', $candidate)->exists());

        $budget->forceFill([
            'token_publico' => $candidate,
            'token_expira_em' => $this->resolveTokenExpiry($budget),
        ])->save();

        return $candidate;
    }

    private function publicUrl(string $token): string
    {
        return (string) Budget::publicApprovalUrlForToken($token);
    }

    private function resolveTokenExpiry(Budget $budget): Carbon
    {
        if ($budget->validade_data instanceof Carbon) {
            $expiry = $budget->validade_data->copy()->endOfDay();

            // Rede de seguranca: nunca devolver um prazo no passado, senao o link
            // publico nasce morto e o cliente recebe 410 ao abrir a proposta.
            if ($expiry->isFuture()) {
                return $expiry;
            }
        }

        return $this->defaultTokenExpiry($budget);
    }

    private function defaultTokenExpiry(Budget $budget): Carbon
    {
        $days = max(1, (int) ($budget->validade_dias ?? 10));

        return now()->addDays($days)->endOfDay();
    }

    /**
     * Renova a validade da proposta quando ela ja venceu antes do (re)envio.
     *
     * A validade_data e congelada na criacao do orcamento (now + validade_dias) e nao
     * acompanha reenvios. Sem esta renovacao, reenviar uma proposta vencida regenera
     * PDF e mensagem mas grava o mesmo token_expira_em passado: o link continua em 410.
     */
    private function refreshValidityForDispatch(Budget $budget): void
    {
        if (! $budget->validade_data instanceof Carbon) {
            return;
        }

        if ($budget->validade_data->copy()->endOfDay()->isFuture()) {
            return;
        }

        $budget->forceFill([
            'validade_data' => $this->defaultTokenExpiry($budget)->copy()->startOfDay(),
        ])->save();
    }

    private function buildWhatsappCaption(Budget $budget, string $companyName, string $approvalLink, bool $isResolved = false): string
    {
        $numero = trim((string) ($budget->numero ?? ('ORC-' . (int) $budget->id)));
        $cliente = $this->resolveDisplayClientName($budget);
        $total = 'R$ ' . number_format((float) ($budget->total ?? 0), 2, ',', '.');

        return trim(
            $companyName . "\n\n"
            . 'Segue o orçamento ' . $numero . ($isResolved ? ' (já aprovado)' : '') . ($cliente !== '' ? ' para ' . $cliente : '') . ".\n"
            . 'Total da proposta: ' . $total . ".\n\n"
            . ($isResolved
                ? 'O PDF em anexo e o link abaixo estão disponíveis para sua consulta:'
                : 'Analise o PDF em anexo e responda a proposta pelo link abaixo:') . "\n"
            . $approvalLink
        );
    }

    private function resolveDestinationPhone(Budget $budget): string
    {
        $raw = trim((string) ($budget->telefone_contato ?? ''));
        if ($raw === '') {
            $raw = trim((string) ($budget->client?->telefone1 ?? ''));
        }

        return $raw;
    }

    private function hasValidWhatsappPhone(Budget $budget): bool
    {
        $normalized = $this->phoneNumberNormalizationService->normalize($this->resolveDestinationPhone($budget));
        $digits = preg_replace('/\D+/', '', $normalized) ?? '';

        return strlen($digits) >= 12;
    }

    private function resolveDestinationEmail(Budget $budget): string
    {
        $raw = trim((string) ($budget->email_contato ?? ''));
        if ($raw === '') {
            $raw = trim((string) ($budget->client?->email ?? ''));
        }

        return $raw;
    }

    private function hasValidEmail(Budget $budget): bool
    {
        return filter_var($this->resolveDestinationEmail($budget), FILTER_VALIDATE_EMAIL) !== false;
    }

    private function companyName(): string
    {
        $payload = $this->companyProfileService->payload();
        $settings = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];

        $name = trim((string) ($settings['empresa_nome_fantasia'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $name = trim((string) ($settings['empresa_razao_social'] ?? ''));

        return $name !== '' ? $name : 'Sistema ERP';
    }

    private function resolveDisplayClientName(Budget $budget): string
    {
        $clientName = trim((string) ($budget->client?->nome_razao ?? ''));
        if ($clientName !== '') {
            return $clientName;
        }

        return trim((string) ($budget->cliente_nome_avulso ?? ''));
    }

    private function tokenExpired(Budget $budget): bool
    {
        $deadline = $this->expiryDeadline($budget);

        return $deadline instanceof Carbon && now()->greaterThan($deadline);
    }

    /**
     * OS ja encerrada (entregue, irreparavel, descartada...) ou cancelada: o
     * atendimento acabou e nenhum status de orcamento deve mais move-la.
     */
    private function orderIsSettled(Budget $budget): bool
    {
        $flowState = strtolower(trim((string) ($budget->order?->estado_fluxo ?? '')));

        return in_array($flowState, [OrderStatus::CLOSURE_MACRO_GROUP, 'cancelado'], true);
    }

    /**
     * Prazo efetivo da proposta: o token manda, porque e ele que fecha o link
     * publico. Sem token, cai na validade comercial exibida ao cliente.
     */
    private function expiryDeadline(Budget $budget): ?Carbon
    {
        // Regra única em Budget::publicLinkDeadline(): o PDF decide exibir o
        // botão de aprovação pelo mesmo critério que este serviço usa para
        // devolver 410.
        return $budget->publicLinkDeadline();
    }

    /**
     * Marca como "vencido" toda proposta que passou do prazo e continua parada
     * esperando a resposta do cliente. Chamado pelo agendador (app:expire-budgets)
     * e tambem no acesso ao link publico, para o painel nao ficar mostrando
     * "Aguardando resposta" num orcamento cujo link ja devolve 410.
     */
    public function expireStaleBudgets(int $limit = 200): int
    {
        $expired = 0;

        Budget::query()
            ->with(['client', 'equipment', 'order', 'items'])
            ->whereIn('status', Budget::awaitingCustomerReplyStatuses())
            ->where(function ($query): void {
                $query
                    ->where(function ($tokenQuery): void {
                        $tokenQuery
                            ->whereNotNull('token_expira_em')
                            ->where('token_expira_em', '<', now());
                    })
                    ->orWhere(function ($validityQuery): void {
                        $validityQuery
                            ->whereNull('token_expira_em')
                            ->whereNotNull('validade_data')
                            ->whereDate('validade_data', '<', now()->toDateString());
                    });
            })
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get()
            ->each(function (Budget $budget) use (&$expired): void {
                if ($this->markExpired($budget)) {
                    $expired++;
                }
            });

        return $expired;
    }

    /**
     * Transicao para "vencido" de um orcamento especifico. Devolve false quando
     * o orcamento nao esta mais elegivel (ja decidido, cancelado, prazo renovado),
     * para nunca sobrescrever uma decisao ja registrada.
     */
    public function markExpired(Budget $budget): bool
    {
        $previousStatus = trim((string) ($budget->status ?? ''));

        if (! in_array($previousStatus, Budget::awaitingCustomerReplyStatuses(), true)) {
            return false;
        }

        if (! $this->tokenExpired($budget)) {
            return false;
        }

        DB::transaction(function () use ($budget, $previousStatus): void {
            $expiredAt = now();

            $budget->forceFill(['status' => Budget::STATUS_EXPIRED])->save();

            $this->recordStatusHistory(
                $budget,
                $previousStatus,
                Budget::STATUS_EXPIRED,
                'Prazo da proposta encerrado sem resposta do cliente. É necessário enviar um novo orçamento.',
                'sistema',
                null
            );

            $osId = (int) ($budget->os_id ?? 0);
            if ($osId > 0) {
                $this->orderEventService->record(
                    $osId,
                    OrderEvent::CATEGORIA_ORCAMENTO,
                    OrderEvent::TIPO_ORCAMENTO_VENCIDO,
                    'Orçamento vencido sem resposta',
                    sprintf('O prazo do orçamento %s terminou sem resposta do cliente.', $budget->numero),
                    [
                        'orcamento_id' => (int) $budget->id,
                        'numero' => (string) $budget->numero,
                        'status_anterior' => $previousStatus,
                        'venceu_em' => optional($this->expiryDeadline($budget))->toDateTimeString(),
                    ],
                    null,
                    OrderEvent::ORIGEM_SISTEMA,
                    $expiredAt
                );
            }

            // A sincronizacao devolveria a OS para "aguardando_orcamento". Isso e
            // correto numa OS em andamento, mas nao pode reabrir uma OS ja encerrada
            // ou cancelada so porque um orcamento antigo ficou parado em
            // "aguardando resposta" — o fluxo dela seguiu por fora do orcamento.
            if (! $this->orderIsSettled($budget)) {
                $this->budgetOrderSyncService->syncFromBudget($budget, null);
            }

            $this->notificationDispatchService->toUsers(
                $this->budgetDecisionRecipients($budget),
                [
                    'kind' => 'orcamento.expired',
                    'title' => 'Orçamento vencido sem resposta',
                    'body' => sprintf(
                        'O orçamento %s venceu sem resposta do cliente. Envie uma nova proposta para reabrir o link.',
                        $budget->numero
                    ),
                    'route' => '/orcamentos/' . (int) $budget->id,
                    'icon' => 'receipt',
                    'orcamento_id' => (int) $budget->id,
                    'os_id' => (int) ($budget->os_id ?? 0),
                ]
            );
        });

        return true;
    }

    private function approvedStatus(Budget $budget): string
    {
        return (int) ($budget->os_id ?? 0) > 0 || (string) ($budget->tipo_orcamento ?? '') === Budget::TYPE_ASSISTANCE
            ? Budget::STATUS_APPROVED
            : Budget::STATUS_PENDING_OS;
    }

    private function syncOrderForDispatch(Budget $budget, string $relativePdfPath): void
    {
        $orderId = (int) ($budget->os_id ?? 0);
        if ($orderId <= 0) {
            return;
        }

        $order = Order::query()->find($orderId);
        if (! $order instanceof Order) {
            return;
        }

        $order->forceFill([
            'orcamento_pdf' => $relativePdfPath !== '' ? $relativePdfPath : (string) ($order->orcamento_pdf ?? ''),
        ])->save();
    }

    private function syncOrderForDecision(Budget $budget, bool $approved, Carbon $decisionAt): void
    {
        $orderId = (int) ($budget->os_id ?? 0);
        if ($orderId <= 0) {
            return;
        }

        $order = Order::query()->find($orderId);
        if (! $order instanceof Order) {
            return;
        }

        $order->forceFill([
            'orcamento_aprovado' => $approved,
            'data_aprovacao' => $approved ? $decisionAt : null,
        ])->save();
    }

    private function recordStatusHistory(
        Budget $budget,
        ?string $previousStatus,
        string $newStatus,
        ?string $observacao,
        string $origem,
        ?int $userId
    ): void {
        BudgetStatusHistory::query()->create([
            'orcamento_id' => (int) $budget->id,
            'status_anterior' => $previousStatus,
            'status_novo' => $newStatus,
            'observacao' => $observacao,
            'origem' => $origem,
            'alterado_por' => $userId,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function publicBudgetPayload(Budget $budget): array
    {
        $status = trim((string) ($budget->status ?? Budget::STATUS_DRAFT));
        $expired = $this->tokenExpired($budget);
        $canRespond = ! $expired && ! in_array($status, [Budget::STATUS_APPROVED, Budget::STATUS_PENDING_OS, Budget::STATUS_REJECTED], true);

        return [
            'id' => (int) $budget->id,
            'numero' => trim((string) ($budget->numero ?? '')),
            'versao' => (int) ($budget->versao ?? 1),
            'titulo' => trim((string) ($budget->titulo ?? '')),
            'status' => $status,
            'status_label' => Budget::statusLabel($status),
            'company_name' => $this->companyName(),
            'client_name' => $this->resolveDisplayClientName($budget),
            'equipment_name' => trim((string) ($budget->equipment?->resumo_tecnico ?? '')),
            'order_number' => trim((string) ($budget->order?->numero_os ?? '')),
            'phone' => trim((string) ($budget->telefone_contato ?? ($budget->client?->telefone1 ?? ''))),
            'email' => trim((string) ($budget->email_contato ?? ($budget->client?->email ?? ''))),
            'validade_data' => $budget->validade_data instanceof Carbon ? $budget->validade_data->format('d/m/Y') : '',
            'token_expira_em' => $budget->token_expira_em instanceof Carbon ? $budget->token_expira_em->format('d/m/Y H:i') : '',
            'expired' => $expired,
            'can_respond' => $canRespond,
            'subtotal' => round((float) ($budget->subtotal ?? 0), 2),
            'desconto' => round((float) ($budget->desconto ?? 0), 2),
            'acrescimo' => round((float) ($budget->acrescimo ?? 0), 2),
            'total' => round((float) ($budget->total ?? 0), 2),
            'motivo_rejeicao' => trim((string) ($budget->motivo_rejeicao ?? '')),
            // O cliente aprova sabendo como paga e por quanto tempo tem
            // garantia: mesmas condições que saem no PDF.
            'condicoes_comerciais' => $this->budgetCommercialTermsService->forBudget($budget),
            'items' => $budget->items
                ->sortBy('ordem')
                ->values()
                ->map(static fn ($item): array => [
                    'descricao' => trim((string) ($item->descricao ?? '')),
                    'tipo_item' => trim((string) ($item->tipo_item ?? '')),
                    'quantidade' => (float) ($item->quantidade ?? 0),
                    'valor_unitario' => (float) ($item->valor_unitario ?? 0),
                    'desconto' => (float) ($item->desconto ?? 0),
                    'acrescimo' => (float) ($item->acrescimo ?? 0),
                    'total' => (float) ($item->total ?? 0),
                    'observacoes' => trim((string) ($item->observacoes ?? '')),
                ])->all(),
        ];
    }

    public function responseIp(?string $rawIp): ?string
    {
        $rawIp = trim((string) $rawIp);

        return $rawIp !== '' ? Str::limit($rawIp, 45, '') : null;
    }
}
