<?php

namespace App\Services\Chat;

use App\Enums\Files\FileOrigin;
use App\Models\Chat\Message;
use App\Models\Chat\MessageAttachment;
use App\Services\Files\ChatFileManagerAdapter;
use App\Services\Integrations\IntegrationSettingsService;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MessageAttachmentService
{
    public function __construct(
        private readonly IntegrationSettingsService $integrationSettingsService,
        private readonly ChatAttachmentPolicy $attachmentPolicy,
        private readonly ChatFileManagerAdapter $fileManagerAdapter
    ) {}

    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function storeUploadedAttachments(Message $message, array $files): void
    {
        $storedPaths = [];
        $createdAttachmentIds = [];

        try {
            foreach ($files as $file) {
                if (! $file instanceof UploadedFile || ! $file->isValid()) {
                    throw ValidationException::withMessages([
                        'attachments' => ['O anexo enviado e invalido.'],
                    ]);
                }

                $inspection = $this->attachmentPolicy->inspectUploadedFile($file);
                if (! $inspection['allowed']) {
                    throw ValidationException::withMessages([
                        'attachments' => ['O tipo de arquivo enviado nao e permitido.'],
                    ]);
                }

                $storedName = Str::uuid()->toString().'.'.$inspection['extension'];
                $storagePath = $file->storeAs(
                    $this->directoryFor($message),
                    $storedName,
                    'local'
                );

                if (! is_string($storagePath) || $storagePath === '') {
                    throw new \RuntimeException('Falha ao persistir o anexo do chat.');
                }

                $storedPaths[] = $storagePath;

                $attachment = MessageAttachment::query()->create([
                    'mensagem_id' => $message->id,
                    'attachment_type' => $this->detectType(
                        $inspection['mime_type'],
                        $inspection['extension']
                    ),
                    'transfer_status' => MessageAttachment::TRANSFER_AVAILABLE,
                    'disk' => 'local',
                    'storage_path' => $storagePath,
                    'original_name' => $this->attachmentPolicy->safeDownloadName(
                        $file->getClientOriginalName(),
                        $inspection['extension']
                    ),
                    'stored_name' => $storedName,
                    'mime_type' => $inspection['mime_type'],
                    'byte_size' => $file->getSize() ?: null,
                    'metadata' => [
                        'source' => 'upload',
                    ],
                ]);
                $createdAttachmentIds[] = (int) $attachment->id;
                $this->fileManagerAdapter->synchronize($attachment, FileOrigin::Upload);
            }

            $this->refreshMessageContentType($message);
        } catch (\Throwable $exception) {
            if ($createdAttachmentIds !== []) {
                try {
                    MessageAttachment::query()->whereKey($createdAttachmentIds)->delete();
                } catch (\Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            foreach ($storedPaths as $storedPath) {
                try {
                    Storage::disk('local')->delete($storedPath);
                } catch (\Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $descriptor
     */
    public function storeInboundAttachment(
        Message $message,
        array $descriptor,
        ?string $bytes = null,
        bool $bytesAlreadyResolved = false
    ): MessageAttachment {
        $attachmentType = $this->normalizeAttachmentType((string) ($descriptor['attachment_type'] ?? ''));
        $mimeType = trim((string) ($descriptor['mime_type'] ?? ''));
        $originalName = trim((string) ($descriptor['original_name'] ?? ''));
        $providerUrl = trim((string) ($descriptor['provider_url'] ?? ''));
        $base64 = trim((string) ($descriptor['base64'] ?? ''));
        $metadata = is_array($descriptor['metadata'] ?? null) ? $descriptor['metadata'] : [];

        $fallbackExtension = $this->attachmentPolicy->preferredExtension($mimeType);
        $safeOriginalName = $this->attachmentPolicy->safeDownloadName(
            $originalName !== '' ? $originalName : $this->fallbackFileName($attachmentType, $mimeType),
            $fallbackExtension
        );

        $attachment = MessageAttachment::query()->create([
            'mensagem_id' => $message->id,
            'attachment_type' => $attachmentType,
            'transfer_status' => MessageAttachment::TRANSFER_FAILED,
            'original_name' => $safeOriginalName,
            'mime_type' => $mimeType !== '' ? $mimeType : null,
            'byte_size' => Arr::get($descriptor, 'byte_size'),
            'provider_url' => $providerUrl !== '' ? $providerUrl : null,
            'metadata' => $metadata,
        ]);

        if (! $bytesAlreadyResolved && $bytes === null) {
            $bytes = $this->resolveInboundBytes($base64, $providerUrl);
        }

        if ($bytes !== null) {
            $inspection = $this->attachmentPolicy->inspectInboundBytes($bytes, $mimeType, $safeOriginalName);

            if (! $inspection['allowed']) {
                logger()->warning('[CHAT][ANEXO] Midia inbound bloqueada pela politica de tipos', [
                    'attachment_id' => $attachment->id,
                    'detected_mime_type' => $inspection['mime_type'],
                    'extension' => $inspection['extension'],
                    'reason' => $inspection['reason'],
                ]);

                $attachment->forceFill([
                    'metadata' => array_merge($metadata, [
                        'downloaded' => false,
                        'rejected_by_policy' => true,
                        'rejection_reason' => $inspection['reason'],
                    ]),
                ])->save();

                $this->refreshMessageContentType($message);

                return $attachment->fresh() ?? $attachment;
            }

            $storedName = Str::uuid()->toString().'.'.$inspection['extension'];
            $storagePath = $this->directoryFor($message).'/'.$storedName;

            try {
                $stored = Storage::disk('local')->put($storagePath, $bytes);
            } catch (\Throwable $exception) {
                report($exception);
                $stored = false;
            }

            if (! $stored) {
                $attachment->forceFill([
                    'metadata' => array_merge($metadata, [
                        'downloaded' => false,
                        'storage_write_failed' => true,
                    ]),
                ])->save();

                $this->refreshMessageContentType($message);

                return $attachment->fresh() ?? $attachment;
            }

            try {
                $attachment->forceFill([
                    'attachment_type' => $this->detectType($inspection['mime_type'], $inspection['extension']),
                    'transfer_status' => MessageAttachment::TRANSFER_AVAILABLE,
                    'disk' => 'local',
                    'storage_path' => $storagePath,
                    'stored_name' => $storedName,
                    'mime_type' => $inspection['mime_type'],
                    'byte_size' => strlen($bytes),
                    'metadata' => array_merge($metadata, ['downloaded' => true]),
                ])->save();
                $this->fileManagerAdapter->synchronize($attachment, FileOrigin::Integration);
            } catch (\Throwable $exception) {
                Storage::disk('local')->delete($storagePath);
                $failureAttributes = [
                    'transfer_status' => MessageAttachment::TRANSFER_FAILED,
                    'disk' => null,
                    'storage_path' => null,
                    'stored_name' => null,
                    'metadata' => array_merge((array) $attachment->metadata, [
                        'downloaded' => false,
                        'file_manager_state' => 'failed',
                    ]),
                ];
                if (Schema::connection('chat')->hasColumn($attachment->getTable(), 'managed_file_uuid')) {
                    $failureAttributes['managed_file_uuid'] = null;
                }
                $attachment->forceFill($failureAttributes)->saveQuietly();

                throw $exception;
            }
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
        return 'chat-media/'.now()->format('Y/m/d').'/conversa-'.$message->conversa_id;
    }

    private function fallbackFileName(string $attachmentType, string $mimeType): string
    {
        $extension = $this->attachmentPolicy->preferredExtension($mimeType);

        return match ($attachmentType) {
            MessageAttachment::TYPE_IMAGE => 'imagem.'.$extension,
            MessageAttachment::TYPE_AUDIO => 'audio.'.$extension,
            MessageAttachment::TYPE_VIDEO => 'video.'.$extension,
            default => 'arquivo.'.$extension,
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

        if (! $this->isTrustedProviderUrl($providerUrl)) {
            logger()->warning('[CHAT][ANEXO] URL inbound bloqueada por origem nao confiavel', [
                'origin_hash' => $this->originHash($providerUrl),
            ]);

            return null;
        }

        if (! $this->allowsLiteralAddress($providerUrl)) {
            logger()->warning('[CHAT][ANEXO] URL inbound bloqueada por endereco privado nao autorizado', [
                'origin_hash' => $this->originHash($providerUrl),
            ]);

            return null;
        }

        try {
            $primaryIp = null;
            $response = Http::timeout(20)
                ->connectTimeout(10)
                ->withOptions([
                    'allow_redirects' => false,
                    'on_stats' => static function (TransferStats $stats) use (&$primaryIp): void {
                        $candidate = $stats->getHandlerStats()['primary_ip'] ?? null;
                        $primaryIp = is_string($candidate) ? $candidate : null;
                    },
                ])
                ->get($providerUrl);

            if ($response->status() >= 300 && $response->status() < 400) {
                logger()->warning('[CHAT][ANEXO] Redirect de midia inbound bloqueado.', [
                    'origin_hash' => $this->originHash($providerUrl),
                    'status' => $response->status(),
                ]);

                return null;
            }

            if (! $this->allowsConnectedAddress($providerUrl, $primaryIp)) {
                logger()->warning('[CHAT][ANEXO] IP conectado para midia inbound foi bloqueado.', [
                    'origin_hash' => $this->originHash($providerUrl),
                ]);

                return null;
            }

            $contentLength = (int) $response->header('Content-Length', 0);
            if ($contentLength > 0 && ! $this->withinInboundSizeLimit($contentLength)) {
                logger()->warning('[CHAT][ANEXO] Download inbound bloqueado por tamanho excedido', [
                    'origin_hash' => $this->originHash($providerUrl),
                    'content_length' => $contentLength,
                    'max_bytes' => $this->maxInboundBytes(),
                ]);

                return null;
            }

            return $this->responseToBytes($response);
        } catch (\Throwable $exception) {
            logger()->warning('[CHAT][ANEXO] Falha ao baixar midia inbound', [
                'origin_hash' => $this->originHash($providerUrl),
                'error_type' => $exception::class,
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

        if (! $this->withinInboundSizeLimit(strlen($bytes))) {
            logger()->warning('[CHAT][ANEXO] Corpo inbound descartado por tamanho excedido', [
                'byte_size' => strlen($bytes),
                'max_bytes' => $this->maxInboundBytes(),
            ]);

            return null;
        }

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

        $estimatedBytes = (int) ceil((strlen($normalized) * 3) / 4);
        if (! $this->withinInboundSizeLimit($estimatedBytes)) {
            logger()->warning('[CHAT][ANEXO] Payload base64 inbound descartado por tamanho excedido', [
                'estimated_bytes' => $estimatedBytes,
                'max_bytes' => $this->maxInboundBytes(),
            ]);

            return null;
        }

        $decoded = base64_decode($normalized, true);
        if ($decoded === false) {
            return null;
        }

        return $this->withinInboundSizeLimit(strlen($decoded)) ? $decoded : null;
    }

    private function maxInboundBytes(): int
    {
        return max(1024 * 1024, (int) config('chat.inbound_attachment_max_bytes', 25 * 1024 * 1024));
    }

    private function withinInboundSizeLimit(int $byteSize): bool
    {
        return $byteSize > 0 && $byteSize <= $this->maxInboundBytes();
    }

    private function isTrustedProviderUrl(string $providerUrl): bool
    {
        $origin = $this->normalizeOrigin($providerUrl);
        if ($origin === null) {
            return false;
        }

        return in_array($origin, $this->integrationSettingsService->trustedInboundMediaOrigins(), true);
    }

    private function normalizeOrigin(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower(trim((string) ($parts['scheme'] ?? '')));
        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if ($scheme === '' || $host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $scheme.'://'.$host.($port !== null ? ':'.$port : '');
    }

    private function allowsLiteralAddress(string $url): bool
    {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        return $this->isPublicIp($host) || $this->allowsPrivateOrigin($url);
    }

    private function allowsConnectedAddress(string $url, ?string $primaryIp): bool
    {
        if ($primaryIp === null || $primaryIp === '') {
            return app()->environment('testing');
        }

        return $this->isPublicIp($primaryIp) || $this->allowsPrivateOrigin($url);
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function allowsPrivateOrigin(string $url): bool
    {
        $origin = $this->normalizeOrigin($url);

        return $origin !== null
            && in_array($origin, (array) config('chat.trusted_private_media_origins', []), true);
    }

    private function originHash(string $url): string
    {
        return hash('sha256', $this->normalizeOrigin($url) ?? 'invalid-origin');
    }
}
