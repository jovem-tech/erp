<?php

namespace App\Services\Files;

use App\Enums\Files\FileCategory;
use App\Enums\Files\FileIntegrityStatus;
use App\Enums\Files\FileLifecycleStatus;
use App\Enums\Files\FileSecurityStatus;
use App\Models\Files\ManagedFile;
use Illuminate\Support\Facades\Storage;

class ManagedFileDeliveryService
{
    public function __construct(
        private readonly FileManagerConfiguration $configuration,
        private readonly FilePolicyRegistry $policies
    ) {}

    /** @return array{stream: resource, mime_type: string, file_name: string, inline: bool} */
    public function open(ManagedFile $file, bool $preview = false, bool $allowTrashedPreview = false): array
    {
        $entry = $this->locate($file, $allowTrashedPreview);
        if ($preview && ! $this->policies->allowsInline($entry['category'], $entry['mime_type'])) {
            throw new \UnexpectedValueException('Preview inline não permitido para este arquivo.');
        }

        $stream = fopen($entry['absolute_path'], 'rb');
        if (! is_resource($stream)) {
            throw new \RuntimeException('Arquivo indisponível no storage.');
        }

        return [
            'stream' => $stream,
            'mime_type' => $entry['mime_type'],
            'file_name' => $entry['file_name'],
            'inline' => $preview,
        ];
    }

    /**
     * Resolve um arquivo já validado para composição de pacotes internos.
     * O caminho absoluto nunca deve ser serializado em respostas da API.
     *
     * @return array{absolute_path: string, file_name: string, mime_type: string, category: FileCategory}
     */
    public function locate(ManagedFile $file, bool $allowTrashedPreview = false): array
    {
        $this->assertDeliverable($file, $allowTrashedPreview);

        return $this->locateStoredSource($file);
    }

    /**
     * Valida e resolve somente a fonte física, sem alterar as regras de
     * entrega por lifecycle. Usado antes de restaurar arquivos arquivados ou
     * presentes na lixeira.
     *
     * @return array{absolute_path: string, file_name: string, mime_type: string, category: FileCategory}
     */
    public function locateStoredSource(ManagedFile $file): array
    {

        $category = FileCategory::tryFrom((string) $file->category);
        if ($category === null) {
            throw new \DomainException('Categoria sem policy de entrega.');
        }

        $diskName = (string) $file->storage_disk;
        if (! $this->isDiskAllowed($diskName)) {
            throw new \RuntimeException('Disco do arquivo não autorizado para leitura.');
        }

        $storageKey = FilePathGuard::normalizeRelativePath((string) $file->storage_key);

        return [
            'absolute_path' => $this->assertPathContained($diskName, $storageKey),
            'file_name' => FilePathGuard::safeFileName((string) $file->safe_download_name, (string) $file->extension),
            'mime_type' => strtolower(trim((string) $file->detected_mime_type)),
            'category' => $category,
        ];
    }

    private function assertDeliverable(ManagedFile $file, bool $allowTrashedPreview): void
    {
        $allowedLifecycles = [FileLifecycleStatus::Active];
        if ($allowTrashedPreview) {
            $allowedLifecycles[] = FileLifecycleStatus::Trashed;
        }
        if (! in_array($file->lifecycle_status, $allowedLifecycles, true)) {
            throw new \DomainException('Arquivo fora do lifecycle ativo.');
        }
        if ($file->security_status !== FileSecurityStatus::Clean) {
            throw new \DomainException('Arquivo bloqueado pelo estado de segurança.');
        }
        if ($file->integrity_status !== FileIntegrityStatus::Valid) {
            throw new \DomainException('Arquivo bloqueado pelo estado de integridade.');
        }
    }

    private function isDiskAllowed(string $diskName): bool
    {
        return $diskName === (string) config('file-manager.storage.disk')
            || $this->configuration->isLegacyReadDiskAllowed($diskName);
    }

    private function assertPathContained(string $diskName, string $storageKey): string
    {
        $disk = Storage::disk($diskName);
        if (! $disk->exists($storageKey)) {
            throw new \RuntimeException('Arquivo indisponível no storage.');
        }

        $root = realpath($disk->path(''));
        $candidate = realpath($disk->path($storageKey));
        if (! is_string($root) || ! is_string($candidate)) {
            throw new \RuntimeException('Não foi possível validar o caminho do arquivo.');
        }

        $root = rtrim(str_replace('\\', '/', $root), '/').'/';
        $candidate = str_replace('\\', '/', $candidate);
        if (! str_starts_with($candidate, $root) || ! is_file($candidate) || ! is_readable($candidate)) {
            throw new \RuntimeException('Caminho do arquivo escapou da raiz autorizada.');
        }

        if ($diskName !== (string) config('file-manager.storage.disk')) {
            return $candidate;
        }

        $allowedPrefixes = [FilePathGuard::normalizeRelativePath((string) config('file-manager.storage.root'))];
        foreach ((array) config('file-manager.scanner.roots', []) as $scannerRoot) {
            if (is_array($scannerRoot) && (string) ($scannerRoot['disk'] ?? '') === $diskName) {
                $allowedPrefixes[] = FilePathGuard::normalizeRelativePath((string) ($scannerRoot['path'] ?? ''));
            }
        }

        foreach (array_unique($allowedPrefixes) as $prefix) {
            if ($storageKey === $prefix || str_starts_with($storageKey, $prefix.'/')) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Arquivo fora dos namespaces autorizados.');
    }
}
