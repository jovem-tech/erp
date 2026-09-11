<?php

namespace App\Services\Files;

use App\Enums\Files\FileCategory;
use App\Enums\Files\FileIntegrityStatus;
use App\Enums\Files\FileLifecycleStatus;
use App\Models\Files\ManagedFile;
use App\Models\Files\ManagedFileLegacyAlias;
use App\Models\FinanceiroAnexo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class FinanceiroAnexoReconciliationService
{
    public function __construct(
        private readonly FinanceiroAnexoCatalogService $catalog,
        private readonly ManagedFileDomainLifecycleService $lifecycle,
        private readonly FileStateMachine $states
    ) {}

    /**
     * @return array{mode:string,processed:int,repaired:int,consistent:int,pending:int,missing:int,integrity_errors:int,orphaned:int,retired_aliases:int,unlinked:int,duplicates:int,failed:int}
     */
    public function reconcile(bool $apply = false, int $limit = 1_000): array
    {
        if ($apply && ! (bool) config('file-manager.kill_switches.allow_mutating_reconcile', false)) {
            throw new \RuntimeException('Reconciliação financeira mutável desabilitada pelo kill switch.');
        }

        $result = [
            'mode' => $apply ? 'apply' : 'dry_run',
            'processed' => 0,
            'repaired' => 0,
            'consistent' => 0,
            'pending' => 0,
            'missing' => 0,
            'integrity_errors' => 0,
            'orphaned' => 0,
            'retired_aliases' => 0,
            'unlinked' => 0,
            'duplicates' => 0,
            'failed' => 0,
        ];

        if (! $this->schemaReady()) {
            return $result;
        }

        $limit = max(1, min(10_000, $limit));
        $anexos = FinanceiroAnexo::withTrashed()
            ->with('managedFile')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($anexos as $anexo) {
            $result['processed']++;
            $wasConsistent = $this->isConsistent($anexo);

            if (! $this->binaryExists($anexo)) {
                $result['missing']++;
                if ($apply && $anexo->managedFile instanceof ManagedFile) {
                    $this->states->markIntegrity($anexo->managedFile, FileIntegrityStatus::Missing);
                }

                continue;
            }

            if (! $apply) {
                if ($wasConsistent) {
                    $result['consistent']++;
                } else {
                    $result['pending']++;
                }

                continue;
            }

            try {
                $managed = $this->catalog->synchronize($anexo);
                if (! $managed instanceof ManagedFile) {
                    $result['pending']++;

                    continue;
                }

                if ($managed->integrity_status === FileIntegrityStatus::Corrupted) {
                    $result['integrity_errors']++;
                }

                if ($anexo->trashed() && ! in_array($managed->lifecycle_status, [FileLifecycleStatus::Trashed, FileLifecycleStatus::Purged], true)) {
                    $managed = $this->lifecycle->trash(
                        $managed,
                        null,
                        'Sincronização de anexo financeiro previamente excluído.',
                        null,
                        'reconciliation'
                    );
                } elseif (! $anexo->trashed() && $managed->lifecycle_status === FileLifecycleStatus::Trashed) {
                    $anexo->delete();
                } elseif ($managed->lifecycle_status === FileLifecycleStatus::Purged) {
                    $anexo->forceDelete();
                }

                if ($wasConsistent) {
                    $result['consistent']++;
                } else {
                    $result['repaired']++;
                }
            } catch (\Throwable $exception) {
                $result['failed']++;
                report($exception);
            }
        }

        $this->reconcileOrphans($apply, $result, $limit);
        $result['duplicates'] = $this->duplicateSourceCount();

        return $result;
    }

    /** @param array<string, int|string> $result */
    private function reconcileOrphans(bool $apply, array &$result, int $limit): void
    {
        $aliases = ManagedFileLegacyAlias::query()
            ->with('file')
            ->where('source_table', 'financeiro_anexos')
            ->whereNull('retired_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $sourceIds = $aliases
            ->pluck('source_record_id')
            ->filter(static fn (mixed $id): bool => ctype_digit((string) $id) && (int) $id > 0)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $existingIds = FinanceiroAnexo::withTrashed()
            ->whereIn('id', $sourceIds)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->flip();
        $processedFiles = [];

        foreach ($aliases as $alias) {
            $sourceId = ctype_digit((string) $alias->source_record_id) ? (int) $alias->source_record_id : 0;
            if ($sourceId > 0 && $existingIds->has($sourceId)) {
                continue;
            }

            $file = $alias->file;
            if (! $file instanceof ManagedFile || (string) $file->category !== FileCategory::FinanceiroAnexo->value) {
                continue;
            }
            if (! isset($processedFiles[$file->id])) {
                $result['orphaned']++;
                $processedFiles[$file->id] = true;
            }
            if (! $apply) {
                continue;
            }

            DB::transaction(function () use ($alias, $file, &$result): void {
                $lockedAlias = ManagedFileLegacyAlias::query()->lockForUpdate()->findOrFail($alias->id);
                $lockedFile = ManagedFile::query()->lockForUpdate()->findOrFail($file->id);

                if (! in_array($lockedFile->lifecycle_status, [FileLifecycleStatus::Trashed, FileLifecycleStatus::Purged], true)) {
                    $this->states->trash(
                        $lockedFile,
                        null,
                        'Origem financeira ausente durante reconciliação.',
                        null
                    );
                }
                $result['unlinked'] += DB::table('managed_file_links')
                    ->where('file_id', $lockedFile->id)
                    ->whereNull('unlinked_at')
                    ->update(['is_current' => false, 'unlinked_at' => now()]);
                if ($lockedAlias->retired_at === null) {
                    $lockedAlias->forceFill(['retired_at' => now()])->save();
                    $result['retired_aliases']++;
                }
            }, attempts: 3);
        }
    }

    private function isConsistent(FinanceiroAnexo $anexo): bool
    {
        $managed = $anexo->managedFile;
        if (! $managed instanceof ManagedFile) {
            return false;
        }
        if ((string) $managed->category !== FileCategory::FinanceiroAnexo->value) {
            return false;
        }

        try {
            $path = FilePathGuard::normalizeRelativePath((string) $anexo->arquivo);
        } catch (\InvalidArgumentException) {
            return false;
        }
        if ((string) $managed->storage_disk !== 'local' || (string) $managed->storage_key !== $path) {
            return false;
        }

        $hasLink = DB::table('managed_file_links')
            ->where('file_id', $managed->id)
            ->where('subject_type', 'financeiro')
            ->where('subject_id', (int) $anexo->financeiro_id)
            ->where('relation', 'anexo:'.(int) $anexo->id)
            ->where('is_current', true)
            ->whereNull('unlinked_at')
            ->exists();
        $hasAlias = DB::table('managed_file_legacy_aliases')
            ->where('file_id', $managed->id)
            ->where('source_table', 'financeiro_anexos')
            ->where('source_record_id', (string) $anexo->id)
            ->whereNull('retired_at')
            ->exists();

        return $hasLink && $hasAlias;
    }

    private function binaryExists(FinanceiroAnexo $anexo): bool
    {
        try {
            $path = FilePathGuard::normalizeRelativePath((string) $anexo->arquivo);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return Storage::disk('local')->exists($path);
    }

    private function duplicateSourceCount(): int
    {
        $duplicateSources = DB::table('managed_file_legacy_aliases')
            ->select('source_record_id')
            ->where('source_table', 'financeiro_anexos')
            ->whereNull('retired_at')
            ->whereNotNull('source_record_id')
            ->groupBy('source_record_id')
            ->havingRaw('COUNT(*) > 1');

        return (int) DB::query()
            ->fromSub($duplicateSources, 'duplicate_financeiro_anexo_sources')
            ->count();
    }

    private function schemaReady(): bool
    {
        return Schema::hasTable('financeiro_anexos')
            && Schema::hasTable('managed_files')
            && Schema::hasColumn('financeiro_anexos', 'managed_file_uuid')
            && Schema::hasColumn('financeiro_anexos', 'deleted_at');
    }
}
