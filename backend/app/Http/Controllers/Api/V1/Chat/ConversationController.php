<?php

namespace App\Http\Controllers\Api\V1\Chat;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\Chat\Conversation;
use App\Models\Chat\Message;
use App\Models\Legacy\LegacyClient;
use App\Models\User;
use App\Services\Channels\Whatsapp\ContactConversationResolver;
use App\Services\Channels\Whatsapp\PhoneNumberNormalizationService;
use App\Services\Channels\Whatsapp\WhatsappMessagingService;
use App\Services\Chat\ChatAttachmentPolicy;
use App\Services\Chat\ChatClientLookupService;
use App\Services\Chat\ConversationAccessService;
use App\Services\Chat\ConversationPayloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

class ConversationController extends BaseApiController
{
    public function __construct(
        private readonly ConversationAccessService $accessService,
        private readonly ContactConversationResolver $resolver,
        private readonly PhoneNumberNormalizationService $phoneNormalizer,
        private readonly ConversationPayloadService $payloadService,
        private readonly ChatClientLookupService $clientLookup,
        private readonly WhatsappMessagingService $messagingService,
        private readonly ChatAttachmentPolicy $attachmentPolicy
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        if (! $user instanceof User) {
            return $this->unauthenticatedResponse($request);
        }

        $this->authorize('atendimento_whatsapp:visualizar');
        $accountIds = $this->accessService->accessibleAccountIds($user);

        if ($accountIds === []) {
            return $this->error(
                'Nenhuma conta de atendimento autorizada para este usuario.',
                403,
                'CHAT_ACCOUNT_CONTEXT_UNAVAILABLE',
                null,
                request: $request
            );
        }

        $status = trim((string) $request->query('status', ''));
        $search = trim((string) $request->query('search', ''));
        $unreadOnly = filter_var($request->query('unread_only', false), FILTER_VALIDATE_BOOL);

        $query = Conversation::query()
            ->whereIn('conta_id', $accountIds)
            ->with(['contact.client', 'latestMessage.attachments'])
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id');

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $matchingClientIds = $this->clientLookup->search($search, 20)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $searchTerm = '%'.mb_strtolower($search).'%';
            $query->whereHas('contact', function ($contactQuery) use ($searchTerm, $matchingClientIds): void {
                $contactQuery->where(function ($inner) use ($searchTerm, $matchingClientIds): void {
                    $inner
                        ->whereRaw('LOWER(COALESCE(nome, \'\')) LIKE ?', [$searchTerm])
                        ->orWhereRaw('LOWER(COALESCE(telefone, \'\')) LIKE ?', [$searchTerm]);

                    if ($matchingClientIds !== []) {
                        $inner->orWhereIn('cliente_id', $matchingClientIds);
                    }
                });
            });
        }

        if ($unreadOnly) {
            $query->whereExists(function ($subQuery): void {
                $subQuery->selectRaw('1')
                    ->from('mensagens')
                    ->whereColumn('mensagens.conversa_id', 'conversas.id')
                    ->where('mensagens.message_type', 'incoming')
                    ->where(function ($comparison): void {
                        $comparison->whereNull('conversas.lida_em')
                            ->orWhereColumn('mensagens.created_at', '>', 'conversas.lida_em');
                    });
            });
        }

        $perPage = max(1, min(50, (int) $request->query('per_page', 20)));
        $paginator = $query->paginate($perPage);

        $conversationIds = collect($paginator->items())->pluck('id')->all();
        $unreadByConversation = $this->unreadCountsFor($conversationIds);

        $items = collect($paginator->items())
            ->map(fn (Conversation $conversation): array => $this->payloadService->conversationSummary(
                $conversation,
                (int) ($unreadByConversation[$conversation->id] ?? 0)
            ))
            ->all();

        return $this->success(
            ['items' => $items],
            meta: $this->paginationMeta($paginator),
            request: $request
        );
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        if (! $user instanceof User) {
            return $this->unauthenticatedResponse($request);
        }

        $this->authorize('atendimento_whatsapp:criar');
        $accountId = $this->accessService->defaultAccountIdForUser($user);

        if ($accountId === null) {
            return $this->error(
                'Nenhuma conta padrao de atendimento autorizada para este usuario.',
                403,
                'CHAT_ACCOUNT_CONTEXT_UNAVAILABLE',
                null,
                request: $request
            );
        }

        $validator = Validator::make($request->all(), [
            'client_id' => ['nullable', 'integer', 'min:1'],
            'telefone' => ['nullable', 'string', 'max:32'],
            'nome' => ['nullable', 'string', 'max:120'],
            'mensagem' => ['nullable', 'string', 'max:4096'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => $this->attachmentPolicy->uploadRules(),
        ]);

        if ($validator->fails()) {
            return $this->error(
                'Dados inválidos para iniciar a conversa.',
                422,
                'CONVERSATION_VALIDATION_ERROR',
                $validator->errors(),
                request: $request
            );
        }

        $data = $validator->validated();
        $attachments = $this->extractAttachments($request);
        $initialText = trim((string) ($data['mensagem'] ?? ''));
        $hasInitialContent = $initialText !== '' || $attachments !== [];

        try {
            [$conversation, $resolvedName] = $this->resolveConversationTarget($data, $accountId);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'CONVERSATION_TARGET_INVALID', null, request: $request);
        }

        if ($hasInitialContent) {
            $message = $this->messagingService->createOutgoingMessage(
                $conversation,
                'usuario',
                $user->id,
                $initialText,
                $attachments,
                ['origin' => 'chat_ui', 'resolved_name' => $resolvedName]
            );

            SendWhatsappMessageJob::dispatch($message->id);
        }

        $freshConversation = Conversation::query()
            ->with(['contact.client', 'latestMessage.attachments', 'messages.attachments'])
            ->findOrFail($conversation->id);

        return $this->success([
            'conversation' => $this->payloadService->conversationDetail($freshConversation, 0),
        ], 201, request: $request);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        if (! $user instanceof User) {
            return $this->unauthenticatedResponse($request);
        }

        $this->authorize('atendimento_whatsapp:visualizar');

        if (! $this->accessService->canAccessConversation($user, $conversation)) {
            return $this->error('Sem acesso a esta conversa.', 403, 'CONVERSATION_FORBIDDEN', null, request: $request);
        }

        $conversation->load(['contact.client', 'latestMessage.attachments', 'messages.attachments']);
        $conversation->forceFill(['lida_em' => now()])->save();

        return $this->success([
            'conversation' => $this->payloadService->conversationDetail($conversation, 0),
        ], request: $request);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Conversation, 1: string|null}
     */
    private function resolveConversationTarget(array $data, int $accountId): array
    {
        $clientId = (int) ($data['client_id'] ?? 0);
        $nome = trim((string) ($data['nome'] ?? ''));
        $rawPhone = trim((string) ($data['telefone'] ?? ''));

        if ($clientId > 0) {
            $client = $this->clientLookup->findById($clientId);
            if (! $client instanceof LegacyClient) {
                throw new \RuntimeException('Cliente não encontrado no sistema_hml.');
            }

            $resolvedPhone = $rawPhone !== '' ? $rawPhone : $this->clientLookup->preferredPhoneFor($client);
            if ($resolvedPhone === null || trim($resolvedPhone) === '') {
                throw new \RuntimeException('O cliente selecionado não possui telefone disponível para WhatsApp.');
            }

            $normalizedPhone = $this->phoneNormalizer->normalize($resolvedPhone);
            if (! $this->isValidPhone($normalizedPhone)) {
                throw new \RuntimeException('Telefone inválido para iniciar a conversa.');
            }

            $resolvedName = trim((string) ($client->nome_razao ?? ''));

            return [
                $this->resolver->resolve($normalizedPhone, $resolvedName !== '' ? $resolvedName : $nome, $client->id, $accountId),
                $resolvedName !== '' ? $resolvedName : ($nome !== '' ? $nome : null),
            ];
        }

        $normalizedPhone = $this->phoneNormalizer->normalize($rawPhone);
        if (! $this->isValidPhone($normalizedPhone)) {
            throw new \RuntimeException('Telefone inválido. Use o formato com DDD, ex.: 11912345678.');
        }

        return [
            $this->resolver->resolve($normalizedPhone, $nome !== '' ? $nome : null, null, $accountId),
            $nome !== '' ? $nome : null,
        ];
    }

    private function isValidPhone(string $phone): bool
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) >= 12 && strlen($digits) <= 13;
    }

    /**
     * @param  array<int, int>  $conversationIds
     * @return array<int, int>
     */
    private function unreadCountsFor(array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        return Message::query()
            ->join('conversas', 'conversas.id', '=', 'mensagens.conversa_id')
            ->where('mensagens.message_type', 'incoming')
            ->whereIn('mensagens.conversa_id', $conversationIds)
            ->where(function ($query): void {
                $query->whereNull('conversas.lida_em')
                    ->orWhereColumn('mensagens.created_at', '>', 'conversas.lida_em');
            })
            ->groupBy('mensagens.conversa_id')
            ->selectRaw('mensagens.conversa_id as conversa_id, COUNT(*) as total')
            ->pluck('total', 'conversa_id')
            ->all();
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function extractAttachments(Request $request): array
    {
        $files = $request->file('attachments', $request->file('anexos', []));

        if ($files instanceof UploadedFile) {
            return [$files];
        }

        if (! is_array($files)) {
            return [];
        }

        return array_values(array_filter($files, static fn ($file): bool => $file instanceof UploadedFile));
    }
}
