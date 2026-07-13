<?php

namespace App\Services\Orders;

use App\Jobs\ProcessOrderDocumentSendJob;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\OrderDocumentFile;
use App\Models\OrderDocumentSend;
use App\Models\OrderDocumentShareLink;
use App\Models\OrderDocumentShareLinkItem;
use App\Models\User;
use App\Services\Budgets\BudgetPdfService;
use App\Services\Integrations\IntegrationSettingsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

class OrderDocumentCenterService
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private const DOCUMENT_TYPES = [
        'abertura' => [
            'label' => 'Comprovante de abertura',
            'template_code' => 'abertura',
            'automatic_triggers' => ['criacao_os'],
        ],
        'orcamento' => [
            'label' => 'Orçamento',
            'template_code' => 'orcamento_enviado',
            'automatic_triggers' => ['envio_orcamento'],
        ],
        'laudo' => [
            'label' => 'Laudo técnico',
            'template_code' => 'laudo_concluido',
            'automatic_triggers' => ['status_tecnico'],
        ],
        'cobranca_manutencao' => [
            'label' => 'Cobrança / manutenção',
            'template_code' => 'cobranca_manutencao',
            'automatic_triggers' => ['baixa_cobranca'],
        ],
        'entrega' => [
            'label' => 'Comprovante de entrega',
            'template_code' => 'entrega_concluida',
            'automatic_triggers' => ['baixa_entrega'],
        ],
        'devolucao_sem_reparo' => [
            'label' => 'Devolução sem reparo',
            'template_code' => 'devolucao_sem_reparo',
            'automatic_triggers' => ['baixa_sem_reparo'],
        ],
    ];

    /**
     * @var array<string, string>
     */
    private const SHARE_EXPIRATIONS = [
        '24h' => '+24 hours',
        '7d' => '+7 days',
        '30d' => '+30 days',
    ];

    public function __construct(
        private readonly OrderWorkflowService $orderWorkflowService,
        private readonly OrderOpeningPdfService $orderOpeningPdfService,
        private readonly OrderClosurePdfService $orderClosurePdfService,
        private readonly BudgetPdfService $budgetPdfService,
        private readonly IntegrationSettingsService $integrationSettingsService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function catalog(int $orderId, User $actor): array
    {
        $context = $this->resolveAuthorizedOrder($orderId, $actor);
        if (($context['result'] ?? 'error') !== 'ok') {
            return $context;
        }

        /** @var Order $order */
        $order = $context['order'];
        $documents = $this->loadDocuments((int) $order->id);

        return [
            'result' => 'ok',
            'order' => $context['payload'],
            'catalog' => $this->buildCatalog($order, $documents),
            'documents' => $documents->map(fn (OrderDocument $document): array => $this->mapDocumentVersion($order, $document))->values()->all(),
            'dispatch_defaults' => $this->buildDispatchDefaults($order, $documents),
            'send_history' => $this->mapSendHistory((int) $order->id),
            'share_links' => $this->mapShareLinks((int) $order->id),
            'limits' => [
                'max_attachments' => 10,
                'max_total_bytes' => 20 * 1024 * 1024,
                'share_expirations' => array_keys(self::SHARE_EXPIRATIONS),
            ],
        ];
    }

    /**
     * @param array<int, string> $types
     * @return array<string, mixed>
     */
    public function generate(int $orderId, User $actor, array $types, array $options = []): array
    {
        $context = $this->resolveAuthorizedOrder($orderId, $actor);
        if (($context['result'] ?? 'error') !== 'ok') {
            return $context;
        }

        /** @var Order $order */
        $order = $context['order'];
        $requestedTypes = $this->normalizeDocumentTypes($types);
        if ($requestedTypes === []) {
            return [
                'result' => 'validation_error',
                'message' => 'Selecione ao menos um tipo de documento para gerar.',
            ];
        }

        $results = [];

        foreach ($requestedTypes as $type) {
            $precondition = $this->generationPrecondition($order, $type);
            if (! ($precondition['ok'] ?? false)) {
                $results[] = [
                    'type' => $type,
                    'ok' => false,
                    'blocked' => true,
                    'message' => (string) ($precondition['reason'] ?? 'Documento sem pré-requisitos suficientes para geração.'),
                ];
                continue;
            }

            try {
                $results[] = $this->generateSingleType($order, $actor, $type, $options);
            } catch (Throwable $exception) {
                report($exception);

                $results[] = [
                    'type' => $type,
                    'ok' => false,
                    'blocked' => false,
                    'message' => 'Falha inesperada ao gerar o documento.',
                ];
            }
        }

        return [
            'result' => 'ok',
            'documents' => $results,
            'catalog' => $this->catalog($orderId, $actor),
        ];
    }

    /**
     * @param array<int, int> $documentIds
     * @return array<string, mixed>
     */
    public function queueSend(int $orderId, User $actor, array $documentIds, array $payload = []): array
    {
        $context = $this->resolveAuthorizedOrder($orderId, $actor);
        if (($context['result'] ?? 'error') !== 'ok') {
            return $context;
        }

        /** @var Order $order */
        $order = $context['order'];
        $format = $this->normalizeFormat((string) ($payload['format'] ?? 'a4'));
        $channel = $this->normalizeChannel((string) ($payload['channel'] ?? 'whatsapp'));
        $documents = $this->resolveSelectedDocuments($order, $documentIds);

        if ($documents->isEmpty()) {
            return [
                'result' => 'validation_error',
                'message' => 'Selecione ao menos um documento válido para envio.',
            ];
        }

        $files = $this->buildSendableFiles($order, $documents, $format);
        $fileValidation = $this->validateBatchFiles($files);
        if (! ($fileValidation['ok'] ?? false)) {
            return [
                'result' => 'validation_error',
                'message' => (string) ($fileValidation['message'] ?? 'Lote de arquivos inválido para envio.'),
            ];
        }

        $destination = $this->resolveDestination($order, $channel, $payload);
        if (! ($destination['ok'] ?? false)) {
            return [
                'result' => 'validation_error',
                'message' => (string) ($destination['message'] ?? 'Destino inválido para envio.'),
            ];
        }

        $templateCode = $this->resolveSuggestedTemplateCode($documents);
        $message = trim((string) ($payload['message'] ?? ''));
        if ($message === '') {
            $message = $this->buildDefaultOutboundMessage($order, $documents, $templateCode);
        }

        $send = OrderDocumentSend::query()->create([
            'os_id' => (int) $order->id,
            'documento_id' => (int) ($documents->first()?->id ?? 0) ?: null,
            'canal' => $channel,
            'destino_mascarado' => (string) ($destination['masked'] ?? ''),
            'destino_criptografado' => Crypt::encryptString((string) ($destination['value'] ?? '')),
            'template_codigo' => $templateCode !== '' ? $templateCode : null,
            'mensagem_final' => $message,
            'status' => 'na_fila',
            'enviado_por' => (int) $actor->id,
            'metadados_json' => [
                'document_ids' => $documents->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                'format' => $format,
                'subject' => $this->buildEmailSubject($order, $documents),
                'destination_name' => $destination['label'] ?? ($order->client?->nome_razao ?? ''),
                'destination_value' => $destination['value'] ?? '',
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ProcessOrderDocumentSendJob::dispatch((int) $send->id)->onQueue('documents');

        $this->recordOrderEvent(
            (int) $order->id,
            'documento',
            'documento_cliente_envio_enfileirado',
            'Envio documental enfileirado',
            'Documentos do cliente adicionados à fila de envio.',
            [
                'canal' => $channel,
                'documentos' => $documents->pluck('tipo_documento')->values()->all(),
                'destino' => $destination['masked'] ?? '',
            ],
            (int) $actor->id
        );

        return [
            'result' => 'ok',
            'message' => 'Envio documental enfileirado com sucesso.',
            'send' => [
                'id' => (int) $send->id,
                'channel' => $channel,
                'destination_masked' => (string) ($destination['masked'] ?? ''),
                'status' => 'na_fila',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function processQueuedSend(int $sendId): array
    {
        if (! Schema::hasTable('os_documento_envios')) {
            return [
                'result' => 'skipped',
                'message' => 'Histórico de envios indisponível neste ambiente.',
            ];
        }

        $send = OrderDocumentSend::query()->find($sendId);
        if (! $send instanceof OrderDocumentSend) {
            return [
                'result' => 'not_found',
            ];
        }

        $order = Order::query()->with(['client', 'equipment', 'equipment.type', 'equipment.brand', 'equipment.model'])->find((int) $send->os_id);
        if (! $order instanceof Order) {
            $send->forceFill([
                'status' => 'erro',
                'erro_sanitizado' => 'OS vinculada ao envio não foi encontrada.',
                'updated_at' => now(),
            ])->save();

            return [
                'result' => 'not_found',
            ];
        }

        $metadata = is_array($send->metadados_json ?? null) ? $send->metadados_json : [];
        $documentIds = collect($metadata['document_ids'] ?? [])->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->values()->all();
        $format = $this->normalizeFormat((string) ($metadata['format'] ?? 'a4'));
        $documents = $this->resolveSelectedDocuments($order, $documentIds);
        $files = $this->buildSendableFiles($order, $documents, $format);

        if ($documents->isEmpty() || $files === []) {
            $send->forceFill([
                'status' => 'erro',
                'erro_sanitizado' => 'Os arquivos selecionados não estão mais disponíveis para envio.',
                'updated_at' => now(),
            ])->save();

            return [
                'result' => 'missing_files',
            ];
        }

        $destination = trim((string) ($metadata['destination_value'] ?? ''));
        $message = trim((string) ($send->mensagem_final ?? ''));
        $subject = trim((string) ($metadata['subject'] ?? $this->buildEmailSubject($order, $documents)));

        try {
            $dispatch = match ($send->canal) {
                'email' => $this->dispatchEmail($destination, $subject, $message, $files),
                default => $this->dispatchWhatsapp($destination, $message, $files),
            };
        } catch (Throwable $exception) {
            report($exception);

            $dispatch = [
                'ok' => false,
                'provider' => $send->canal,
                'reference' => null,
                'message' => $this->sanitizeOperationalError($exception->getMessage()),
            ];
        }

        $ok = (bool) ($dispatch['ok'] ?? false);

        $send->forceFill([
            'status' => $ok ? 'enviado' : 'erro',
            'provedor' => trim((string) ($dispatch['provider'] ?? $send->canal)) ?: $send->canal,
            'referencia_externa' => trim((string) ($dispatch['reference'] ?? '')) ?: null,
            'erro_sanitizado' => $ok ? null : $this->sanitizeOperationalError((string) ($dispatch['message'] ?? 'Falha ao enviar documentos.')),
            'enviado_em' => $ok ? now() : null,
            'updated_at' => now(),
        ])->save();

        $this->recordOrderEvent(
            (int) $order->id,
            'mensagem',
            $ok ? 'documento_cliente_enviado' : 'documento_cliente_envio_falhou',
            $ok ? 'Documentos do cliente enviados' : 'Falha ao enviar documentos do cliente',
            $ok
                ? 'Envio documental concluído para o cliente.'
                : 'Houve falha no envio documental e o lote ficou disponível para reprocessamento.',
            [
                'canal' => (string) $send->canal,
                'destino' => (string) ($send->destino_mascarado ?? ''),
                'documentos' => $documents->pluck('tipo_documento')->values()->all(),
                'provedor' => (string) ($dispatch['provider'] ?? ''),
            ],
            (int) ($send->enviado_por ?? 0) ?: null
        );

        return [
            'result' => $ok ? 'ok' : 'dispatch_failed',
            'message' => $ok ? 'Envio documental concluído.' : (string) ($send->erro_sanitizado ?? 'Falha no envio documental.'),
        ];
    }

    /**
     * @param array<int, int> $documentIds
     * @return array<string, mixed>
     */
    public function createShareLink(int $orderId, User $actor, array $documentIds, array $payload = []): array
    {
        $context = $this->resolveAuthorizedOrder($orderId, $actor);
        if (($context['result'] ?? 'error') !== 'ok') {
            return $context;
        }

        /** @var Order $order */
        $order = $context['order'];
        $documents = $this->resolveSelectedDocuments($order, $documentIds);
        if ($documents->isEmpty()) {
            return [
                'result' => 'validation_error',
                'message' => 'Selecione ao menos um documento para compartilhar.',
            ];
        }

        $expirationKey = trim((string) ($payload['expiracao'] ?? '7d'));
        $ttl = self::SHARE_EXPIRATIONS[$expirationKey] ?? self::SHARE_EXPIRATIONS['7d'];
        $format = $this->normalizeFormat((string) ($payload['format'] ?? 'a4'));
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        $link = DB::transaction(function () use ($order, $actor, $documents, $tokenHash, $format, $ttl): OrderDocumentShareLink {
            /** @var OrderDocumentShareLink $link */
            $link = OrderDocumentShareLink::query()->create([
                'os_id' => (int) $order->id,
                'token_hash' => $tokenHash,
                'formato_padrao' => $format,
                'criado_por' => (int) $actor->id,
                'expira_em' => Carbon::now()->add($ttl),
                'metadados_json' => [
                    'document_types' => $documents->pluck('tipo_documento')->values()->all(),
                ],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($documents as $document) {
                OrderDocumentShareLinkItem::query()->create([
                    'link_id' => (int) $link->id,
                    'documento_id' => (int) $document->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $link;
        });

        $this->recordOrderEvent(
            (int) $order->id,
            'documento',
            'documento_cliente_link_gerado',
            'Link público de documentos gerado',
            'Um link público seguro foi gerado para compartilhamento de documentos do cliente.',
            [
                'link_id' => (int) $link->id,
                'expira_em' => optional($link->expira_em)->toIso8601String(),
                'documentos' => $documents->pluck('tipo_documento')->values()->all(),
            ],
            (int) $actor->id
        );

        return [
            'result' => 'ok',
            'link' => [
                'id' => (int) $link->id,
                'url' => url('/documentos/compartilhados/' . $rawToken),
                'expires_at' => optional($link->expira_em)->toIso8601String(),
                'format' => $format,
            ],
        ];
    }

    public function revokeShareLink(int $orderId, int $linkId, User $actor): array
    {
        $context = $this->resolveAuthorizedOrder($orderId, $actor);
        if (($context['result'] ?? 'error') !== 'ok') {
            return $context;
        }

        $link = OrderDocumentShareLink::query()
            ->where('os_id', $orderId)
            ->whereKey($linkId)
            ->first();

        if (! $link instanceof OrderDocumentShareLink) {
            return [
                'result' => 'not_found',
            ];
        }

        $link->forceFill([
            'revogado_em' => now(),
            'revogado_por' => (int) $actor->id,
            'updated_at' => now(),
        ])->save();

        $this->recordOrderEvent(
            $orderId,
            'documento',
            'documento_cliente_link_revogado',
            'Link público revogado',
            'O link público de documentos do cliente foi revogado manualmente.',
            ['link_id' => $linkId],
            (int) $actor->id
        );

        return ['result' => 'ok'];
    }

    public function publicLinkView(string $token, ?string $ip = null): array
    {
        $linkContext = $this->resolvePublicLink($token);
        if (($linkContext['result'] ?? 'error') !== 'ok') {
            return $linkContext;
        }

        /** @var OrderDocumentShareLink $link */
        $link = $linkContext['link'];
        /** @var Order $order */
        $order = $linkContext['order'];
        /** @var Collection<int, OrderDocument> $documents */
        $documents = $linkContext['documents'];

        $this->touchPublicLink($link, $ip);

        return [
            'result' => 'ok',
            'order' => [
                'id' => (int) $order->id,
                'numero_os' => (string) ($order->numero_os ?? ''),
                'cliente_nome' => (string) ($order->client?->nome_razao ?? ''),
                'equipamento' => $this->resolveEquipmentLabel($order),
            ],
            'link' => [
                'id' => (int) $link->id,
                'expires_at' => optional($link->expira_em)->toIso8601String(),
                'format' => (string) ($link->formato_padrao ?? 'a4'),
            ],
            'documents' => $documents->map(fn (OrderDocument $document): array => [
                'id' => (int) $document->id,
                'type' => (string) $document->tipo_documento,
                'label' => $this->documentTypeLabel((string) $document->tipo_documento),
                'version' => (int) ($document->versao ?? 1),
                'files' => $this->mapPublicDocumentFiles($token, $order, $document),
            ])->values()->all(),
        ];
    }

    public function resolvePublicFile(string $token, int $documentId, string $format): array
    {
        $linkContext = $this->resolvePublicLink($token);
        if (($linkContext['result'] ?? 'error') !== 'ok') {
            return $linkContext;
        }

        /** @var OrderDocumentShareLink $link */
        $link = $linkContext['link'];
        /** @var Order $order */
        $order = $linkContext['order'];
        /** @var Collection<int, OrderDocument> $documents */
        $documents = $linkContext['documents'];

        $document = $documents->first(fn (OrderDocument $candidate): bool => (int) $candidate->id === $documentId);
        if (! $document instanceof OrderDocument) {
            return [
                'result' => 'not_found',
            ];
        }

        $this->touchPublicLink($link, null);

        return $this->resolveDocumentFilePayload($order, $document, $format);
    }

    public function archive(int $orderId, int $documentId, User $actor, bool $archive = true): array
    {
        $context = $this->resolveAuthorizedOrder($orderId, $actor);
        if (($context['result'] ?? 'error') !== 'ok') {
            return $context;
        }

        if (! Schema::hasColumn('os_documentos', 'arquivado_em')) {
            return [
                'result' => 'unsupported',
                'message' => 'Arquivamento lógico indisponível neste ambiente.',
            ];
        }

        $document = OrderDocument::query()
            ->where('os_id', $orderId)
            ->whereKey($documentId)
            ->first();

        if (! $document instanceof OrderDocument) {
            return ['result' => 'not_found'];
        }

        $document->forceFill([
            'arquivado_em' => $archive ? now() : null,
            'arquivado_por' => $archive ? (int) $actor->id : null,
            'updated_at' => now(),
        ])->save();

        return ['result' => 'ok'];
    }

    /**
     * @param array<int, int> $documentIds
     * @return array<string, mixed>
     */
    public function buildZip(int $orderId, User $actor, array $documentIds, string $format = 'a4'): array
    {
        $context = $this->resolveAuthorizedOrder($orderId, $actor);
        if (($context['result'] ?? 'error') !== 'ok') {
            return $context;
        }

        /** @var Order $order */
        $order = $context['order'];
        $documents = $this->resolveSelectedDocuments($order, $documentIds);
        if ($documents->isEmpty()) {
            return [
                'result' => 'validation_error',
                'message' => 'Nenhum documento válido foi selecionado para o ZIP.',
            ];
        }

        $zipName = 'documentos-cliente-' . $this->slug((string) ($order->numero_os ?? ('os-' . $order->id))) . '-' . now()->format('YmdHis') . '.zip';
        $relativePath = 'private/os_documentos/' . (int) $order->id . '/zip/' . $zipName;
        $absolutePath = Storage::disk('local')->path($relativePath);
        $directory = dirname($absolutePath);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return [
                'result' => 'error',
                'message' => 'Não foi possível montar o pacote ZIP agora.',
            ];
        }

        $index = 1;
        foreach ($documents as $document) {
            $file = $this->resolveDocumentFilePayload($order, $document, $format);
            if (($file['result'] ?? 'error') !== 'ok') {
                continue;
            }

            $zip->addFile(
                (string) ($file['file']['absolute_path'] ?? ''),
                $this->safeZipEntryName($document, $format, $index)
            );
            $index++;
        }

        $zip->close();

        return [
            'result' => 'ok',
            'file' => [
                'relative_path' => $relativePath,
                'absolute_path' => $absolutePath,
                'file_name' => $zipName,
            ],
        ];
    }

    /**
     * @param array<int, int> $documentIds
     * @return array<string, mixed>
     */
    public function printBundle(int $orderId, User $actor, array $documentIds, string $format = 'a4'): array
    {
        $context = $this->resolveAuthorizedOrder($orderId, $actor);
        if (($context['result'] ?? 'error') !== 'ok') {
            return $context;
        }

        /** @var Order $order */
        $order = $context['order'];
        $documents = $this->resolveSelectedDocuments($order, $documentIds);

        return [
            'result' => 'ok',
            'order' => [
                'id' => (int) $order->id,
                'numero_os' => (string) ($order->numero_os ?? ''),
            ],
            'format' => $this->normalizeFormat($format),
            'documents' => $documents->map(fn (OrderDocument $document): array => $this->mapDocumentVersion($order, $document))->values()->all(),
        ];
    }

    public function resolveFileForActor(int $orderId, int $documentId, string $format, User $actor): array
    {
        $context = $this->resolveAuthorizedOrder($orderId, $actor);
        if (($context['result'] ?? 'error') !== 'ok') {
            return $context;
        }

        /** @var Order $order */
        $order = $context['order'];
        $document = OrderDocument::query()
            ->with(['files'])
            ->where('os_id', $orderId)
            ->whereKey($documentId)
            ->first();

        if (! $document instanceof OrderDocument) {
            return ['result' => 'not_found'];
        }

        return $this->resolveDocumentFilePayload($order, $document, $format);
    }

    public function syncAfterBudgetDispatch(int $orderId, int $budgetId, string $absolutePath, User $actor, ?string $approvalLink = null): void
    {
        try {
            $order = Order::query()->with(['client', 'equipment', 'equipment.type', 'equipment.brand', 'equipment.model', 'technician'])->find($orderId);
            if (! $order instanceof Order) {
                return;
            }

            if (! is_file($absolutePath)) {
                return;
            }

            $bytes = file_get_contents($absolutePath);
            if ($bytes === false) {
                return;
            }

            $thermalBytes = $this->renderGenericPdfBytes($order, 'orcamento', '80mm', [
                'budget_id' => $budgetId,
                'approval_link' => $approvalLink,
            ]);

            $this->persistDocumentVersion(
                $order,
                'orcamento',
                [
                    'a4' => [
                        'bytes' => $bytes,
                        'mime' => 'application/pdf',
                    ],
                    '80mm' => [
                        'bytes' => $thermalBytes,
                        'mime' => 'application/pdf',
                    ],
                ],
                $actor,
                [
                    'template_codigo' => 'orcamento_enviado',
                    'idempotency_key' => 'budget:' . $budgetId . ':dispatch',
                    'metadata' => [
                        'budget_id' => $budgetId,
                        'approval_link' => $approvalLink,
                    ],
                ]
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function syncAfterClosure(int $orderId, User $actor): void
    {
        try {
            $order = Order::query()->with(['client', 'equipment', 'equipment.type', 'equipment.brand', 'equipment.model', 'technician'])->find($orderId);
            if (! $order instanceof Order) {
                return;
            }

            $status = mb_strtolower(trim((string) ($order->status ?? '')));
            if ($status === 'equipamento_entregue') {
                $this->generate($orderId, $actor, ['entrega']);

                if ((float) ($order->valor_final ?? 0) > 0) {
                    $this->generate($orderId, $actor, ['cobranca_manutencao']);
                }
            }

            if ($status === 'devolvido_sem_reparo') {
                $this->generate($orderId, $actor, ['devolucao_sem_reparo']);
            }

            if ($this->hasTechnicalContent($order) && in_array($status, ['reparo_concluido', 'equipamento_entregue', 'devolvido_sem_reparo', 'irreparavel', 'cancelado'], true)) {
                $this->generate($orderId, $actor, ['laudo']);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function syncAfterStatusChange(int $orderId, User $actor, ?string $newStatus = null): void
    {
        try {
            $order = Order::query()->with(['client', 'equipment', 'equipment.type', 'equipment.brand', 'equipment.model', 'technician'])->find($orderId);
            if (! $order instanceof Order) {
                return;
            }

            $status = $newStatus !== null && $newStatus !== ''
                ? mb_strtolower(trim($newStatus))
                : mb_strtolower(trim((string) ($order->status ?? '')));

            if ($this->hasTechnicalContent($order) && in_array($status, ['reparo_concluido', 'equipamento_entregue', 'devolvido_sem_reparo', 'irreparavel'], true)) {
                $this->generate((int) $order->id, $actor, ['laudo']);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function generateSingleType(Order $order, User $actor, string $type, array $options = []): array
    {
        return match ($type) {
            'abertura' => $this->generateOpeningDocument($order, $actor),
            'orcamento' => $this->generateBudgetDocument($order, $actor),
            default => $this->generateGenericOrderDocument($order, $actor, $type, $options),
        };
    }

    private function generateOpeningDocument(Order $order, User $actor): array
    {
        $result = $this->orderOpeningPdfService->generate($order, $actor);
        if (! ($result['ok'] ?? false)) {
            return [
                'type' => 'abertura',
                'ok' => false,
                'message' => (string) ($result['message'] ?? 'Falha ao gerar o comprovante de abertura.'),
            ];
        }

        $document = OrderDocument::query()
            ->with(['files'])
            ->whereKey((int) ($result['document_id'] ?? 0))
            ->where('os_id', (int) $order->id)
            ->first();

        if ($document instanceof OrderDocument) {
            $this->ensureStoredFormats($order, $document, 'abertura');
        }

        return [
            'type' => 'abertura',
            'ok' => true,
            'document_id' => (int) ($result['document_id'] ?? 0),
            'message' => (string) ($result['message'] ?? 'Documento gerado com sucesso.'),
        ];
    }

    private function generateBudgetDocument(Order $order, User $actor): array
    {
        $budgetRow = $this->latestBudgetRow((int) $order->id);
        if ($budgetRow === null) {
            return [
                'type' => 'orcamento',
                'ok' => false,
                'message' => 'Esta OS ainda não possui orçamento vinculado.',
            ];
        }

        if (! class_exists(\App\Models\Budget::class)) {
            return [
                'type' => 'orcamento',
                'ok' => false,
                'message' => 'O gerador de orçamento não está disponível neste ambiente local.',
            ];
        }

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $budgetClass */
        $budgetClass = \App\Models\Budget::class;
        /** @var \Illuminate\Database\Eloquent\Model|null $budget */
        $budget = $budgetClass::query()->find((int) $budgetRow->id);

        if ($budget === null) {
            return [
                'type' => 'orcamento',
                'ok' => false,
                'message' => 'Não foi possível carregar o orçamento vinculado para gerar o PDF.',
            ];
        }

        $approvalLink = trim((string) ($budgetRow->token_publico ?? '')) !== ''
            ? url('/orcamento/' . rawurlencode((string) $budgetRow->token_publico))
            : '';
        $pdf = $this->budgetPdfService->generate($budget, $approvalLink);

        if (! ($pdf['ok'] ?? false)) {
            return [
                'type' => 'orcamento',
                'ok' => false,
                'message' => (string) ($pdf['message'] ?? 'Falha ao gerar o PDF do orçamento.'),
            ];
        }

        $this->syncAfterBudgetDispatch((int) $order->id, (int) $budgetRow->id, (string) ($pdf['absolute_path'] ?? ''), $actor, $approvalLink);

        $document = OrderDocument::query()
            ->where('os_id', (int) $order->id)
            ->where('tipo_documento', 'orcamento')
            ->orderByDesc('versao')
            ->orderByDesc('id')
            ->first();

        return [
            'type' => 'orcamento',
            'ok' => $document instanceof OrderDocument,
            'document_id' => (int) ($document?->id ?? 0),
            'message' => $document instanceof OrderDocument
                ? 'PDF de orçamento registrado no acervo documental.'
                : 'O PDF do orçamento foi gerado, mas não foi possível registrá-lo no acervo documental.',
        ];
    }

    private function generateGenericOrderDocument(Order $order, User $actor, string $type, array $options = []): array
    {
        $a4Bytes = $this->renderGenericPdfBytes($order, $type, 'a4', $options);
        $thermalBytes = $this->renderGenericPdfBytes($order, $type, '80mm', $options);

        $persisted = $this->persistDocumentVersion(
            $order,
            $type,
            [
                'a4' => [
                    'bytes' => $a4Bytes,
                    'mime' => 'application/pdf',
                ],
                '80mm' => [
                    'bytes' => $thermalBytes,
                    'mime' => 'application/pdf',
                ],
            ],
            $actor,
            [
                'template_codigo' => (string) (self::DOCUMENT_TYPES[$type]['template_code'] ?? $type),
                'metadata' => [
                    'generated_from' => 'order_document_center',
                    'layout_padrao' => 'a4',
                ],
            ]
        );

        return [
            'type' => $type,
            'ok' => (bool) ($persisted['ok'] ?? false),
            'document_id' => (int) ($persisted['document_id'] ?? 0),
            'message' => (string) ($persisted['message'] ?? 'Documento gerado com sucesso.'),
        ];
    }

    private function ensureStoredFormats(Order $order, OrderDocument $document, string $type): void
    {
        if (! Schema::hasTable('os_documento_arquivos')) {
            return;
        }

        $a4 = $this->resolveDocumentFilePayload($order, $document, 'a4');
        if (($a4['result'] ?? 'error') !== 'ok') {
            return;
        }

        $a4File = $a4['file'];
        $a4Bytes = is_file((string) ($a4File['absolute_path'] ?? ''))
            ? file_get_contents((string) ($a4File['absolute_path'] ?? ''))
            : false;

        if (is_string($a4Bytes) && ! $this->hasFormatRecord((int) $document->id, 'a4')) {
            OrderDocumentFile::query()->create([
                'documento_id' => (int) $document->id,
                'formato' => 'a4',
                'arquivo' => (string) ($a4File['relative_path'] ?? $document->arquivo ?? ''),
                'mime' => (string) ($a4File['mime_type'] ?? 'application/pdf'),
                'tamanho_bytes' => strlen($a4Bytes),
                'hash_sha256' => hash('sha256', $a4Bytes),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($this->hasFormatRecord((int) $document->id, '80mm')) {
            return;
        }

        $thermalBytes = $this->renderGenericPdfBytes($order, $type, '80mm');
        $relativePath = 'private/os_documentos/' . (int) $order->id . '/' . $type . '_' . $this->slug((string) ($order->numero_os ?? ('os-' . $order->id))) . '_v' . (int) ($document->versao ?? 1) . '_80mm.pdf';

        Storage::disk('local')->put($relativePath, $thermalBytes);

        OrderDocumentFile::query()->create([
            'documento_id' => (int) $document->id,
            'formato' => '80mm',
            'arquivo' => $relativePath,
            'mime' => 'application/pdf',
            'tamanho_bytes' => strlen($thermalBytes),
            'hash_sha256' => hash('sha256', $thermalBytes),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, array<string, string>> $files
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function persistDocumentVersion(Order $order, string $type, array $files, User $actor, array $options = []): array
    {
        $a4Bytes = (string) ($files['a4']['bytes'] ?? '');
        if ($a4Bytes === '') {
            return [
                'ok' => false,
                'message' => 'O layout A4 é obrigatório para registrar a versão documental.',
            ];
        }

        $templateCode = trim((string) ($options['template_codigo'] ?? ''));
        $idempotencyKey = trim((string) ($options['idempotency_key'] ?? ''));
        $metadata = is_array($options['metadata'] ?? null) ? $options['metadata'] : [];

        if ($idempotencyKey !== '' && Schema::hasColumn('os_documentos', 'idempotency_key')) {
            $existing = OrderDocument::query()
                ->where('os_id', (int) $order->id)
                ->where('tipo_documento', $type)
                ->where('idempotency_key', $idempotencyKey)
                ->orderByDesc('id')
                ->first();

            if ($existing instanceof OrderDocument) {
                return [
                    'ok' => true,
                    'document_id' => (int) $existing->id,
                    'message' => 'Versão documental reaproveitada por idempotência.',
                ];
            }
        }

        $baseSlug = $this->slug((string) ($order->numero_os ?? ('os-' . $order->id)));

        try {
            /** @var array{document: OrderDocument} $persisted */
            $persisted = DB::transaction(function () use ($order, $type, $files, $actor, $templateCode, $idempotencyKey, $metadata, $baseSlug): array {
                $version = max(
                    1,
                    ((int) DB::table('os_documentos')
                        ->where('os_id', (int) $order->id)
                        ->where('tipo_documento', $type)
                        ->lockForUpdate()
                        ->max('versao')) + 1
                );

                $preparedFiles = [];
                foreach ($files as $format => $file) {
                    $normalizedFormat = $this->normalizeFormat((string) $format);
                    $bytes = (string) ($file['bytes'] ?? '');
                    if ($bytes === '') {
                        continue;
                    }

                    $relativePath = 'private/os_documentos/' . (int) $order->id . '/' . $type . '_' . $baseSlug . '_v' . $version . '_' . $normalizedFormat . '.pdf';
                    Storage::disk('local')->put($relativePath, $bytes);

                    $preparedFiles[$normalizedFormat] = [
                        'relative_path' => $relativePath,
                        'mime' => (string) ($file['mime'] ?? 'application/pdf'),
                        'bytes' => $bytes,
                    ];
                }

                $documentPayload = [
                    'os_id' => (int) $order->id,
                    'tipo_documento' => $type,
                    'arquivo' => (string) ($preparedFiles['a4']['relative_path'] ?? ''),
                    'versao' => $version,
                    'hash_sha1' => sha1((string) ($preparedFiles['a4']['bytes'] ?? '')),
                    'gerado_por' => (int) $actor->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (Schema::hasColumn('os_documentos', 'hash_sha256')) {
                    $documentPayload['hash_sha256'] = hash('sha256', (string) ($preparedFiles['a4']['bytes'] ?? ''));
                }

                if (Schema::hasColumn('os_documentos', 'template_codigo')) {
                    $documentPayload['template_codigo'] = $templateCode !== '' ? $templateCode : null;
                }

                if (Schema::hasColumn('os_documentos', 'idempotency_key')) {
                    $documentPayload['idempotency_key'] = $idempotencyKey !== '' ? $idempotencyKey : null;
                }

                if (Schema::hasColumn('os_documentos', 'metadados_json')) {
                    $documentPayload['metadados_json'] = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                /** @var OrderDocument $document */
                $document = OrderDocument::query()->create($documentPayload);

                if (Schema::hasTable('os_documento_arquivos')) {
                    foreach ($preparedFiles as $format => $file) {
                        OrderDocumentFile::query()->create([
                            'documento_id' => (int) $document->id,
                            'formato' => $format,
                            'arquivo' => (string) $file['relative_path'],
                            'mime' => (string) $file['mime'],
                            'tamanho_bytes' => strlen((string) $file['bytes']),
                            'hash_sha256' => hash('sha256', (string) $file['bytes']),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                return [
                    'document' => $document,
                ];
            });
        } catch (Throwable $exception) {
            report($exception);

            return [
                'ok' => false,
                'message' => 'Falha ao persistir a nova versão documental.',
            ];
        }

        /** @var OrderDocument $document */
        $document = $persisted['document'];

        $this->recordOrderEvent(
            (int) $order->id,
            'documento',
            'documento_cliente_gerado',
            'Documento do cliente gerado',
            'Uma nova versão documental foi registrada para a OS.',
            [
                'documento_id' => (int) $document->id,
                'tipo_documento' => $type,
                'versao' => (int) ($document->versao ?? 1),
            ],
            (int) $actor->id
        );

        return [
            'ok' => true,
            'document_id' => (int) $document->id,
            'message' => 'Documento gerado com sucesso.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveAuthorizedOrder(int $orderId, User $actor): array
    {
        $detail = $this->orderWorkflowService->showForUser($actor, $orderId);
        if (($detail['result'] ?? 'error') !== 'ok') {
            return $detail;
        }

        $order = Order::query()
            ->with(['client', 'equipment', 'equipment.type', 'equipment.brand', 'equipment.model', 'technician'])
            ->find($orderId);

        if (! $order instanceof Order) {
            return ['result' => 'not_found'];
        }

        return [
            'result' => 'ok',
            'order' => $order,
            'payload' => $detail['order'] ?? [],
        ];
    }

    /**
     * @return Collection<int, OrderDocument>
     */
    private function loadDocuments(int $orderId): Collection
    {
        return OrderDocument::query()
            ->with(['generatedBy', 'files'])
            ->where('os_id', $orderId)
            ->orderBy('tipo_documento')
            ->orderByDesc('versao')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param Collection<int, OrderDocument> $documents
     * @return array<int, array<string, mixed>>
     */
    private function buildCatalog(Order $order, Collection $documents): array
    {
        $catalog = [];

        foreach (self::DOCUMENT_TYPES as $type => $definition) {
            $versions = $documents->filter(fn (OrderDocument $document): bool => (string) $document->tipo_documento === $type)->values();
            $latest = $versions->first();
            $precondition = $this->generationPrecondition($order, $type);

            $catalog[] = [
                'type' => $type,
                'label' => (string) ($definition['label'] ?? $type),
                'template_code' => (string) ($definition['template_code'] ?? ''),
                'automatic_triggers' => $definition['automatic_triggers'] ?? [],
                'can_generate' => (bool) ($precondition['ok'] ?? false),
                'blocked_reason' => (string) ($precondition['reason'] ?? ''),
                'latest_document' => $latest instanceof OrderDocument ? $this->mapDocumentVersion($order, $latest) : null,
                'versions' => $versions->map(fn (OrderDocument $document): array => $this->mapDocumentVersion($order, $document))->all(),
            ];
        }

        $legacyTypes = $documents
            ->pluck('tipo_documento')
            ->map(fn ($type): string => (string) $type)
            ->unique()
            ->filter(fn (string $type): bool => ! array_key_exists($type, self::DOCUMENT_TYPES))
            ->values();

        foreach ($legacyTypes as $legacyType) {
            $versions = $documents->filter(fn (OrderDocument $document): bool => (string) $document->tipo_documento === $legacyType)->values();
            $latest = $versions->first();

            $catalog[] = [
                'type' => $legacyType,
                'label' => $this->documentTypeLabel($legacyType),
                'template_code' => '',
                'automatic_triggers' => [],
                'can_generate' => false,
                'blocked_reason' => 'Tipo legado disponível apenas para consulta histórica.',
                'latest_document' => $latest instanceof OrderDocument ? $this->mapDocumentVersion($order, $latest) : null,
                'versions' => $versions->map(fn (OrderDocument $document): array => $this->mapDocumentVersion($order, $document))->all(),
            ];
        }

        return $catalog;
    }

    /**
     * @return array<string, mixed>
     */
    private function generationPrecondition(Order $order, string $type): array
    {
        return match ($type) {
            'abertura' => ['ok' => true, 'reason' => ''],
            'orcamento' => $this->latestBudgetRow((int) $order->id) !== null
                ? ['ok' => true, 'reason' => '']
                : ['ok' => false, 'reason' => 'A OS ainda não possui orçamento vinculado.'],
            'laudo' => $this->hasTechnicalContent($order)
                ? ['ok' => true, 'reason' => '']
                : ['ok' => false, 'reason' => 'Preencha diagnóstico técnico ou solução aplicada para liberar o laudo.'],
            'cobranca_manutencao' => (float) ($order->valor_final ?? 0) > 0
                ? ['ok' => true, 'reason' => '']
                : ['ok' => false, 'reason' => 'A cobrança só pode ser gerada quando houver valor final positivo na OS.'],
            'entrega' => mb_strtolower(trim((string) ($order->status ?? ''))) === 'equipamento_entregue'
                ? ['ok' => true, 'reason' => '']
                : ['ok' => false, 'reason' => 'O comprovante de entrega só fica disponível quando a OS é encerrada como equipamento entregue.'],
            'devolucao_sem_reparo' => mb_strtolower(trim((string) ($order->status ?? ''))) === 'devolvido_sem_reparo'
                ? ['ok' => true, 'reason' => '']
                : ['ok' => false, 'reason' => 'A devolução sem reparo só fica disponível quando a OS é encerrada nesse status.'],
            default => ['ok' => false, 'reason' => 'Tipo de documento não suportado para geração.'],
        };
    }

    private function hasTechnicalContent(Order $order): bool
    {
        return trim((string) ($order->diagnostico_tecnico ?? '')) !== ''
            || trim((string) ($order->solucao_aplicada ?? '')) !== ''
            || trim((string) ($order->procedimentos_executados ?? '')) !== '';
    }

    /**
     * @param array<int, int> $documentIds
     * @return Collection<int, OrderDocument>
     */
    private function resolveSelectedDocuments(Order $order, array $documentIds): Collection
    {
        $ids = collect($documentIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return collect();
        }

        return OrderDocument::query()
            ->with(['files', 'generatedBy'])
            ->where('os_id', (int) $order->id)
            ->whereIn('id', $ids)
            ->orderBy('tipo_documento')
            ->orderByDesc('versao')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveDocumentFilePayload(Order $order, OrderDocument $document, string $format): array
    {
        $normalizedFormat = $this->normalizeFormat($format);

        if ($normalizedFormat === 'a4') {
            $relativePath = trim((string) ($document->arquivo ?? ''));
            $file = $this->resolveLocalManagedFile($relativePath);
            if ($file === null) {
                return ['result' => 'missing_file'];
            }

            return ['result' => 'ok', 'file' => $file];
        }

        $fileRecord = $document->relationLoaded('files')
            ? $document->files->first(fn (OrderDocumentFile $file): bool => (string) $file->formato === $normalizedFormat)
            : OrderDocumentFile::query()
                ->where('documento_id', (int) $document->id)
                ->where('formato', $normalizedFormat)
                ->first();

        if (! $fileRecord instanceof OrderDocumentFile) {
            return ['result' => 'missing_file'];
        }

        $file = $this->resolveLocalManagedFile((string) ($fileRecord->arquivo ?? ''));
        if ($file === null) {
            return ['result' => 'missing_file'];
        }

        return ['result' => 'ok', 'file' => $file];
    }

    /**
     * @return array<string, string>|null
     */
    private function resolveLocalManagedFile(string $relativePath): ?array
    {
        $relativePath = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
        if ($relativePath === '' || str_contains($relativePath, '..') || ! Storage::disk('local')->exists($relativePath)) {
            return null;
        }

        $mime = trim((string) Storage::disk('local')->mimeType($relativePath));

        return [
            'relative_path' => $relativePath,
            'absolute_path' => Storage::disk('local')->path($relativePath),
            'filename' => basename($relativePath),
            'mime_type' => $mime !== '' ? $mime : 'application/pdf',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapSendHistory(int $orderId): array
    {
        if (! Schema::hasTable('os_documento_envios')) {
            return [];
        }

        return OrderDocumentSend::query()
            ->with(['sender'])
            ->where('os_id', $orderId)
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(function (OrderDocumentSend $send): array {
                $metadata = is_array($send->metadados_json ?? null) ? $send->metadados_json : [];

                return [
                    'id' => (int) $send->id,
                    'channel' => (string) ($send->canal ?? ''),
                    'destination_masked' => (string) ($send->destino_mascarado ?? ''),
                    'template_code' => (string) ($send->template_codigo ?? ''),
                    'status' => (string) ($send->status ?? ''),
                    'provider' => (string) ($send->provedor ?? ''),
                    'reference' => (string) ($send->referencia_externa ?? ''),
                    'error' => (string) ($send->erro_sanitizado ?? ''),
                    'sent_at' => optional($send->enviado_em)->toIso8601String(),
                    'created_at' => optional($send->created_at)->toIso8601String(),
                    'sender' => [
                        'id' => (int) ($send->sender?->id ?? 0),
                        'name' => (string) ($send->sender?->nome ?? ''),
                    ],
                    'document_ids' => array_values(array_map('intval', (array) ($metadata['document_ids'] ?? []))),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapShareLinks(int $orderId): array
    {
        if (! Schema::hasTable('os_documento_links')) {
            return [];
        }

        return OrderDocumentShareLink::query()
            ->with(['items', 'creator'])
            ->where('os_id', $orderId)
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(function (OrderDocumentShareLink $link): array {
                return [
                    'id' => (int) $link->id,
                    'format' => (string) ($link->formato_padrao ?? 'a4'),
                    'expires_at' => optional($link->expira_em)->toIso8601String(),
                    'revoked_at' => optional($link->revogado_em)->toIso8601String(),
                    'access_count' => (int) ($link->acessos_count ?? 0),
                    'creator' => [
                        'id' => (int) ($link->creator?->id ?? 0),
                        'name' => (string) ($link->creator?->nome ?? ''),
                    ],
                    'document_ids' => $link->items->pluck('documento_id')->map(fn ($id): int => (int) $id)->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePublicLink(string $token): array
    {
        if (! Schema::hasTable('os_documento_links')) {
            return ['result' => 'not_found'];
        }

        $hash = hash('sha256', $token);

        $link = OrderDocumentShareLink::query()
            ->with(['items'])
            ->where('token_hash', $hash)
            ->first();

        if (! $link instanceof OrderDocumentShareLink) {
            return ['result' => 'not_found'];
        }

        if ($link->revogado_em !== null) {
            return ['result' => 'revoked'];
        }

        if ($link->expira_em instanceof Carbon && $link->expira_em->isPast()) {
            return ['result' => 'expired'];
        }

        $order = Order::query()->with(['client', 'equipment', 'equipment.type', 'equipment.brand', 'equipment.model'])->find((int) $link->os_id);
        if (! $order instanceof Order) {
            return ['result' => 'not_found'];
        }

        $documentIds = $link->items->pluck('documento_id')->map(fn ($id): int => (int) $id)->values()->all();
        $documents = OrderDocument::query()
            ->with(['files'])
            ->where('os_id', (int) $order->id)
            ->whereIn('id', $documentIds)
            ->orderBy('tipo_documento')
            ->orderByDesc('versao')
            ->get();

        return [
            'result' => 'ok',
            'link' => $link,
            'order' => $order,
            'documents' => $documents,
        ];
    }

    private function touchPublicLink(OrderDocumentShareLink $link, ?string $ip): void
    {
        try {
            $link->forceFill([
                'acessos_count' => (int) ($link->acessos_count ?? 0) + 1,
                'ultimo_acesso_em' => now(),
                'ultimo_acesso_ip_hash' => $ip !== null && $ip !== '' ? hash('sha256', $ip . '|' . (string) config('app.key')) : $link->ultimo_acesso_ip_hash,
                'updated_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapPublicDocumentFiles(string $token, Order $order, OrderDocument $document): array
    {
        $files = [];

        foreach (['a4', '80mm'] as $format) {
            $resolved = $this->resolveDocumentFilePayload($order, $document, $format);
            if (($resolved['result'] ?? 'error') !== 'ok') {
                continue;
            }

            $files[] = [
                'format' => $format,
                'label' => $format === '80mm' ? '80mm' : 'A4',
                'url' => url('/documentos/compartilhados/' . $token . '/arquivos/' . (int) $document->id . '/' . $format),
            ];
        }

        return $files;
    }

    private function renderGenericPdfBytes(Order $order, string $type, string $layout, array $options = []): string
    {
        $html = $this->renderGenericDocumentHtml($order, $type, $layout, $options);

        $pdf = Pdf::loadHTML($html)
            ->setOption('isRemoteEnabled', false)
            ->setOption('isPhpEnabled', false);

        if ($layout === '80mm') {
            $pdf->setPaper([0, 0, 226.77, 1200], 'portrait');
        } else {
            $pdf->setPaper('a4', 'portrait');
        }

        return $pdf->output();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function renderGenericDocumentHtml(Order $order, string $type, string $layout, array $options = []): string
    {
        $title = $this->documentTypeLabel($type);
        $clientName = $this->escape($order->client?->nome_razao ?? 'Não informado');
        $equipment = $this->escape($this->resolveEquipmentLabel($order));
        $serial = $this->escape((string) ($order->equipment?->numero_serie ?? 'Não informada'));
        $status = $this->escape((string) ($order->status ?? ''));
        $generatedAt = now()->format('d/m/Y H:i');
        $body = $this->documentNarrative($order, $type, $options);

        if ($layout === '80mm') {
            return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><style>
                *{box-sizing:border-box} body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#0f172a;margin:0;padding:10px}
                h1{font-size:14px;margin:0 0 8px} h2{font-size:11px;margin:10px 0 4px}
                .muted{color:#475569}.divider{border-top:1px dashed #94a3b8;margin:8px 0}
                .row{margin-bottom:4px}.label{font-weight:700}
                table{width:100%;border-collapse:collapse;margin-top:6px} td{padding:3px 0;vertical-align:top}
            </style></head><body>
                <h1>' . $this->escape($title) . '</h1>
                <div class="row"><span class="label">OS:</span> ' . $this->escape((string) ($order->numero_os ?? ('#' . $order->id))) . '</div>
                <div class="row"><span class="label">Cliente:</span> ' . $clientName . '</div>
                <div class="row"><span class="label">Equipamento:</span> ' . $equipment . '</div>
                <div class="row"><span class="label">S/N:</span> ' . $serial . '</div>
                <div class="row"><span class="label">Status:</span> ' . $status . '</div>
                <div class="divider"></div>
                ' . $body . '
                <div class="divider"></div>
                <div class="muted">Gerado em ' . $this->escape($generatedAt) . '</div>
            </body></html>';
        }

        return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><style>
            *{box-sizing:border-box} body{font-family:DejaVu Sans,sans-serif;color:#0f172a;font-size:12px;line-height:1.5;margin:0;padding:24px}
            .header{display:flex;justify-content:space-between;gap:16px;margin-bottom:20px}.eyebrow{font-size:11px;color:#1d4ed8;text-transform:uppercase;letter-spacing:.08em;font-weight:700}
            h1{margin:0;font-size:24px;line-height:1.15}.chip{display:inline-block;padding:8px 12px;border:1px solid #bfdbfe;border-radius:999px;background:#eff6ff;color:#1e3a8a;font-size:11px;font-weight:700}
            table{width:100%;border-collapse:collapse;margin-top:14px} td{border:1px solid #dbe4f0;padding:8px 10px;vertical-align:top}.label{width:28%;font-weight:700;background:#f8fbff;color:#334155}
            .section{margin-top:20px}.section h2{margin:0 0 8px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#1e293b}
            .box{padding:12px;border:1px solid #dbeafe;border-radius:14px;background:#f8fbff}.muted{color:#475569}
        </style></head><body>
            <div class="header">
                <div>
                    <div class="eyebrow">Central documental da OS</div>
                    <h1>' . $this->escape($title) . '</h1>
                </div>
                <div class="chip">Gerado em ' . $this->escape($generatedAt) . '</div>
            </div>
            <table>
                <tr><td class="label">OS</td><td>' . $this->escape((string) ($order->numero_os ?? ('#' . $order->id))) . '</td></tr>
                <tr><td class="label">Cliente</td><td>' . $clientName . '</td></tr>
                <tr><td class="label">Equipamento</td><td>' . $equipment . '</td></tr>
                <tr><td class="label">S/N</td><td>' . $serial . '</td></tr>
                <tr><td class="label">Status</td><td>' . $status . '</td></tr>
                <tr><td class="label">Valor final</td><td>' . $this->escape($this->money((float) ($order->valor_final ?? 0))) . '</td></tr>
            </table>
            <div class="section">
                <h2>Resumo</h2>
                <div class="box">' . $body . '</div>
            </div>
        </body></html>';
    }

    /**
     * @param array<string, mixed> $options
     */
    private function documentNarrative(Order $order, string $type, array $options = []): string
    {
        $diagnosis = trim((string) ($order->diagnostico_tecnico ?? ''));
        $solution = trim((string) ($order->solucao_aplicada ?? ''));
        $report = trim((string) ($order->relato_cliente ?? ''));
        $formattedReport = $report !== '' ? nl2br($this->escape($report), false) : '<span class="muted">Não informado.</span>';
        $formattedDiagnosis = $diagnosis !== '' ? nl2br($this->escape($diagnosis), false) : '<span class="muted">Não informado.</span>';
        $formattedSolution = $solution !== '' ? nl2br($this->escape($solution), false) : '<span class="muted">Não informado.</span>';

        return match ($type) {
            'abertura' => '<strong>Relato do cliente:</strong><br>' . $formattedReport,
            'orcamento' => '<strong>Orçamento vinculado:</strong><br>Esta versão registra o PDF comercial associado à OS para envio e histórico do cliente.'
                . (trim((string) ($options['approval_link'] ?? '')) !== '' ? '<br><br><strong>Link de aprovação:</strong><br>' . $this->escape((string) $options['approval_link']) : ''),
            'laudo' => '<strong>Diagnóstico técnico:</strong><br>' . $formattedDiagnosis . '<br><br><strong>Solução aplicada:</strong><br>' . $formattedSolution,
            'cobranca_manutencao' => '<strong>Resumo financeiro:</strong><br>Valor final consolidado da OS: ' . $this->escape($this->money((float) ($order->valor_final ?? 0))) . '.<br><br><strong>Forma de pagamento:</strong><br>' . $this->escape((string) ($order->forma_pagamento ?? 'Não informada')),
            'entrega' => '<strong>Entrega concluída:</strong><br>OS encerrada com status de equipamento entregue.<br><br><strong>Observações do atendimento:</strong><br>' . $formattedSolution,
            'devolucao_sem_reparo' => '<strong>Devolução sem reparo:</strong><br>A OS foi encerrada sem execução de reparo.<br><br><strong>Justificativa / diagnóstico:</strong><br>' . $formattedDiagnosis,
            default => '<span class="muted">Documento sem narrativa específica.</span>',
        };
    }

    /**
     * @param Collection<int, OrderDocument> $documents
     */
    private function resolveSuggestedTemplateCode(Collection $documents): string
    {
        $firstType = (string) ($documents->first()?->tipo_documento ?? '');

        return $this->resolveTemplateCodeForDocumentType($firstType);
    }

    private function resolveTemplateCodeForDocumentType(string $type): string
    {
        return (string) (self::DOCUMENT_TYPES[$type]['template_code'] ?? '');
    }

    /**
     * @param Collection<int, OrderDocument> $documents
     * @return array<string, mixed>
     */
    private function buildDispatchDefaults(Order $order, Collection $documents): array
    {
        $defaultWhatsapp = $this->resolveDefaultDestinationValue($order, 'whatsapp');
        $defaultEmail = $this->resolveDefaultDestinationValue($order, 'email');
        $preferredChannel = $defaultWhatsapp !== '' ? 'whatsapp' : 'email';

        $referenceDocument = $documents
            ->filter(fn (OrderDocument $document): bool => $document->arquivado_em === null)
            ->sortByDesc(fn (OrderDocument $document): int => (int) $document->id)
            ->first();

        $templateCode = '';
        $message = '';

        if ($referenceDocument instanceof OrderDocument) {
            $selection = collect([$referenceDocument]);
            $templateCode = $this->resolveSuggestedTemplateCode($selection);
            $message = $this->buildDefaultOutboundMessage($order, $selection, $templateCode);
        }

        return [
            'channel' => $preferredChannel,
            'format' => 'a4',
            'destination' => $preferredChannel === 'email' ? $defaultEmail : $defaultWhatsapp,
            'destinations' => [
                'whatsapp' => $defaultWhatsapp,
                'email' => $defaultEmail,
            ],
            'message' => $message,
            'template_code' => $templateCode,
        ];
    }

    /**
     * @param Collection<int, OrderDocument> $documents
     */
    private function buildDefaultOutboundMessage(Order $order, Collection $documents, string $templateCode): string
    {
        $templateMessage = $this->resolveWhatsappTemplateBody($templateCode);
        if ($templateMessage !== '') {
            return strtr($templateMessage, [
                '{{numero_os}}' => (string) ($order->numero_os ?? ''),
                '{{cliente_nome}}' => (string) ($order->client?->nome_razao ?? ''),
                '{{equipamento}}' => $this->resolveEquipmentLabel($order),
            ]);
        }

        $labels = $documents->map(fn (OrderDocument $document): string => $this->documentTypeLabel((string) $document->tipo_documento))->values()->all();

        return 'Olá! Seguem os documentos da sua OS ' . (string) ($order->numero_os ?? '') . ': ' . implode(', ', $labels) . '.';
    }

    private function resolveWhatsappTemplateBody(string $templateCode): string
    {
        if ($templateCode === '' || ! Schema::hasTable('whatsapp_templates')) {
            return '';
        }

        $template = DB::table('whatsapp_templates')
            ->where('codigo', $templateCode)
            ->where('ativo', true)
            ->orderByDesc('id')
            ->first(['conteudo']);

        return trim((string) ($template->conteudo ?? ''));
    }

    /**
     * @param array<int, array<string, string>> $files
     * @return array<string, mixed>
     */
    private function dispatchWhatsapp(string $phone, string $message, array $files): array
    {
        $provider = '';
        $reference = null;

        foreach ($files as $index => $file) {
            $caption = $index === 0 ? $message : '';
            $result = $this->integrationSettingsService->sendDirectMedia(
                $phone,
                (string) ($file['absolute_path'] ?? ''),
                'document',
                $caption !== '' ? $caption : null,
                (string) ($file['filename'] ?? null)
            );

            if (! ($result['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'provider' => (string) ($result['provider'] ?? 'whatsapp'),
                    'reference' => $reference,
                    'message' => (string) ($result['message'] ?? 'Falha ao despachar documento via WhatsApp.'),
                ];
            }

            $provider = (string) ($result['provider'] ?? 'whatsapp');
            $reference = (string) ($result['reference'] ?? $reference);
        }

        if ($files === [] && $message !== '') {
            $result = $this->integrationSettingsService->sendDirectMessage($phone, $message);

            return [
                'ok' => (bool) ($result['ok'] ?? false),
                'provider' => (string) ($result['provider'] ?? 'whatsapp'),
                'reference' => (string) ($result['reference'] ?? ''),
                'message' => (string) ($result['message'] ?? ''),
            ];
        }

        return [
            'ok' => true,
            'provider' => $provider !== '' ? $provider : 'whatsapp',
            'reference' => $reference,
            'message' => 'Envio por WhatsApp concluído.',
        ];
    }

    /**
     * @param array<int, array<string, string>> $files
     * @return array<string, mixed>
     */
    private function dispatchEmail(string $email, string $subject, string $message, array $files): array
    {
        Mail::html(
            nl2br($this->escape($message), false),
            function ($mail) use ($email, $subject, $files): void {
                $mail->to($email)->subject($subject);

                foreach ($files as $file) {
                    $mail->attach((string) ($file['absolute_path'] ?? ''), [
                        'as' => (string) ($file['filename'] ?? 'documento.pdf'),
                        'mime' => (string) ($file['mime_type'] ?? 'application/pdf'),
                    ]);
                }
            }
        );

        return [
            'ok' => true,
            'provider' => 'mail',
            'reference' => null,
            'message' => 'Envio por e-mail concluído.',
        ];
    }

    /**
     * @param Collection<int, OrderDocument> $documents
     */
    private function buildEmailSubject(Order $order, Collection $documents): string
    {
        $label = $documents->count() === 1
            ? $this->documentTypeLabel((string) ($documents->first()?->tipo_documento ?? 'documento'))
            : 'Documentos da OS';

        return $label . ' - ' . (string) ($order->numero_os ?? ('OS #' . $order->id));
    }

    /**
     * @param Collection<int, OrderDocument> $documents
     * @return array<int, array<string, string>>
     */
    private function buildSendableFiles(Order $order, Collection $documents, string $format): array
    {
        $files = [];

        foreach ($documents as $document) {
            $result = $this->resolveDocumentFilePayload($order, $document, $format);
            if (($result['result'] ?? 'error') !== 'ok') {
                continue;
            }

            $file = $result['file'];
            $files[] = [
                'absolute_path' => (string) ($file['absolute_path'] ?? ''),
                'filename' => $this->safeZipEntryName($document, $format, count($files) + 1),
                'mime_type' => (string) ($file['mime_type'] ?? 'application/pdf'),
            ];
        }

        return $files;
    }

    /**
     * @param array<int, array<string, string>> $files
     * @return array<string, mixed>
     */
    private function validateBatchFiles(array $files): array
    {
        if ($files === []) {
            return [
                'ok' => false,
                'message' => 'Nenhum arquivo está disponível no formato selecionado.',
            ];
        }

        if (count($files) > 10) {
            return [
                'ok' => false,
                'message' => 'O lote suporta no máximo 10 anexos. Para volumes maiores, utilize link público seguro.',
            ];
        }

        $totalBytes = 0;
        foreach ($files as $file) {
            $absolutePath = (string) ($file['absolute_path'] ?? '');
            if ($absolutePath !== '' && is_file($absolutePath)) {
                $totalBytes += filesize($absolutePath) ?: 0;
            }
        }

        if ($totalBytes > 20 * 1024 * 1024) {
            return [
                'ok' => false,
                'message' => 'O lote excede 20 MB. Use link público seguro para esse volume de arquivos.',
            ];
        }

        return ['ok' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveDestination(Order $order, string $channel, array $payload): array
    {
        $override = trim((string) ($payload['destino'] ?? ''));
        $confirmOverride = filter_var($payload['confirmar_destino_alternativo'] ?? false, FILTER_VALIDATE_BOOL);

        $defaultValue = $this->resolveDefaultDestinationValue($order, $channel);

        $value = $override !== '' ? $override : $defaultValue;
        if ($value === '') {
            return [
                'ok' => false,
                'message' => $channel === 'email'
                    ? 'Cliente sem e-mail cadastrado para o envio.'
                    : 'Cliente sem telefone cadastrado para o envio.',
            ];
        }

        if ($override !== '' && $override !== $defaultValue && ! $confirmOverride) {
            return [
                'ok' => false,
                'message' => 'Confirme explicitamente o destino alternativo antes de enviar os documentos.',
            ];
        }

        return [
            'ok' => true,
            'value' => $value,
            'masked' => $this->maskSensitiveDestination($value, $channel),
            'label' => (string) ($order->client?->nome_razao ?? 'Cliente'),
        ];
    }

    private function resolveDefaultDestinationValue(Order $order, string $channel): string
    {
        return $channel === 'email'
            ? trim((string) ($order->client?->email ?? ''))
            : trim((string) ($order->client?->telefone1 ?? $order->client?->telefone_contato ?? ''));
    }

    private function latestBudgetRow(int $orderId): ?object
    {
        if (! Schema::hasTable('orcamentos')) {
            return null;
        }

        return DB::table('orcamentos')
            ->where('os_id', $orderId)
            ->orderByDesc('id')
            ->first(['id', 'numero', 'versao', 'token_publico']);
    }

    private function hasFormatRecord(int $documentId, string $format): bool
    {
        if (! Schema::hasTable('os_documento_arquivos')) {
            return false;
        }

        return OrderDocumentFile::query()
            ->where('documento_id', $documentId)
            ->where('formato', $format)
            ->exists();
    }

    /**
     * @param iterable<OrderDocument> $documents
     * @return array<int, string>
     */
    private function normalizeDocumentTypes(iterable $documents): array
    {
        return collect($documents)
            ->map(fn ($type): string => mb_strtolower(trim((string) $type)))
            ->filter(fn (string $type): bool => $type !== '' && array_key_exists($type, self::DOCUMENT_TYPES))
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeFormat(string $format): string
    {
        return mb_strtolower(trim($format)) === '80mm' ? '80mm' : 'a4';
    }

    private function normalizeChannel(string $channel): string
    {
        return mb_strtolower(trim($channel)) === 'email' ? 'email' : 'whatsapp';
    }

    private function resolveEquipmentLabel(Order $order): string
    {
        $parts = array_filter([
            trim((string) ($order->equipment?->type?->nome ?? '')),
            trim((string) ($order->equipment?->brand?->nome ?? '')),
            trim((string) ($order->equipment?->model?->nome ?? '')),
        ], static fn (string $value): bool => $value !== '');

        $summary = trim((string) ($order->equipment?->resumo_tecnico ?? ''));

        return $parts !== [] ? implode(' / ', $parts) : ($summary !== '' ? $summary : 'Equipamento não informado');
    }

    private function documentTypeLabel(string $type): string
    {
        return (string) (self::DOCUMENT_TYPES[$type]['label'] ?? ucwords(str_replace('_', ' ', $type)));
    }

    /**
     * @return array<string, mixed>
     */
    private function mapDocumentVersion(Order $order, OrderDocument $document): array
    {
        $availableFormats = [];
        foreach (['a4', '80mm'] as $format) {
            $resolved = $this->resolveDocumentFilePayload($order, $document, $format);
            $availableFormats[$format] = [
                'available' => ($resolved['result'] ?? 'error') === 'ok',
                'url' => ($resolved['result'] ?? 'error') === 'ok'
                    ? '/api/v1/orders/' . (int) $order->id . '/documents/' . (int) $document->id . '/files/' . $format
                    : null,
            ];
        }

        $templateCode = $this->resolveTemplateCodeForDocumentType((string) ($document->tipo_documento ?? ''));

        return [
            'id' => (int) $document->id,
            'type' => (string) ($document->tipo_documento ?? ''),
            'label' => $this->documentTypeLabel((string) ($document->tipo_documento ?? '')),
            'version' => (int) ($document->versao ?? 1),
            'archived_at' => optional($document->arquivado_em)->toIso8601String(),
            'created_at' => optional($document->created_at)->toIso8601String(),
            'updated_at' => optional($document->updated_at)->toIso8601String(),
            'generated_by' => [
                'id' => (int) ($document->generatedBy?->id ?? 0),
                'name' => (string) ($document->generatedBy?->nome ?? ''),
            ],
            'files' => $availableFormats,
            'template_code' => $templateCode,
            'suggested_message' => $this->buildDefaultOutboundMessage($order, collect([$document]), $templateCode),
            'legacy_file' => (string) ($document->arquivo ?? ''),
        ];
    }

    private function maskSensitiveDestination(string $value, string $channel): string
    {
        $value = trim($value);

        if ($channel === 'email') {
            [$local, $domain] = array_pad(explode('@', $value, 2), 2, '');
            $local = mb_substr($local, 0, 2) . str_repeat('*', max(1, mb_strlen($local) - 2));
            $domain = preg_replace('/(^.).*?(\.[^.]+$)/', '$1***$2', $domain) ?? '***';

            return trim($local . '@' . $domain, '@');
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return '***';
        }

        return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }

    private function sanitizeOperationalError(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return 'Falha operacional no envio documental.';
        }

        $message = preg_replace('/([A-Z]:\\\\[^\\s]+)/iu', '[path]', $message) ?? $message;
        $message = preg_replace('/https?:\/\/\S+/iu', '[url]', $message) ?? $message;

        return mb_substr($message, 0, 240);
    }

    private function safeZipEntryName(OrderDocument $document, string $format, int $index): string
    {
        $base = $this->slug((string) ($document->tipo_documento ?? 'documento'));
        $version = (int) ($document->versao ?? 1);
        $format = $this->normalizeFormat($format);

        return sprintf('%02d_%s_v%d_%s.pdf', $index, $base, $version, $format);
    }

    private function money(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars(trim((string) ($value ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function slug(string $value): string
    {
        $slug = (string) preg_replace('/[^a-z0-9]+/i', '_', $value);

        return trim(strtolower($slug), '_') ?: 'documento';
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function recordOrderEvent(
        int $orderId,
        string $category,
        string $type,
        string $title,
        string $description,
        array $metadata = [],
        ?int $userId = null
    ): void {
        if ($orderId <= 0) {
            return;
        }

        try {
            $serviceClass = \App\Services\Orders\OrderEventService::class;
            if (! app()->bound($serviceClass)) {
                return;
            }

            app($serviceClass)->record(
                $orderId,
                $category,
                $type,
                $title,
                $description,
                $metadata,
                $userId
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
