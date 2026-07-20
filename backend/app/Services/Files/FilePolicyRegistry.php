<?php

namespace App\Services\Files;

use App\DTO\Files\FileDescriptor;
use App\Enums\Files\FileCategory;

class FilePolicyRegistry
{
    /**
     * @return array{extension: string, detected_mime_type: string, size_bytes: int}
     */
    public function validate(FileDescriptor $descriptor, FileCategory $category): array
    {
        $policy = config("file-manager.policies.{$category->value}");
        if (! is_array($policy)) {
            throw new \RuntimeException('Policy de arquivo nao registrada.');
        }

        if (! is_file($descriptor->sourcePath) || ! is_readable($descriptor->sourcePath)) {
            throw new \InvalidArgumentException('Arquivo de origem indisponivel durante a validacao.');
        }

        $size = @filesize($descriptor->sourcePath);
        $maxBytes = max(1, (int) ($policy['max_bytes'] ?? 0));
        if (! is_int($size) || $size <= 0 || $size > $maxBytes) {
            throw new \InvalidArgumentException('Tamanho do arquivo fora da policy.');
        }

        $extension = strtolower((string) pathinfo($descriptor->originalName, PATHINFO_EXTENSION));
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $this->normalizeMimeType((string) (@$finfo->file($descriptor->sourcePath) ?: ''));
        $allowed = is_array($policy['mime_extensions'] ?? null) ? $policy['mime_extensions'] : [];

        if ($extension === '' || ! in_array($extension, $allowed[$mimeType] ?? [], true)) {
            throw new \InvalidArgumentException('Extensao ou MIME nao permitido pela policy.');
        }

        $declaredMimeType = $this->normalizeMimeType((string) $descriptor->declaredMimeType);
        if (
            $declaredMimeType !== ''
            && $declaredMimeType !== 'application/octet-stream'
            && ! in_array($extension, $allowed[$declaredMimeType] ?? [], true)
        ) {
            throw new \InvalidArgumentException('MIME declarado diverge do conteudo.');
        }

        $this->validateDecoder($descriptor->sourcePath, $mimeType);

        return [
            'extension' => $extension,
            'detected_mime_type' => $mimeType,
            'size_bytes' => $size,
        ];
    }

    public function allowsInline(FileCategory $category, string $mimeType): bool
    {
        return in_array(
            $this->normalizeMimeType($mimeType),
            (array) config("file-manager.policies.{$category->value}.inline_mime_types", []),
            true
        );
    }

    private function validateDecoder(string $sourcePath, string $mimeType): void
    {
        if (str_starts_with($mimeType, 'image/')) {
            $dimensions = @getimagesize($sourcePath);
            if (! is_array($dimensions) || ($dimensions[0] * $dimensions[1]) > 40_000_000) {
                throw new \InvalidArgumentException('Imagem invalida ou com dimensoes excessivas.');
            }
        }

        if ($mimeType === 'application/pdf') {
            $handle = fopen($sourcePath, 'rb');
            $header = is_resource($handle) ? fread($handle, 5) : false;
            if (is_resource($handle)) {
                fclose($handle);
            }

            if ($header !== '%PDF-') {
                throw new \InvalidArgumentException('PDF invalido.');
            }
        }
    }

    private function normalizeMimeType(string $mimeType): string
    {
        $mimeType = strtolower(trim(explode(';', $mimeType, 2)[0] ?? ''));

        return match ($mimeType) {
            'image/jpg', 'image/pjpeg' => 'image/jpeg',
            'audio/x-wav' => 'audio/wav',
            'audio/x-m4a' => 'audio/mp4',
            'application/x-zip-compressed' => 'application/zip',
            default => $mimeType,
        };
    }
}
