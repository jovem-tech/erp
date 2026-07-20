<?php

namespace App\Services\Files;

use App\Models\Files\ManagedFile;
use Illuminate\Support\Collection;
use ZipArchive;

class ManagedFileArchiveService
{
    public function __construct(private readonly ManagedFileDeliveryService $delivery) {}

    /**
     * @param  Collection<int, ManagedFile>  $files
     * @return array{absolute_path: string, file_name: string, size_bytes: int}
     */
    public function build(Collection $files): array
    {
        $maxFiles = (int) config('file-manager.batch_download.max_files', 50);
        $maxBytes = (int) config('file-manager.batch_download.max_bytes', 104_857_600);
        $totalBytes = (int) $files->sum('size_bytes');

        if ($files->isEmpty() || $files->count() > $maxFiles) {
            throw new \DomainException("Selecione entre 1 e {$maxFiles} arquivos.");
        }
        if ($totalBytes > $maxBytes) {
            throw new \DomainException('O pacote selecionado excede o limite de download em lote.');
        }
        if (! class_exists(ZipArchive::class)) {
            throw new \RuntimeException('O suporte a arquivos ZIP não está disponível.');
        }

        $temporaryPath = tempnam(storage_path('framework/cache'), 'files-');
        if (! is_string($temporaryPath)) {
            throw new \RuntimeException('Não foi possível reservar o pacote temporário.');
        }

        $zip = new ZipArchive;
        try {
            if ($zip->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Não foi possível criar o pacote ZIP.');
            }

            $usedNames = [];
            foreach ($files as $file) {
                $entry = $this->delivery->locate($file);
                $entryName = $this->uniqueEntryName($entry['file_name'], $usedNames);
                if (! $zip->addFile($entry['absolute_path'], $entryName)) {
                    throw new \RuntimeException('Não foi possível adicionar um arquivo ao pacote ZIP.');
                }
            }

            if (! $zip->close()) {
                throw new \RuntimeException('Não foi possível finalizar o pacote ZIP.');
            }

            return [
                'absolute_path' => $temporaryPath,
                'file_name' => 'arquivos-selecionados-'.now()->format('Ymd-His').'.zip',
                'size_bytes' => (int) filesize($temporaryPath),
            ];
        } catch (\Throwable $exception) {
            $zip->close();
            @unlink($temporaryPath);

            throw $exception;
        }
    }

    /** @param array<string, true> $usedNames */
    private function uniqueEntryName(string $fileName, array &$usedNames): string
    {
        $candidate = $fileName;
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $baseName = pathinfo($fileName, PATHINFO_FILENAME) ?: 'arquivo';
        $suffix = 2;

        while (isset($usedNames[mb_strtolower($candidate)])) {
            $candidate = $baseName.'-'.$suffix.($extension !== '' ? '.'.$extension : '');
            $suffix++;
        }

        $usedNames[mb_strtolower($candidate)] = true;

        return $candidate;
    }
}
