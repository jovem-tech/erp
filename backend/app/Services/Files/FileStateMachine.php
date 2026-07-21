<?php

namespace App\Services\Files;

use App\Enums\Files\FileIntegrityStatus;
use App\Enums\Files\FileLifecycleStatus;
use App\Enums\Files\FileSecurityStatus;
use App\Enums\Files\ManagedFileAction;
use App\Models\Files\ManagedFile;
use Illuminate\Support\Facades\DB;

class FileStateMachine
{
    public function __construct(private readonly ManagedFileEventRecorder $events) {}

    public function archive(
        ManagedFile $file,
        ?int $actorId = null,
        ?string $reason = null,
        ?int $authorizedBy = null
    ): ManagedFile {
        return $this->transitionLifecycle(
            $file,
            FileLifecycleStatus::Archived,
            $actorId,
            ManagedFileAction::Archived,
            $this->auditContext($reason, $authorizedBy)
        );
    }

    public function restore(
        ManagedFile $file,
        ?int $actorId = null,
        ?string $reason = null,
        ?int $authorizedBy = null
    ): ManagedFile {
        return $this->transitionLifecycle(
            $file,
            FileLifecycleStatus::Active,
            $actorId,
            ManagedFileAction::Restored,
            $this->auditContext($reason, $authorizedBy)
        );
    }

    public function trash(
        ManagedFile $file,
        ?int $actorId = null,
        ?string $reason = null,
        ?int $authorizedBy = null
    ): ManagedFile {
        return $this->transitionLifecycle(
            $file,
            FileLifecycleStatus::Trashed,
            $actorId,
            ManagedFileAction::Trashed,
            $this->auditContext($reason, $authorizedBy)
        );
    }

    public function quarantine(
        ManagedFile $file,
        string $reason,
        ?int $actorId = null,
        ?int $authorizedBy = null
    ): ManagedFile {
        return DB::transaction(function () use ($file, $reason, $actorId, $authorizedBy): ManagedFile {
            $locked = ManagedFile::query()->lockForUpdate()->findOrFail($file->id);
            if (! in_array($locked->security_status, [FileSecurityStatus::Pending, FileSecurityStatus::Clean], true)) {
                throw new \DomainException('Transicao de seguranca invalida.');
            }

            $locked->forceFill([
                'security_status' => FileSecurityStatus::Quarantined,
                'quarantined_at' => now(),
            ])->save();
            $this->events->record(
                ManagedFileAction::Quarantined,
                'success',
                $locked,
                $actorId,
                $locked->category,
                $this->auditContext($reason, $authorizedBy)
            );

            return $locked->fresh() ?? $locked;
        });
    }

    public function releaseFromQuarantine(
        ManagedFile $file,
        ?int $actorId = null,
        ?string $reason = null,
        ?string $validationReference = null,
        ?int $authorizedBy = null
    ): ManagedFile {
        return DB::transaction(function () use ($file, $actorId, $reason, $validationReference, $authorizedBy): ManagedFile {
            $locked = ManagedFile::query()->lockForUpdate()->findOrFail($file->id);
            if ($locked->security_status !== FileSecurityStatus::Quarantined) {
                throw new \DomainException('Somente arquivo em quarentena pode ser liberado.');
            }

            $locked->forceFill([
                'security_status' => FileSecurityStatus::Clean,
                'quarantined_at' => null,
            ])->save();
            $this->events->record(
                ManagedFileAction::ReleasedFromQuarantine,
                'success',
                $locked,
                $actorId,
                $locked->category,
                array_merge(
                    $this->auditContext($reason, $authorizedBy),
                    ['validation_reference' => $validationReference]
                )
            );

            return $locked->fresh() ?? $locked;
        });
    }

    public function markIntegrity(ManagedFile $file, FileIntegrityStatus $status): ManagedFile
    {
        return DB::transaction(function () use ($file, $status): ManagedFile {
            $locked = ManagedFile::query()->lockForUpdate()->findOrFail($file->id);
            $locked->forceFill(['integrity_status' => $status])->save();
            $this->events->record(
                ManagedFileAction::IntegrityChecked,
                'success',
                $locked,
                module: $locked->category,
                context: ['integrity_status' => $status->value]
            );

            return $locked->fresh() ?? $locked;
        });
    }

    private function transitionLifecycle(
        ManagedFile $file,
        FileLifecycleStatus $target,
        ?int $actorId,
        ManagedFileAction $action,
        array $context = []
    ): ManagedFile {
        return DB::transaction(function () use ($file, $target, $actorId, $action, $context): ManagedFile {
            $locked = ManagedFile::query()->lockForUpdate()->findOrFail($file->id);
            $allowed = match ($locked->lifecycle_status) {
                FileLifecycleStatus::Active => [FileLifecycleStatus::Archived, FileLifecycleStatus::Trashed],
                FileLifecycleStatus::Archived => [FileLifecycleStatus::Active, FileLifecycleStatus::Trashed],
                FileLifecycleStatus::Trashed => [FileLifecycleStatus::Active, FileLifecycleStatus::Archived],
                FileLifecycleStatus::Purged => [],
            };

            if (! in_array($target, $allowed, true)) {
                throw new \DomainException('Transicao de lifecycle invalida.');
            }

            $locked->forceFill([
                'lifecycle_status' => $target,
                'archived_at' => $target === FileLifecycleStatus::Archived ? now() : null,
                'trashed_at' => $target === FileLifecycleStatus::Trashed ? now() : null,
            ])->save();
            $this->events->record($action, 'success', $locked, $actorId, $locked->category, $context);

            return $locked->fresh() ?? $locked;
        });
    }

    /** @return array<string, scalar|null> */
    private function auditContext(?string $reason, ?int $authorizedBy): array
    {
        return [
            'reason' => $reason,
            'authorized_by' => $authorizedBy,
        ];
    }
}
