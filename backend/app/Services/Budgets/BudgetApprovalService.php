<?php

namespace App\Services\Budgets;

use App\Models\Budget;
use App\Models\BudgetApproval;
use App\Models\BudgetSend;
use App\Models\BudgetStatusHistory;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use App\Services\Channels\Whatsapp\PhoneNumberNormalizationService;
use App\Services\Company\CompanyProfileService;
use App\Services\Integrations\IntegrationSettingsService;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Orders\OrderDocumentCenterService;
use App\Services\Orders\OrderEventService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BudgetApprovalService
{
    public function __construct(
        private readonly BudgetPdfService $budgetPdfService,
        private readonly IntegrationSettingsService $integrationSettingsService,
        private readonly CompanyProfileService $companyProfileService,
        private readonly PhoneNumberNormalizationService $phoneNumberNormalizationService,
        private readonly BudgetOrderSyncService $budgetOrderSyncService,
        private readonly OrderEventService $orderEventService,
        private readonly OrderDocumentCenterService $orderDocumentCenterService,
        private readonly NotificationDispatchService $notificationDispatchService
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

        $pendencias = $this->dispatchPendencies($budget);
        if ($pendencias !== []) {
            return [
                'result' => 'validation_error',
                'message' => 'Existem pendências que impedem o envio para aprovação.',
                'details' => [
                    'send_for_approval' => $pendencias,
                ],
            ];
        }

        $token = $this->ensurePublicToken($budget);
        $approvalLink = $this->publicUrl($token);
        $pdf = $this->budgetPdfService->generate($budget, $approvalLink, ['actor' => $user]);

        if (! ($pdf['ok'] ?? false)) {
            return [
                'result' => 'dispatch_failed',
                'message' => (string) ($pdf['message'] ?? 'Falha ao gerar o PDF do orçamento.'),
            ];
        }

        $destinationPhone = $this->resolveDestinationPhone($budget);
        $companyName = $this->companyName();
        $sendAt = now();
        $caption = $this->buildWhatsappCaption($budget, $companyName, $approvalLink);

        $dispatch = $this->integrationSettingsService->sendDirectMedia(
            $destinationPhone,
            (string) ($pdf['absolute_path'] ?? ''),
            'document',
            $caption,
            (string) ($pdf['file_name'] ?? null)
        );

        $dispatchOk = (bool) ($dispatch['ok'] ?? false);
        $provider = trim((string) ($dispatch['provider'] ?? ''));
        $dispatchMessage = trim((string) ($dispatch['message'] ?? ($dispatchOk ? 'Proposta enviada para aprovação.' : 'Falha ao enviar proposta para aprovação.')));
        $sendStatus = $dispatchOk ? 'enviado' : 'erro';
        $targetStatus = $dispatchOk ? Budget::STATUS_WAITING_REPLY : Budget::STATUS_PENDING_SEND;

        DB::transaction(function () use (
            $budget,
            $user,
            $token,
            $pdf,
            $sendAt,
            $destinationPhone,
            $caption,
            $provider,
            $dispatchMessage,
            $dispatchOk,
            $sendStatus,
            $targetStatus
        ): void {
            $budget->refresh();
            $previousStatus = (string) ($budget->status ?? Budget::STATUS_DRAFT);
            $expiry = $this->resolveTokenExpiry($budget);

            $envio = BudgetSend::query()->create([
                'orcamento_id' => (int) $budget->id,
                'canal' => 'whatsapp',
                'destino' => $destinationPhone,
                'mensagem' => $caption,
                'documento_path' => (string) ($pdf['relative_path'] ?? ''),
                'status' => $sendStatus,
                'provedor' => $provider !== '' ? $provider : null,
                'referencia_externa' => null,
                'erro_detalhe' => $dispatchOk ? null : $dispatchMessage,
                'enviado_por' => (int) $user->id,
                'enviado_em' => $dispatchOk ? $sendAt : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

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

                if ($dispatchOk) {
                    $this->orderEventService->record(
                        $osId,
                        OrderEvent::CATEGORIA_ORCAMENTO,
                        OrderEvent::TIPO_ORCAMENTO_ENVIADO,
                        'Orçamento enviado para aprovação',
                        sprintf('Orçamento %s enviado ao cliente para aprovação.', $budget->numero),
                        [
                            'orcamento_id' => (int) $budget->id,
                            'envio_id' => (int) $envio->id,
                            'canal' => 'whatsapp',
                            'destino' => $destinationPhone,
                        ],
                        (int) $user->id
                    );

                    $this->orderEventService->record(
                        $osId,
                        OrderEvent::CATEGORIA_MENSAGEM,
                        OrderEvent::TIPO_WHATSAPP_ENVIADO,
                        'Orçamento enviado por WhatsApp',
                        sprintf('Proposta do orçamento %s enviada com PDF anexo.', $budget->numero),
                        [
                            'origin' => 'orcamento_aprovacao',
                            'orcamento_id' => (int) $budget->id,
                            'envio_id' => (int) $envio->id,
                            'destino' => $destinationPhone,
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
            $this->budgetOrderSyncService->syncFromBudget($budget, (int) $user->id);
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
            'message' => $dispatchMessage !== ''
                ? $dispatchMessage
                : ($dispatchOk ? 'Proposta enviada para aprovação.' : 'Falha ao enviar proposta para aprovação.'),
            'dispatch' => [
                'canal' => 'whatsapp',
                'status' => $sendStatus,
                'destino' => $destinationPhone,
                'public_url' => $approvalLink,
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

        $approvedStatus = $this->approvedStatus($budget);
        $decisionMessage = trim((string) $response) !== '' ? trim((string) $response) : 'Aprovado pelo cliente.';

        DB::transaction(function () use ($budget, $approvedStatus, $decisionMessage, $ipAddress, $userAgent): void {
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
                'origem' => 'link_publico',
                'usuario_id' => null,
                'usuario_nome' => 'Cliente',
                'resposta_cliente' => $decisionMessage,
                'observacao' => 'Aprovação registrada pelo link público do orçamento.',
                'ip_origem' => $ipAddress,
                'user_agent' => $userAgent !== null ? Str::limit($userAgent, 255, '') : null,
                'created_at' => $approvedAt,
            ]);

            $this->recordStatusHistory(
                $budget,
                $previousStatus,
                $approvedStatus,
                'Cliente aprovou o orçamento pelo link público.',
                'cliente',
                null
            );

            $osId = (int) ($budget->os_id ?? 0);
            if ($osId > 0) {
                $this->orderEventService->record(
                    $osId,
                    OrderEvent::CATEGORIA_ORCAMENTO,
                    OrderEvent::TIPO_ORCAMENTO_APROVADO,
                    'Orçamento aprovado pelo cliente',
                    sprintf('Cliente aprovou o orçamento %s pelo link público.', $budget->numero),
                    [
                        'orcamento_id' => (int) $budget->id,
                        'numero' => (string) $budget->numero,
                        'resposta_cliente' => $decisionMessage,
                        'ip_origem' => $ipAddress,
                        'user_agent' => $userAgent,
                    ],
                    null,
                    OrderEvent::ORIGEM_CLIENTE,
                    $approvedAt
                );
            }

            $this->syncOrderForDecision($budget, true, $approvedAt);
            $this->budgetOrderSyncService->syncFromBudget($budget);

            // Sino: avisa responsável, criador e técnico da OS — sem isso a
            // aprovação só aparecia no sino no próximo carregamento manual da
            // lista (nenhum dispatch existia aqui antes, apesar de já
            // documentado em notificacoes-sino.md).
            $this->notificationDispatchService->toUsers(
                $this->budgetDecisionRecipients($budget),
                [
                    'kind' => 'orcamento.approved',
                    'title' => 'Orçamento aprovado pelo cliente',
                    'body' => sprintf(
                        'O cliente aprovou o orçamento %s (R$ %s).',
                        $budget->numero,
                        number_format((float) $budget->total, 2, ',', '.')
                    ),
                    'route' => '/orcamentos/' . (int) $budget->id,
                    'icon' => 'receipt',
                    'orcamento_id' => (int) $budget->id,
                    'os_id' => (int) ($budget->os_id ?? 0),
                ]
            );
        });

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

        DB::transaction(function () use ($budget, $decisionMessage, $ipAddress, $userAgent): void {
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
                'origem' => 'link_publico',
                'usuario_id' => null,
                'usuario_nome' => 'Cliente',
                'resposta_cliente' => $decisionMessage,
                'observacao' => 'Rejeição registrada pelo link público do orçamento.',
                'ip_origem' => $ipAddress,
                'user_agent' => $userAgent !== null ? Str::limit($userAgent, 255, '') : null,
                'created_at' => $rejectedAt,
            ]);

            $this->recordStatusHistory(
                $budget,
                $previousStatus,
                Budget::STATUS_REJECTED,
                'Cliente rejeitou o orçamento pelo link público.',
                'cliente',
                null
            );

            $osId = (int) ($budget->os_id ?? 0);
            if ($osId > 0) {
                $this->orderEventService->record(
                    $osId,
                    OrderEvent::CATEGORIA_ORCAMENTO,
                    OrderEvent::TIPO_ORCAMENTO_RECUSADO,
                    'Orçamento recusado pelo cliente',
                    sprintf('Cliente recusou o orçamento %s pelo link público. Motivo: %s', $budget->numero, $decisionMessage),
                    [
                        'orcamento_id' => (int) $budget->id,
                        'numero' => (string) $budget->numero,
                        'resposta_cliente' => $decisionMessage,
                        'ip_origem' => $ipAddress,
                        'user_agent' => $userAgent,
                    ],
                    null,
                    OrderEvent::ORIGEM_CLIENTE,
                    $rejectedAt
                );
            }

            $this->syncOrderForDecision($budget, false, $rejectedAt);
            $this->budgetOrderSyncService->syncFromBudget($budget);

            // Sino: mesmo aviso da aprovação, ver comentário em approveByToken().
            $this->notificationDispatchService->toUsers(
                $this->budgetDecisionRecipients($budget),
                [
                    'kind' => 'orcamento.rejected',
                    'title' => 'Orçamento recusado pelo cliente',
                    'body' => sprintf('O cliente recusou o orçamento %s.', $budget->numero),
                    'route' => '/orcamentos/' . (int) $budget->id,
                    'icon' => 'receipt',
                    'orcamento_id' => (int) $budget->id,
                    'os_id' => (int) ($budget->os_id ?? 0),
                ]
            );
        });

        return [
            'result' => 'ok',
            'message' => 'Rejeição registrada com sucesso.',
        ];
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
     * @return array<int, string>
     */
    private function dispatchPendencies(Budget $budget): array
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

        if (! $this->hasValidWhatsappPhone($budget)) {
            $pendencias[] = 'Informe um telefone de contato com WhatsApp válido para enviar o PDF de aprovação.';
        }

        return $pendencias;
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
            return $budget->validade_data->copy()->endOfDay();
        }

        $days = max(1, (int) ($budget->validade_dias ?? 10));

        return now()->addDays($days)->endOfDay();
    }

    private function buildWhatsappCaption(Budget $budget, string $companyName, string $approvalLink): string
    {
        $numero = trim((string) ($budget->numero ?? ('ORC-' . (int) $budget->id)));
        $cliente = $this->resolveDisplayClientName($budget);
        $total = 'R$ ' . number_format((float) ($budget->total ?? 0), 2, ',', '.');

        return trim(
            $companyName . "\n\n"
            . 'Segue o orçamento ' . $numero . ($cliente !== '' ? ' para ' . $cliente : '') . ".\n"
            . 'Total da proposta: ' . $total . ".\n\n"
            . 'Analise o PDF em anexo e responda a proposta pelo link abaixo:' . "\n"
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
        if (! $budget->token_expira_em instanceof Carbon) {
            return false;
        }

        return now()->greaterThan($budget->token_expira_em);
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
