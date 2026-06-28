<?php

namespace App\Services\Chat;

use App\Models\Chat\Message;
use App\Models\Chat\MessageAttachment;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MessageAttachmentService
{
    /**
     * @param array<int, UploadedFile> $files
     */
    public function storeUploadedAttachments(Message $message, array $files): void
    {
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $extension = $file->guessExtension() ?: $file->extension() ?: 'bin';
            $storedName = Str::uuid()->toString() . '.' . $extension;
            $storagePath = $file->storeAs(
                $this->directoryFor($message),
                $storedName,
                'local'
            );

            MessageAttachment::query()->create([
                'mensagem_id' => $message->id,
                'attachment_type' => $this->detectType(
                    (string) $file->getMimeType(),
                    (string) $file->getClientOriginalExtension()
                ),
                'transfer_status' => MessageAttachment::TRANSFER_AVAILABLE,
                'disk' => 'local',
                'storage_path' => $storagePath,
                'original_name' => $file->getClientOriginalName(),
                'stored_name' => $storedName,
                'mime_type' => $file->getMimeType(),
                'byte_size' => $file->getSize(),
                'metadata' => [
                    'source' => 'upload',
                ],
            ]);
        }

        $this->refreshMessageContentType($message);
    }

    /**
     * @param array<string, mixed> $descriptor
     */
    public function storeInboundAttachment(
        Message $message,
        array $descriptor,
        ?string $bytes = null,
        bool $bytesAlreadyResolved = false
    ): MessageAttachment
    {
        $attachmentType = $this->normalizeAttachmentType((string) ($descriptor['attachment_type'] ?? ''));
        $mimeType = trim((string) ($descriptor['mime_type'] ?? ''));
        $originalName = trim((string) ($descriptor['original_name'] ?? ''));
        $providerUrl = trim((string) ($descriptor['provider_url'] ?? ''));
        $base64 = trim((string) ($descriptor['base64'] ?? ''));
        $metadata = is_array($descriptor['metadata'] ?? null) ? $descriptor['metadata'] : [];

        $attachment = MessageAttachment::query()->create([
            'mensagem_id' => $message->id,
            'attachment_type' => $attachmentType,
            'transfer_status' => MessageAttachment::TRANSFER_FAILED,
            'original_name' => $originalName !== '' ? $originalName : $this->fallbackFileName($attachmentType, $mimeType),
            'mime_type' => $mimeType !== '' ? $mimeType : null,
            'byte_size' => Arr::get($descriptor, 'byte_size'),
            'provider_url' => $providerUrl !== '' ? $providerUrl : null,
            'metadata' => $metadata,
        ]);

        if (! $bytesAlreadyResolved && $bytes === null) {
            $bytes = $this->resolveInboundBytes($base64, $providerUrl);
        }
        if ($bytes !== null) {
            $storedName = Str::uuid()->toString() . '.' . $this->extensionFor($mimeType, $originalName);
            $storagePath = $this->directoryFor($message) . '/' . $storedName;
            Storage::disk('local')->put($storagePath, $bytes);

            $attachment->forceFill([
                'transfer_status' => MessageAttachment::TRANSFER_AVAILABLE,
                'disk' => 'local',
                'storage_path' => $storagePath,
                'stored_name' => $storedName,
                'byte_size' => strlen($bytes),
                'metadata' => array_merge($metadata, ['downloaded' => true]),
            ])->save();
        } else {
            $attachment->forceFill([
                'metadata' => array_merge($metadata, ['downloaded' => false]),
            ])->save();
        }

        $this->refreshMessageContentType($message);

        return $attachment->fresh() ?? $attachment;
    }

    public function refreshMessageContentType(Message $message): void
    {
        $message->loadMissing('attachments');

        $attachments = $message->attachments;
        $hasText = trim((string) ($message->conteudo ?? '')) !== '';

        $contentType = match (true) {
            $attachments->count() > 1 && $hasText => Message::CONTENT_TYPE_MIXED,
            $attachments->count() > 1 => Message::CONTENT_TYPE_MIXED,
            $attachments->count() === 1 && $hasText => Message::CONTENT_TYPE_MIXED,
            $attachments->count() === 1 => $this->mapAttachmentTypeToContentType(
                (string) ($attachments->first()?->attachment_type ?? '')
            ),
            $hasText => Message::CONTENT_TYPE_TEXT,
            default => Message::CONTENT_TYPE_UNKNOWN,
        };

        if ($message->content_type !== $contentType) {
            $message->forceFill(['content_type' => $contentType])->save();
        }
    }

    public function detectType(string $mimeType, string $extension = ''): string
    {
        $mime = mb_strtolower(trim($mimeType));
        $extension = mb_strtolower(trim(ltrim($extension, '.')));

        if (str_starts_with($mime, 'image/')) {
            return MessageAttachment::TYPE_IMAGE;
        }

        if (str_starts_with($mime, 'audio/')) {
            return MessageAttachment::TYPE_AUDIO;
        }

        if (str_starts_with($mime, 'video/')) {
            return MessageAttachment::TYPE_VIDEO;
        }

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            return MessageAttachment::TYPE_IMAGE;
        }

        if (in_array($extension, ['mp3', 'ogg', 'wav', 'm4a', 'opus'], true)) {
            return MessageAttachment::TYPE_AUDIO;
        }

        if (in_array($extension, ['mp4', 'mov', 'avi', 'mkv', 'webm'], true)) {
            return MessageAttachment::TYPE_VIDEO;
        }

        return MessageAttachment::TYPE_DOCUMENT;
    }

    private function mapAttachmentTypeToContentType(string $attachmentType): string
    {
        return match ($attachmentType) {
            MessageAttachment::TYPE_IMAGE => Message::CONTENT_TYPE_IMAGE,
            MessageAttachment::TYPE_AUDIO => Message::CONTENT_TYPE_AUDIO,
            MessageAttachment::TYPE_VIDEO => Message::CONTENT_TYPE_VIDEO,
            MessageAttachment::TYPE_DOCUMENT => Message::CONTENT_TYPE_DOCUMENT,
            default => Message::CONTENT_TYPE_UNKNOWN,
        };
    }

    private function normalizeAttachmentType(string $attachmentType): string
    {
        $attachmentType = trim($attachmentType);

        return in_array($attachmentType, [
            MessageAttachment::TYPE_IMAGE,
            MessageAttachment::TYPE_AUDIO,
            MessageAttachment::TYPE_VIDEO,
            MessageAttachment::TYPE_DOCUMENT,
        ], true) ? $attachmentType : MessageAttachment::TYPE_UNKNOWN;
    }

    private function directoryFor(Message $message): string
    {
        return 'chat-media/' . now()->format('Y/m/d') . '/conversa-' . $message->conversa_id;
    }

    private function extensionFor(string $mimeType, string $originalName): string
    {
        $mimeExtension = match (mb_strtolower(trim($mimeType))) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'video/mp4' => 'mp4',
            'application/pdf' => 'pdf',
            default => '',
        };

        if ($mimeExtension !== '') {
            return $mimeExtension;
        }

        $pathInfo = pathinfo($originalName);
        $extension = mb_strtolower(trim((string) ($pathInfo['extension'] ?? '')));

        return $extension !== '' ? $extension : 'bin';
    }

    private function fallbackFileName(string $attachmentType, string $mimeType): string
    {
        return match ($attachmentType) {
            MessageAttachment::TYPE_IMAGE => 'imagem.' . $this->extensionFor($mimeType, 'imagem'),
            MessageAttachment::TYPE_AUDIO => 'audio.' . $this->extensionFor($mimeType, 'audio'),
            MessageAttachment::TYPE_VIDEO => 'video.' . $this->extensionFor($mimeType, 'video'),
            default => 'arquivo.' . $this->extensionFor($mimeType, 'arquivo'),
        };
    }

    public function resolveInboundBytesFromDescriptor(array $descriptor): ?string
    {
        return $this->resolveInboundBytes(
            trim((string) ($descriptor['base64'] ?? '')),
            trim((string) ($descriptor['provider_url'] ?? ''))
        );
    }

    private function resolveInboundBytes(string $base64, string $providerUrl): ?string
    {
        if ($base64 !== '') {
            $decoded = $this->decodeBase64Payload($base64);
            if ($decoded !== null && $decoded !== '') {
                return $decoded;
            }
        }

        if ($providerUrl === '') {
            return null;
        }

        try {
            $response = Http::withoutVerifying()
                ->timeout(20)
                ->get($providerUrl);

            return $this->responseToBytes($response);
        } catch (\Throwable $exception) {
            logger()->warning('[CHAT][ANEXO] Falha ao baixar mídia inbound', [
                'url' => $providerUrl,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function responseToBytes(Response $response): ?string
    {
        if (! $response->successful()) {
            return null;
        }

        $bytes = $response->body();

        return $bytes !== '' ? $bytes : null;
    }

    private function decodeBase64Payload(string $payload): ?string
    {
        $normalized = trim($payload);
        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, ',')) {
            [, $normalized] = explode(',', $normalized, 2);
        }

        $decoded = base64_decode($normalized, true);

        return $decoded === false ? null : $decoded;
    }
}
