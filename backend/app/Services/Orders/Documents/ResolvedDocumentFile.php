<?php

namespace App\Services\Orders\Documents;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Um formato (A4/80mm) de uma versão documental, resolvido para consumo:
 * pode estar em disco (assinatura formal / legado), no cache de render, ou
 * ter acabado de ser renderizado do snapshot. Todo consumidor trabalha com
 * bytes(); só quem precisa de caminho (pdftocairo, UploadedFile do chat)
 * usa withTempFile(), que apaga o temporário no finally.
 */
final class ResolvedDocumentFile
{
    public const SOURCE_DISK = 'disk';

    public const SOURCE_RENDER_CACHE = 'render_cache';

    public const SOURCE_SNAPSHOT = 'snapshot';

    public const SOURCE_LIVE = 'live';

    private ?string $sha256 = null;

    /**
     * @param  array<int, string>  $divergencias
     */
    private function __construct(
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly string $source,
        public readonly string $relativePath,
        public readonly ?string $absolutePath,
        public readonly string $managedFileUuid,
        private ?string $bytes,
        public readonly array $divergencias = []
    ) {
    }

    public static function fromDisk(string $relativePath, string $absolutePath, string $mimeType, string $managedFileUuid = ''): self
    {
        return new self(basename($relativePath), $mimeType, self::SOURCE_DISK, $relativePath, $absolutePath, $managedFileUuid, null);
    }

    /**
     * @param  array<int, string>  $divergencias
     */
    public static function fromBytes(string $relativePath, string $bytes, string $source, array $divergencias = []): self
    {
        return new self(basename($relativePath), 'application/pdf', $source, $relativePath, null, '', $bytes, $divergencias);
    }

    public function isOnDisk(): bool
    {
        return $this->absolutePath !== null;
    }

    public function bytes(): string
    {
        if ($this->bytes === null) {
            $bytes = $this->absolutePath !== null ? @file_get_contents($this->absolutePath) : false;
            $this->bytes = is_string($bytes) ? $bytes : '';
        }

        return $this->bytes;
    }

    public function size(): int
    {
        if ($this->bytes === null && $this->absolutePath !== null) {
            $size = @filesize($this->absolutePath);

            return $size === false ? 0 : (int) $size;
        }

        return strlen($this->bytes());
    }

    public function sha256(): string
    {
        return $this->sha256 ??= hash('sha256', $this->bytes());
    }

    /**
     * Executa $fn com um caminho de arquivo válido: o próprio arquivo quando
     * está em disco, ou um temporário 0600 apagado ao final.
     *
     * @template T
     *
     * @param  callable(string): T  $fn
     * @return T
     */
    public function withTempFile(callable $fn): mixed
    {
        if ($this->absolutePath !== null && is_file($this->absolutePath)) {
            return $fn($this->absolutePath);
        }

        $directory = (string) config('document-rendering.temp_directory', storage_path('framework/cache/pdf-tmp'));
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Não foi possível reservar o diretório temporário do documento.');
        }

        $path = tempnam($directory, 'doc-');
        if (! is_string($path)) {
            throw new \RuntimeException('Não foi possível reservar o arquivo temporário do documento.');
        }

        try {
            @chmod($path, 0600);
            if (file_put_contents($path, $this->bytes()) === false) {
                throw new \RuntimeException('Não foi possível materializar o documento temporário.');
            }

            return $fn($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Resposta HTTP com os mesmos cabeçalhos que o chamador já usava: arquivo
     * em disco vai como BinaryFileResponse (sem carregar em memória); render
     * sob demanda vai como bytes com Content-Length.
     *
     * @param  array<string, string>  $headers
     */
    public function toResponse(array $headers): Response|BinaryFileResponse
    {
        if ($this->absolutePath !== null && is_file($this->absolutePath)) {
            return response()->file($this->absolutePath, $headers);
        }

        $bytes = $this->bytes();

        return response($bytes, 200, $headers + ['Content-Length' => (string) strlen($bytes)]);
    }

    /**
     * Forma compatível com o payload antigo de resolveDocumentFilePayload():
     * quem só olhava absolute_path continua funcionando para arquivos em
     * disco; render sob demanda deixa absolute_path vazio de propósito.
     *
     * @return array<string, mixed>
     */
    public function toLegacyArray(): array
    {
        return [
            'relative_path' => $this->relativePath,
            'absolute_path' => $this->absolutePath ?? '',
            'filename' => $this->filename,
            'mime_type' => $this->mimeType,
            'managed_file_uuid' => $this->managedFileUuid,
            'source' => $this->source,
            'divergencias' => $this->divergencias,
            'resolved' => $this,
        ];
    }
}
