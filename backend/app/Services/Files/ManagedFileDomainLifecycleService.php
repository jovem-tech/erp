<?php

namespace App\Services\Files;

use App\Enums\Files\FileCategory;
use App\Enums\Files\FileLifecycleStatus;
use App\Models\Files\ManagedFile;
use App\Models\Financeiro;
use App\Models\FinanceiroAnexo;
use Illuminate\Support\Facades\DB;

class ManagedFileDomainLifecycleService
{
    public function __construct(
        private readonly FileManagerConfiguration $configuration,
        private readonly FileStateMachine $states
    ) {}

    public function trash(
        ManagedFile $file,
        ?int $actorId = null,
        ?string $reason = null,
        ?int $authorizedBy = null,
        string $origin = 'file_manager'
    ): ManagedFile {
        if (! $this->managesFinanceiro($file)) {
            return $this->states->trash($file, $actorId, $reason, $authorizedBy);
        }

        return DB::transaction(function () use ($file, $actorId, $reason, $authorizedBy, $origin): ManagedFile {
            $locked = ManagedFile::query()->lockForUpdate()->findOrFail($file->id);
            $anexo = FinanceiroAnexo::withTrashed()
                ->where('managed_file_uuid', (string) $locked->uuid)
                ->lockForUpdate()
                ->first();

            if ($locked->lifecycle_status !== FileLifecycleStatus::Trashed) {
                $locked = $this->states->trash(
                    $locked,
                    $actorId,
                    $reason,
                    $authorizedBy,
                    ['origin' => $origin]
                );
            }
            if ($anexo instanceof FinanceiroAnexo && ! $anexo->trashed()) {
                $anexo->delete();
            }

            return $locked;
        }, attempts: 3);
    }

    public function restore(
        ManagedFile $file,
        ?int $actorId = null,
        ?string $reason = null,
        ?int $authorizedBy = null
    ): ManagedFile {
        if (! $this->managesFinanceiro($file)) {
            return $this->states->restore($file, $actorId, $reason, $authorizedBy);
        }

        return DB::transaction(function () use ($file, $actorId, $reason, $authorizedBy): ManagedFile {
            $locked = ManagedFile::query()->lockForUpdate()->findOrFail($file->id);
            $anexo = FinanceiroAnexo::withTrashed()
                ->where('managed_file_uuid', (string) $locked->uuid)
                ->lockForUpdate()
                ->first();

            if (! $anexo instanceof FinanceiroAnexo) {
                throw new \DomainException('O anexo financeiro de origem não existe mais.');
            }
            if (! Financeiro::query()->whereKey((int) $anexo->financeiro_id)->exists()) {
                throw new \DomainException('O lançamento financeiro de origem não existe mais.');
            }

            if ($locked->lifecycle_status !== FileLifecycleStatus::Active) {
                $locked = $this->states->restore(
                    $locked,
                    $actorId,
                    $reason,
                    $authorizedBy,
                    ['origin' => 'file_manager']
                );
            }
            if ($anexo->trashed()) {
                $anexo->restore();
            }

            return $locked;
        }, attempts: 3);
    }

    private function managesFinanceiro(ManagedFile $file): bool
    {
        return (string) $file->category === FileCategory::FinanceiroAnexo->value
            && $this->configuration->isAuthoritativeCategory(FileCategory::FinanceiroAnexo);
    }
}
