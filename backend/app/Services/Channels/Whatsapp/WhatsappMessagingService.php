<?php

namespace App\Services\Channels\Whatsapp;

use App\Events\MessageCreated;
use App\Events\MessageUpdated;
use App\Models\Chat\Conversation;
use App\Models\Chat\Message;
use App\Services\Chat\ConversationPayloadService;
use App\Services\Chat\MessageAttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class WhatsappMessagingService
{
    public function __construct(
        private readonly ContactConversationResolver $resolver,
        private readonly MessageAttachmentService $attachmentService,
        private readonly WhatsappChannelDriver $driver,
        private readonly ConversationPayloadService $payloadService,
        private readonly PhoneNumberNormalizationService $phoneNormalizer
    ) {
    }

    /**
     * @param array<int, UploadedFile> $attachments
     */
    public function createOutgoingMessage(
        Conversation $conversation,
        string $senderType,
        ?int $senderId,
        ?string $text = null,
        array $attachments = [],
        array $contentAttributes = []
    ): Message {
        $trimmedText = trim((string) $text);

        $message = DB::connection('chat')->transaction(function () use (
            $conversation,
            $senderType,
            $senderId,
            $trimmedText,
            $attachments,
            $contentAttributes
        ) {
            $message = Message::query()->create([
                'conta_id' => $conversation->conta_id,
                'conversa_id' => $conversation->id,
                'caixa_entrada_id' => $conversation->caixa_entrada_id,
                'message_type' => 'outgoing',
                'sender_type' => $senderType,
                'sender_id' => $senderId,
                'conteudo' => $trimmedText !== '' ? $trimmedText : null,
                'content_type' => $trimmedText !== '' ? Message::CONTENT_TYPE_TEXT : Message::CONTENT_TYPE_UNKNOWN,
                'content_attributes' => $contentAttributes !== [] ? $contentAttributes : null,
                'status' => 'pending',
            ]);

            $this->attachmentService->storeUploadedAttachments($message, $attachments);

            $conversation->forceFill(['last_activity_at' => now()])->save();

            return $message->fresh(['attachments']) ?? $message;
        });

        broadcast(new MessageCreated($message));

        return $message;
    }

    /**
     * @param array<int, UploadedFile> $attachments
     * @return array<string, mixed>
     */
    public function sendSystemMessage(
        string $phone,
        ?string $text = null,
        array $attachments = [],
        ?string $contactName = null,
        ?int $clientId = null,
        array $contentAttributes = []
    ): array {
        $normalizedPhone = $this->phoneNormalizer->normalize($phone);
        if ($normalizedPhone === '') {
            return [
                'ok' => false,
                'status_code' => 422,
                'message' => 'Telefone inválido para envio do WhatsApp.',
            ];
        }

        $conversation = $this->resolver->resolve(
            $normalizedPhone,
            $contactName,
            $clientId
        );

        $message = $this->createOutgoingMessage(
            $conversation,
            'system',
            null,
            $text,
            $attachments,
            $contentAttributes
        );

        $result = $this->sendPendingMessage($message);

        return array_merge($result, [
            'message_payload' => $this->payloadService->message($message->fresh(['attachments']) ?? $message),
            'conversation_id' => $conversation->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendPendingMessage(Message $message): array
    {
        $message->loadMissing(['conversation.contactInbox', 'attachments']);

        $result = $this->driver->sendMessage($message->conversation, $message);
        $response = is_array($result['response'] ?? null) ? $result['response'] : [];
        $providerMessageIds = is_array($result['provider_message_ids'] ?? null)
            ? array_values(array_filter($result['provider_message_ids']))
            : [];

        $attributes = is_array($message->content_attributes) ? $message->content_attributes : [];
        $attributes['provider_response'] = $response;

        if (($result['provider'] ?? null) !== null) {
            $attributes['provider'] = $result['provider'];
        }

        if ($providerMessageIds !== []) {
            $attributes['provider_message_ids'] = $providerMessageIds;
        }

        $message->forceFill([
            'status' => ($result['ok'] ?? false) ? 'sent' : 'failed',
            'source_id' => $providerMessageIds[0] ?? $message->source_id,
            'content_attributes' => $attributes,
        ])->save();

        $updated = $message->fresh(['attachments']) ?? $message;

        broadcast(new MessageUpdated($updated));

        return $result;
    }
}
