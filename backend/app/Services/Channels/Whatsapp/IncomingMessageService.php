<?php

namespace App\Services\Channels\Whatsapp;

use App\Events\MessageCreated;
use App\Models\Chat\Message;
use App\Services\Chat\MessageAttachmentService;
use Illuminate\Support\Facades\DB;

class IncomingMessageService
{
    public function __construct(
        private readonly PhoneNumberNormalizationService $phoneNormalizer,
        private readonly ContactConversationResolver $resolver,
        private readonly MessageAttachmentService $attachmentService
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): ?Message
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $key = is_array($data['key'] ?? null) ? $data['key'] : [];

        $remoteJid = trim((string) ($key['remoteJid'] ?? $data['remoteJid'] ?? ''));
        $fromMe = $this->toBoolean($key['fromMe'] ?? $data['fromMe'] ?? false);
        $messageId = trim((string) ($key['id'] ?? $data['id'] ?? ''));

        if ($remoteJid === '' || $fromMe || $this->phoneNormalizer->isGroupOrBroadcastJid($remoteJid)) {
            return null;
        }

        $messagePayload = is_array($data['message'] ?? null) ? $data['message'] : [];
        $text = $this->extractText($messagePayload);
        $attachmentDescriptor = $this->extractAttachment($messagePayload, $data);

        if ($text === '' && $attachmentDescriptor === null) {
            return null;
        }

        $phone = $this->phoneNormalizer->normalize($remoteJid);
        if ($phone === '') {
            return null;
        }

        if ($messageId !== '' && Message::query()->where('source_id', $messageId)->exists()) {
            return null;
        }

        $pushName = trim((string) ($data['pushName'] ?? ''));
        $attachmentBytes = $attachmentDescriptor !== null
            ? $this->attachmentService->resolveInboundBytesFromDescriptor($attachmentDescriptor)
            : null;

        $message = DB::connection('chat')->transaction(function () use (
            $phone,
            $pushName,
            $messageId,
            $text,
            $attachmentDescriptor,
            $attachmentBytes
        ) {
            $conversation = $this->resolver->resolve($phone, $pushName !== '' ? $pushName : null);

            $message = Message::create([
                'conta_id' => $conversation->conta_id,
                'conversa_id' => $conversation->id,
                'caixa_entrada_id' => $conversation->caixa_entrada_id,
                'message_type' => 'incoming',
                'sender_type' => 'contato',
                'sender_id' => $conversation->contato_id,
                'conteudo' => $text !== '' ? $text : null,
                'content_type' => $text !== '' ? Message::CONTENT_TYPE_TEXT : Message::CONTENT_TYPE_UNKNOWN,
                'content_attributes' => $attachmentDescriptor !== null ? [
                    'provider_media' => [
                        'attachment_type' => $attachmentDescriptor['attachment_type'] ?? null,
                        'mime_type' => $attachmentDescriptor['mime_type'] ?? null,
                        'provider_url' => $attachmentDescriptor['provider_url'] ?? null,
                    ],
                ] : null,
                'source_id' => $messageId !== '' ? $messageId : null,
                'status' => 'sent',
            ]);

            if ($attachmentDescriptor !== null) {
                $this->attachmentService->storeInboundAttachment($message, $attachmentDescriptor, $attachmentBytes, true);
                $message = $message->fresh(['attachments']) ?? $message;
            }

            $conversation->forceFill(['last_activity_at' => now()])->save();

            return $message;
        });

        broadcast(new MessageCreated($message));

        return $message;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function extractText(array $message): string
    {
        $candidates = [
            $message['conversation'] ?? null,
            $message['extendedTextMessage']['text'] ?? null,
            $message['imageMessage']['caption'] ?? null,
            $message['videoMessage']['caption'] ?? null,
            $message['documentMessage']['caption'] ?? null,
            $message['buttonsResponseMessage']['selectedDisplayText'] ?? null,
            $message['listResponseMessage']['title'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function extractAttachment(array $message, array $data): ?array
    {
        $candidates = [
            ['key' => 'imageMessage', 'type' => 'image'],
            ['key' => 'audioMessage', 'type' => 'audio'],
            ['key' => 'videoMessage', 'type' => 'video'],
            ['key' => 'documentMessage', 'type' => 'document'],
        ];

        foreach ($candidates as $candidate) {
            $media = is_array($message[$candidate['key']] ?? null) ? $message[$candidate['key']] : null;
            if (! is_array($media)) {
                continue;
            }

            return [
                'attachment_type' => $candidate['type'],
                'mime_type' => $this->firstString([$media['mimetype'] ?? null, data_get($data, 'media.mimetype')]),
                'original_name' => $this->firstString([$media['fileName'] ?? null, data_get($data, 'media.fileName')]),
                'provider_url' => $this->firstString([
                    $media['mediaUrl'] ?? null,
                    $media['url'] ?? null,
                    data_get($data, 'media.url'),
                ]),
                'base64' => $this->firstString([
                    $media['base64'] ?? null,
                    $media['fileBase64'] ?? null,
                    data_get($data, 'media.base64'),
                ]),
                'byte_size' => is_numeric($media['fileLength'] ?? null) ? (int) $media['fileLength'] : null,
                'metadata' => [
                    'message_key' => $candidate['key'],
                    'direct_path' => $media['directPath'] ?? null,
                    'caption' => $media['caption'] ?? null,
                ],
            ];
        }

        return null;
    }

    /**
     * @param array<int, mixed> $values
     */
    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }

        if (is_string($value)) {
            $normalized = mb_strtolower(trim($value));

            if ($normalized === '') {
                return false;
            }

            $filtered = filter_var($normalized, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if (is_bool($filtered)) {
                return $filtered;
            }

            return in_array($normalized, ['1', 'true', 'yes', 'sim', 'on'], true);
        }

        return (bool) $value;
    }
}
