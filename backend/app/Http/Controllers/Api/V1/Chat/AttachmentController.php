<?php

namespace App\Http\Controllers\Api\V1\Chat;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Chat\MessageAttachment;
use App\Models\User;
use App\Services\Chat\ConversationAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends BaseApiController
{
    public function __construct(
        private readonly ConversationAccessService $accessService
    ) {
    }

    public function show(Request $request, MessageAttachment $attachment): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        $user = $this->authenticatedUser($request);
        if (! $user instanceof User) {
            return $this->unauthenticatedResponse($request);
        }

        $this->authorize('atendimento_whatsapp:visualizar');

        $attachment->loadMissing('message.conversation');

        if (
            ! $attachment->message
            || ! $attachment->message->conversation
            || ! $this->accessService->canAccessConversation($user, $attachment->message->conversation)
        ) {
            return $this->error('Sem acesso a este anexo.', 403, 'CHAT_ATTACHMENT_FORBIDDEN', null, request: $request);
        }

        if ($attachment->storage_path === null || $attachment->disk === null) {
            return $this->error(
                'Anexo indisponível para download.',
                404,
                'CHAT_ATTACHMENT_UNAVAILABLE',
                null,
                request: $request
            );
        }

        return Storage::disk($attachment->disk)->response(
            $attachment->storage_path,
            $attachment->original_name ?? $attachment->stored_name ?? 'anexo',
            array_filter([
                'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="' . ($attachment->original_name ?? $attachment->stored_name ?? 'anexo') . '"',
            ])
        );
    }
}
