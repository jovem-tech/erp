<?php

namespace App\Services\Files\Authorizers;

use App\Contracts\Files\FileAuthorizer;
use App\Models\Chat\MessageAttachment;
use App\Models\Files\ManagedFile;
use App\Models\User;
use App\Services\Auth\RbacAuthorizationService;
use App\Services\Chat\ConversationAccessService;

class ChatAttachmentFileAuthorizer implements FileAuthorizer
{
    public function __construct(
        private readonly RbacAuthorizationService $rbac,
        private readonly ConversationAccessService $conversationAccess
    ) {}

    public function allows(User $actor, ManagedFile $file, string $ability): bool
    {
        $action = in_array($ability, ['metadata', 'download'], true) ? 'visualizar' : 'editar';
        if (! $this->rbac->allows($actor, 'atendimento_whatsapp', $action)) {
            return false;
        }

        $attachmentIds = $file->links
            ->where('subject_type', 'chat_attachment')
            ->whereNull('unlinked_at')
            ->pluck('subject_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        try {
            $attachments = MessageAttachment::query()
                ->whereKey($attachmentIds)
                ->with('message.conversation')
                ->get();

            return $attachments->contains(function (MessageAttachment $attachment) use ($actor): bool {
                $conversation = $attachment->message?->conversation;

                return $conversation !== null
                    && $this->conversationAccess->canAccessConversation($actor, $conversation);
            });
        } catch (\Throwable) {
            return false;
        }
    }
}
