<?php

namespace App\Services\Chat;

use Illuminate\Http\UploadedFile;

class ChatAttachmentPolicy
{
    /**
     * @return array<int, mixed>
     */
    public function uploadRules(): array
    {
        return [
            'file',
            'max:'.$this->maxUploadKilobytes(),
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (! $value instanceof UploadedFile || ! $value->isValid()) {
                    $fail('O anexo enviado e invalido.');

                    return;
                }

                $inspection = $this->inspectUploadedFile($value);
                if (! $inspection['allowed']) {
                    $fail('O tipo de arquivo enviado nao e permitido.');
                }
            },
        ];
    }

    /**
     * @return array{allowed: bool, mime_type: string, extension: string, reason: string|null}
     */
    public function inspectUploadedFile(UploadedFile $file): array
    {
        return $this->inspect(
            (string) $file->getPathname(),
            (string) $file->getClientOriginalName(),
            (string) $file->getMimeType()
        );
    }

    /**
     * @return array{allowed: bool, mime_type: string, extension: string, reason: string|null}
     */
    public function inspectInboundBytes(string $bytes, string $declaredMimeType, string $originalName): array
    {
        $detectedMimeType = $this->detectMimeTypeFromBytes($bytes);
        $extension = $this->extensionFromName($originalName);

        if ($extension === '') {
            $extension = $this->preferredExtension($declaredMimeType);
        }

        return $this->evaluate($detectedMimeType, $extension, $declaredMimeType);
    }

    /**
     * @return array{allowed: bool, mime_type: string, extension: string, reason: string|null}
     */
    public function inspectStoredFile(string $mimeType, string $storedName): array
    {
        return $this->evaluate($mimeType, $this->extensionFromName($storedName));
    }

    public function isAllowedDisk(?string $disk): bool
    {
        return is_string($disk)
            && in_array($disk, (array) config('chat.attachments.allowed_disks', ['local']), true);
    }

    public function shouldRenderInline(string $mimeType, string $storedName): bool
    {
        $inspection = $this->inspectStoredFile($mimeType, $storedName);

        return $inspection['allowed']
            && in_array($inspection['mime_type'], (array) config('chat.attachments.inline_mime_types', []), true);
    }

    public function safeDownloadName(?string $name, string $fallbackExtension = 'bin'): string
    {
        $normalized = str_replace('\\', '/', trim((string) $name));
        $normalized = basename($normalized);
        $normalized = preg_replace('/[\x00-\x1F\x7F]+/u', '', $normalized) ?? '';
        $normalized = preg_replace('/[^\pL\pN._ -]+/u', '_', $normalized) ?? '';
        $normalized = trim($normalized, " .\t\n\r\0\x0B");

        if ($normalized === '') {
            $extension = preg_replace('/[^a-z0-9]+/', '', mb_strtolower($fallbackExtension)) ?: 'bin';
            $normalized = 'anexo.'.$extension;
        }

        if (mb_strlen($normalized) > 180) {
            $extension = $this->extensionFromName($normalized);
            $suffix = $extension !== '' ? '.'.$extension : '';
            $baseLength = max(1, 180 - mb_strlen($suffix));
            $base = mb_substr(pathinfo($normalized, PATHINFO_FILENAME), 0, $baseLength);
            $normalized = rtrim($base, ' .').$suffix;
        }

        return $normalized;
    }

    public function preferredExtension(string $mimeType): string
    {
        $mimeType = $this->normalizeMimeType($mimeType);
        $extensions = $this->allowedMimeExtensions()[$mimeType] ?? [];

        return (string) ($extensions[0] ?? 'bin');
    }

    /**
     * @return array{allowed: bool, mime_type: string, extension: string, reason: string|null}
     */
    private function inspect(string $absolutePath, string $originalName, string $declaredMimeType = ''): array
    {
        $detectedMimeType = '';

        if ($absolutePath !== '' && is_file($absolutePath)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detectedMimeType = (string) ($finfo->file($absolutePath) ?: '');
        }

        return $this->evaluate(
            $detectedMimeType,
            $this->extensionFromName($originalName),
            $declaredMimeType
        );
    }

    /**
     * @return array{allowed: bool, mime_type: string, extension: string, reason: string|null}
     */
    private function evaluate(string $detectedMimeType, string $extension, string $declaredMimeType = ''): array
    {
        $detectedMimeType = $this->normalizeMimeType($detectedMimeType);
        $declaredMimeType = $this->normalizeMimeType($declaredMimeType);
        $extension = mb_strtolower(ltrim(trim($extension), '.'));
        $allowed = $this->allowedMimeExtensions();

        if ($detectedMimeType === '' || $extension === '') {
            return $this->rejected($detectedMimeType, $extension, 'missing_type_or_extension');
        }

        if (! in_array($extension, $allowed[$detectedMimeType] ?? [], true)) {
            return $this->rejected($detectedMimeType, $extension, 'detected_type_not_allowed');
        }

        if (
            $declaredMimeType !== ''
            && $declaredMimeType !== 'application/octet-stream'
            && ! in_array($extension, $allowed[$declaredMimeType] ?? [], true)
        ) {
            return $this->rejected($detectedMimeType, $extension, 'declared_type_mismatch');
        }

        return [
            'allowed' => true,
            'mime_type' => $detectedMimeType,
            'extension' => $extension,
            'reason' => null,
        ];
    }

    /**
     * @return array{allowed: false, mime_type: string, extension: string, reason: string}
     */
    private function rejected(string $mimeType, string $extension, string $reason): array
    {
        return [
            'allowed' => false,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'reason' => $reason,
        ];
    }

    private function detectMimeTypeFromBytes(string $bytes): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return (string) ($finfo->buffer($bytes) ?: '');
    }

    private function extensionFromName(string $name): string
    {
        $normalized = str_replace('\\', '/', trim($name));

        return mb_strtolower(trim((string) pathinfo(basename($normalized), PATHINFO_EXTENSION)));
    }

    private function normalizeMimeType(string $mimeType): string
    {
        $mimeType = mb_strtolower(trim(explode(';', $mimeType, 2)[0] ?? ''));

        return match ($mimeType) {
            'image/jpg', 'image/pjpeg' => 'image/jpeg',
            'audio/x-wav' => 'audio/wav',
            'audio/x-m4a' => 'audio/mp4',
            'application/x-zip-compressed' => 'application/zip',
            default => $mimeType,
        };
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function allowedMimeExtensions(): array
    {
        $configured = config('chat.attachments.allowed_mime_extensions', []);

        return is_array($configured) ? $configured : [];
    }

    private function maxUploadKilobytes(): int
    {
        return max(1, (int) config('chat.attachments.max_upload_kilobytes', 25 * 1024));
    }
}
