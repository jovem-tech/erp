<?php

namespace App\Services\Files;

use App\Contracts\Files\PdfThumbnailRenderer;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Str;

/**
 * Miolo compartilhado das miniaturas PDF: rasteriza a primeira página num
 * PNG dentro de um disco de cache, valida e promove com rename atômico.
 * Usado pelo gerenciador (/arquivos, chave = sha256 do ManagedFile) e pela
 * Central Documental (chave = render do snapshot, sem ManagedFile).
 */
final class PdfThumbnailRasterizer
{
    public function __construct(private readonly PdfThumbnailRenderer $renderer)
    {
    }

    public function renderToCache(
        FilesystemAdapter $disk,
        string $relativePath,
        int $maxDimension,
        string $sourcePath
    ): string {
        $directory = dirname($relativePath);
        if (! $disk->makeDirectory($directory) && ! $disk->directoryExists($directory)) {
            throw new \RuntimeException('Nao foi possivel preparar o cache de miniaturas.');
        }

        $absoluteDirectory = $this->containedDirectory($disk, $directory);
        $targetPath = $absoluteDirectory.DIRECTORY_SEPARATOR.basename($relativePath);
        $temporaryPath = $targetPath.'.'.Str::random(20).'.tmp.png';

        try {
            $this->renderer->render(
                $sourcePath,
                $temporaryPath,
                $maxDimension,
                (int) config('file-manager.pdf_thumbnails.timeout_seconds', 10)
            );
            $this->assertValidPng($temporaryPath, $maxDimension);
            @chmod($temporaryPath, 0640);

            if (! @rename($temporaryPath, $targetPath)) {
                throw new \RuntimeException('Nao foi possivel promover a miniatura PDF.');
            }
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }

        return $this->validCachedPath($disk, $relativePath, $maxDimension)
            ?? throw new \RuntimeException('Miniatura PDF invalida apos renderizacao.');
    }

    public function validCachedPath(
        FilesystemAdapter $disk,
        string $relativePath,
        int $maxDimension
    ): ?string {
        if (! $disk->exists($relativePath)) {
            return null;
        }

        $root = realpath($disk->path(''));
        $candidate = realpath($disk->path($relativePath));
        if (! is_string($root) || ! is_string($candidate)) {
            return null;
        }

        $root = rtrim(str_replace('\\', '/', $root), '/').'/';
        $normalizedCandidate = str_replace('\\', '/', $candidate);
        if (! str_starts_with($normalizedCandidate, $root) || ! is_file($candidate) || ! is_readable($candidate)) {
            return null;
        }

        try {
            $this->assertValidPng($candidate, $maxDimension);
        } catch (\RuntimeException) {
            return null;
        }

        return $candidate;
    }

    private function containedDirectory(FilesystemAdapter $disk, string $relativeDirectory): string
    {
        $root = realpath($disk->path(''));
        $directory = realpath($disk->path($relativeDirectory));
        if (! is_string($root) || ! is_string($directory)) {
            throw new \RuntimeException('Diretorio de cache indisponivel.');
        }

        $root = rtrim(str_replace('\\', '/', $root), '/').'/';
        $normalizedDirectory = rtrim(str_replace('\\', '/', $directory), '/').'/';
        if (! str_starts_with($normalizedDirectory, $root)) {
            throw new \RuntimeException('Diretorio de cache fora da raiz autorizada.');
        }

        return $directory;
    }

    public function assertValidPng(string $path, int $maxDimension): void
    {
        $size = @filesize($path);
        $maximumBytes = (int) config('file-manager.pdf_thumbnails.max_bytes', 2_097_152);
        if (! is_int($size) || $size < 16 || $size > $maximumBytes) {
            throw new \RuntimeException('Tamanho da miniatura PDF invalido.');
        }

        $image = @getimagesize($path);
        $width = is_array($image) ? (int) ($image[0] ?? 0) : 0;
        $height = is_array($image) ? (int) ($image[1] ?? 0) : 0;
        $mime = is_array($image) ? (string) ($image['mime'] ?? '') : '';
        if (
            $mime !== 'image/png'
            || $width < 1
            || $height < 1
            || $width > $maxDimension
            || $height > $maxDimension
        ) {
            throw new \RuntimeException('Conteudo da miniatura PDF invalido.');
        }
    }
}
