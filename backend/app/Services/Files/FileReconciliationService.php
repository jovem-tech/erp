<?php

namespace App\Services\Files;

use App\Enums\Files\FileIntegrityStatus;
use App\Models\Files\FileScanFinding;
use App\Models\Files\ManagedFile;
use App\Models\Files\ManagedFileLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FileReconciliationService
{
    public function __construct(private readonly FileStateMachine $states) {}

    /**
     * @return array{processed: int, missing: int, updated: int}
     */
    public function reconcileMissingBlobs(bool $apply = false, int $limit = 500): array
    {
        if ($apply && ! (bool) config('file-manager.kill_switches.allow_mutating_reconcile', false)) {
            throw new \RuntimeException('Reconciliacao mutavel desabilitada pelo kill switch.');
        }

        $counters = ['processed' => 0, 'missing' => 0, 'updated' => 0];
        ManagedFile::query()
            ->orderBy('id')
            ->limit(max(1, min(5000, $limit)))
            ->get()
            ->each(function (ManagedFile $file) use ($apply, &$counters): void {
                $counters['processed']++;
                if (Storage::disk($file->storage_disk)->exists($file->storage_key)) {
                    return;
                }

                $counters['missing']++;
                if ($apply && $file->integrity_status !== FileIntegrityStatus::Missing) {
                    $this->states->markIntegrity($file, FileIntegrityStatus::Missing);
                    $counters['updated']++;
                }
            });

        return $counters;
    }

    /**
     * @return array<string, int>
     */
    public function reconcile(bool $apply = false, int $limit = 500): array
    {
        $missing = $this->reconcileMissingBlobs($apply, $limit);
        $links = $this->reconcileCurrentLinkConflicts($apply, $limit);

        return [
            ...$missing,
            ...$links,
            'open_orphan_findings' => FileScanFinding::query()
                ->where('finding_type', 'orphan')
                ->where('resolution_status', 'open')
                ->limit(max(1, min(5000, $limit)))
                ->count(),
        ];
    }

    /**
     * @return array{link_conflicts: int, links_updated: int}
     */
    private function reconcileCurrentLinkConflicts(bool $apply, int $limit): array
    {
        $counters = ['link_conflicts' => 0, 'links_updated' => 0];
        $conflicts = ManagedFileLink::query()
            ->select(['subject_type', 'subject_id', 'relation'])
            ->where('is_current', true)
            ->groupBy('subject_type', 'subject_id', 'relation')
            ->havingRaw('COUNT(*) > 1')
            ->limit(max(1, min(5000, $limit)))
            ->get();

        foreach ($conflicts as $conflict) {
            $counters['link_conflicts']++;
            if (! $apply) {
                continue;
            }

            DB::transaction(function () use ($conflict, &$counters): void {
                $links = ManagedFileLink::query()
                    ->where('subject_type', $conflict->subject_type)
                    ->where('subject_id', $conflict->subject_id)
                    ->where('relation', $conflict->relation)
                    ->where('is_current', true)
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->get();

                $keepId = $links->first()?->id;
                if ($keepId === null) {
                    return;
                }

                $updated = ManagedFileLink::query()
                    ->whereIn('id', $links->pluck('id')->reject(static fn (int $id): bool => $id === $keepId))
                    ->update(['is_current' => false, 'unlinked_at' => now()]);
                $counters['links_updated'] += $updated;
            }, attempts: 3);
        }

        return $counters;
    }
}
