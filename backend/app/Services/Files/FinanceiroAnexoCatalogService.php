<?php

namespace App\Services\Files;

use App\DTO\Files\FileContext;
use App\Enums\Files\FileCategory;
use App\Enums\Files\FileIntegrityStatus;
use App\Enums\Files\FileOrigin;
use App\Enums\Files\ManagedFileAction;
use App\Models\Files\ManagedFile;
use App\Models\Files\ManagedFileLegacyAlias;
use App\Models\Files\ManagedFileLink;
use App\Models\FinanceiroAnexo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FinanceiroAnexoCatalogService
{
    public function __construct(
        private readonly LegacyCompatibleFileAdapter $adapter,
        private readonly LegacyFileResolver $legacyResolver,
        private readonly FileStateMachine $states,
        private readonly ManagedFileEventRecorder $events
    ) {}

    public function synchronize(FinanceiroAnexo $anexo): ?ManagedFile
    {
        $financeiroId = (int) $anexo->financeiro_id;
        $anexoId = (int) $anexo->id;
        if ($financeiroId <= 0 || $anexoId <= 0) {
            return null;
        }

        $storagePath = FilePathGuard::normalizeRelativePath((string) $anexo->arquivo);
        $allowedPrefix = 'private/financeiro/'.$financeiroId;
        if ($storagePath !== $allowedPrefix && ! str_starts_with($storagePath, $allowedPrefix.'/')) {
            throw new \InvalidArgumentException('Anexo financeiro fora do namespace autorizado.');
        }
        if (! Storage::disk('local')->exists($storagePath)) {
            throw new \RuntimeException('Binário do anexo financeiro não está disponível.');
        }
        FilePathGuard::assertContainedRegularFile(Storage::disk('local'), $storagePath, $allowedPrefix);

        return Cache::lock(
            'file-manager:financeiro-anexo:'.$anexoId,
            max(10, (int) config('file-manager.locks.seconds', 30))
        )->block(max(1, (int) config('file-manager.locks.wait_seconds', 5)), function () use ($anexo, $anexoId, $financeiroId, $storagePath): ?ManagedFile {
            $managed = ManagedFile::query()
                ->where('storage_disk', 'local')
                ->where('storage_key', $storagePath)
                ->first();

            if ($managed instanceof ManagedFile && (string) $managed->category !== FileCategory::FinanceiroAnexo->value) {
                throw new \DomainException('O caminho do anexo já pertence a outra categoria gerenciada.');
            }

            if (! $managed instanceof ManagedFile) {
                $managed = $this->adapter->synchronizeExisting(
                    new FileContext(
                        category: FileCategory::FinanceiroAnexo,
                        origin: FileOrigin::Upload,
                        operationKey: 'financeiro-anexo:'.$anexoId.':'.hash('sha256', $storagePath),
                        subjectType: 'financeiro',
                        subjectId: $financeiroId,
                        relation: 'anexo:'.$anexoId,
                        createdBy: $anexo->usuario_id !== null ? (int) $anexo->usuario_id : null
                    ),
                    'local',
                    $storagePath,
                    'financeiro_anexos',
                    'arquivo',
                    (string) $anexoId
                );
            }
            if (! $managed instanceof ManagedFile) {
                return null;
            }

            $this->attachDomainMetadata($anexo, $managed, $storagePath);

            $absolutePath = FilePathGuard::assertContainedRegularFile(
                Storage::disk('local'),
                $storagePath,
                'private/financeiro/'.$financeiroId
            );
            $actualHash = hash_file('sha256', $absolutePath);
            if (! is_string($actualHash)) {
                throw new \RuntimeException('Não foi possível verificar o hash do anexo financeiro.');
            }
            $legacyHash = strtolower(trim((string) $anexo->hash_sha256));
            $legacyHashIsValid = preg_match('/^[a-f0-9]{64}$/', $legacyHash) === 1;
            $hashDiverges = ! hash_equals(strtolower((string) $managed->sha256), $actualHash)
                || ($legacyHash !== '' && ! $legacyHashIsValid)
                || ($legacyHashIsValid && ! hash_equals($legacyHash, $actualHash));
            $targetIntegrity = $hashDiverges ? FileIntegrityStatus::Corrupted : FileIntegrityStatus::Valid;
            if ($managed->integrity_status !== $targetIntegrity) {
                $managed = $this->states->markIntegrity($managed, $targetIntegrity);
            }

            $anexo->setAttribute('managed_file_uuid', (string) $managed->uuid);
            $anexo->setAttribute('file_manager_synced_at', now());
            $anexo->setRelation('managedFile', $managed);

            return $managed;
        });
    }

    private function attachDomainMetadata(FinanceiroAnexo $anexo, ManagedFile $managed, string $storagePath): void
    {
        DB::transaction(function () use ($anexo, $managed, $storagePath): void {
            $lockedFile = ManagedFile::query()->lockForUpdate()->findOrFail($managed->id);
            $lockedAnexo = FinanceiroAnexo::withTrashed()->lockForUpdate()->findOrFail($anexo->id);
            $currentUuid = trim((string) $lockedAnexo->managed_file_uuid);
            if ($currentUuid !== '' && ! hash_equals($currentUuid, (string) $lockedFile->uuid)) {
                throw new \DomainException('Anexo financeiro já vinculado a outro arquivo gerenciado.');
            }

            $safeName = FilePathGuard::safeFileName((string) $lockedAnexo->nome_original, (string) $lockedFile->extension);
            $lockedFile->forceFill([
                'original_name' => $safeName,
                'safe_download_name' => $safeName,
            ])->save();

            ManagedFileLink::query()
                ->where('subject_type', 'financeiro')
                ->where('subject_id', (int) $lockedAnexo->financeiro_id)
                ->where('relation', 'anexo:'.(int) $lockedAnexo->id)
                ->where('file_id', '!=', $lockedFile->id)
                ->where('is_current', true)
                ->update(['is_current' => false, 'unlinked_at' => now()]);

            $link = ManagedFileLink::query()->firstOrNew([
                'file_id' => $lockedFile->id,
                'subject_type' => 'financeiro',
                'subject_id' => (int) $lockedAnexo->financeiro_id,
                'relation' => 'anexo:'.(int) $lockedAnexo->id,
            ]);
            $wasActive = $link->exists && (bool) $link->is_current && $link->unlinked_at === null;
            $link->forceFill([
                'is_current' => true,
                'unlinked_at' => null,
                'created_by' => $link->created_by ?? $lockedAnexo->usuario_id,
                'metadata_json' => array_merge((array) $link->metadata_json, [
                    'reconciled_from' => 'financeiro_anexos',
                    'source_record_id' => (string) $lockedAnexo->id,
                ]),
            ])->save();

            $alias = $this->legacyResolver->addAlias(
                $lockedFile,
                'local',
                $storagePath,
                'financeiro_anexos',
                'arquivo',
                (string) $lockedAnexo->id
            );
            if ((int) $alias->file_id !== (int) $lockedFile->id) {
                throw new \DomainException('Alias do anexo financeiro aponta para outro arquivo.');
            }
            $alias->forceFill([
                'source_table' => 'financeiro_anexos',
                'source_column' => 'arquivo',
                'source_record_id' => (string) $lockedAnexo->id,
                'verified_at' => now(),
                'retired_at' => null,
            ])->save();

            ManagedFileLegacyAlias::query()
                ->where('source_table', 'financeiro_anexos')
                ->where('source_record_id', (string) $lockedAnexo->id)
                ->where('file_id', '!=', $lockedFile->id)
                ->whereNull('retired_at')
                ->update(['retired_at' => now()]);

            $lockedAnexo->forceFill([
                'managed_file_uuid' => (string) $lockedFile->uuid,
                'file_manager_synced_at' => now(),
            ])->saveQuietly();

            if (! $wasActive) {
                $this->events->record(
                    ManagedFileAction::Linked,
                    'success',
                    $lockedFile,
                    $lockedAnexo->usuario_id !== null ? (int) $lockedAnexo->usuario_id : null,
                    FileCategory::FinanceiroAnexo->value,
                    ['relation' => 'anexo:'.(int) $lockedAnexo->id, 'origin' => 'financeiro_reconciliation']
                );
            }
        }, attempts: 3);
    }
}
