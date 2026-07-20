<?php

namespace App\Http\Controllers\Api\V1\Chat;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\Chat\Conversation;
use App\Models\User;
use App\Services\Channels\Whatsapp\WhatsappMessagingService;
use App\Services\Chat\ChatAttachmentPolicy;
use App\Services\Chat\ConversationAccessService;
use App\Services\Chat\ConversationPayloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

class MessageController extends BaseApiController
{
    public function __construct(
        private readonly ConversationAccessService $accessService,
        private readonly WhatsappMessagingService $messagingService,
        private readonly ConversationPayloadService $payloadService,
        private readonly ChatAttachmentPolicy $attachmentPolicy
    ) {}

    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        if (! $user instanceof User) {
            return $this->unauthenticatedResponse($request);
        }

        $this->authorize('atendimento_whatsapp:criar');

        if (! $this->accessService->canAccessConversation($user, $conversation)) {
            return $this->error('Sem acesso a esta conversa.', 403, 'CONVERSATION_FORBIDDEN', null, request: $request);
        }

        $validator = Validator::make($request->all(), [
            'conteudo' => ['nullable', 'string', 'max:4096'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => $this->attachmentPolicy->uploadRules(),
        ]);

        if ($validator->fails()) {
            return $this->error(
                'Mensagem inválida.',
                422,
                'MESSAGE_VALIDATION_ERROR',
                $validator->errors(),
                request: $request
            );
        }

        $attachments = $this->extractAttachments($request);
        $text = trim((string) ($validator->validated()['conteudo'] ?? ''));

        if ($text === '' && $attachments === []) {
            return $this->error(
                'Envie um texto, um anexo ou ambos.',
                422,
                'MESSAGE_EMPTY',
                null,
                request: $request
            );
        }

        $message = $this->messagingService->createOutgoingMessage(
            $conversation,
            'usuario',
            $user->id,
            $text,
            $attachments,
            ['origin' => 'chat_ui']
        );

        SendWhatsappMessageJob::dispatch($message->id);

        return $this->success([
            'message' => $this->payloadService->message($message),
        ], 201, request: $request);
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function extractAttachments(Request $request): array
    {
        $files = $request->file('attachments', []);

        if ($files instanceof UploadedFile) {
            return [$files];
        }

        if (! is_array($files)) {
            return [];
        }

        return array_values(array_filter($files, static fn ($file): bool => $file instanceof UploadedFile));
    }
}
