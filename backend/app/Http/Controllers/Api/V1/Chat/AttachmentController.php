<?php

namespace App\Http\Controllers\Api\V1\Chat;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Chat\MessageAttachment;
use App\Models\User;
use App\Services\Chat\ChatAttachmentPolicy;
use App\Services\Chat\ConversationAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends BaseApiController
{
    public function __construct(
        private readonly ConversationAccessService $accessService,
        private readonly ChatAttachmentPolicy $attachmentPolicy
    ) {}

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

        if (
            $attachment->storage_path === null
            || ! $this->attachmentPolicy->isAllowedDisk($attachment->disk)
        ) {
            return $this->error(
                'Anexo indisponível para download.',
                404,
                'CHAT_ATTACHMENT_UNAVAILABLE',
                null,
                request: $request
            );
        }

        try {
            $disk = Storage::disk($attachment->disk);
            if (! $disk->exists($attachment->storage_path)) {
                throw new \RuntimeException('Arquivo inexistente.');
            }

            $actualMimeType = (string) ($disk->mimeType($attachment->storage_path) ?: '');
            $storedName = $attachment->stored_name ?? basename($attachment->storage_path);
            $inspection = $this->attachmentPolicy->inspectStoredFile($actualMimeType, $storedName);
            $safeName = $this->attachmentPolicy->safeDownloadName(
                $attachment->original_name ?? $storedName,
                $inspection['extension'] !== '' ? $inspection['extension'] : 'bin'
            );
            $inline = $inspection['allowed']
                && $this->attachmentPolicy->shouldRenderInline($actualMimeType, $storedName);

            return $disk->response(
                $attachment->storage_path,
                $safeName,
                [
                    'Content-Type' => $inspection['allowed']
                        ? $inspection['mime_type']
                        : 'application/octet-stream',
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'private, no-store, max-age=0',
                    'Pragma' => 'no-cache',
                    'Content-Security-Policy' => "default-src 'none'; base-uri 'none'; sandbox",
                ],
                $inline ? 'inline' : 'attachment'
            );
        } catch (\Throwable $exception) {
            report($exception);

            return $this->error(
                'Anexo indisponivel para download.',
                404,
                'CHAT_ATTACHMENT_UNAVAILABLE',
                null,
                request: $request
            );
        }
    }
}
