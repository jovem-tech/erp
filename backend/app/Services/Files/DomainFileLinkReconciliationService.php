<?php

namespace App\Services\Files;

use App\Enums\Files\FileCategory;
use App\Enums\Files\ManagedFileAction;
use App\Models\Files\ManagedFile;
use App\Models\Files\ManagedFileLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reconcilia arquivos catalogados pelo scanner com os registros de dominio.
 *
 * A associacao e feita exclusivamente por caminhos normalizados e roots
 * allowlisted. Um arquivo com mais de um possivel dono permanece sem vinculo:
 * uma falsa associacao poderia conceder acesso ao documento de outro cliente.
 */
class DomainFileLinkReconciliationService
{
    private const SUPPORTED_CATEGORIES = [
        FileCategory::EquipmentPhoto->value,
        FileCategory::OrderPhoto->value,
        FileCategory::OrderPdf->value,
    ];

    public function __construct(
        private readonly LegacyFileResolver $legacyResolver,
        private readonly ManagedFileEventRecorder $events
    ) {}

    /**
     * @return array{processed_sources:int, matched_files:int, linked_files:int, created_links:int, reactivated_links:int, metadata_updated:int, ambiguous_sources:int, ambiguous_files:int, unmatched_sources:int}
     */
    public function reconcile(bool $apply = false, int $limit = 10_000): array
    {
        if ($apply && ! (bool) config('file-manager.kill_switches.allow_mutating_reconcile', false)) {
            throw new \RuntimeException('Reconciliacao mutavel desabilitada pelo kill switch.');
        }

        $limit = max(1, min(100_000, $limit));
        $counters = [
            'processed_sources' => 0,
            'matched_files' => 0,
            'linked_files' => 0,
            'created_links' => 0,
            'reactivated_links' => 0,
            'metadata_updated' => 0,
            'ambiguous_sources' => 0,
            'ambiguous_files' => 0,
            'unmatched_sources' => 0,
        ];

        $files = ManagedFile::query()
            ->with(['links' => static fn ($query) => $query->whereNull('unlinked_at')])
            ->whereIn('category', self::SUPPORTED_CATEGORIES)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($files->isEmpty()) {
            return $counters;
        }

        $fileIndex = $this->buildFileIndex($files);
        $filesById = $files->keyBy(static fn (ManagedFile $file): int => (int) $file->id);
        $proposals = [];

        $this->collectEquipmentPhotoProposals($fileIndex, $proposals, $counters, $limit);
        $this->collectOrderPhotoProposals($fileIndex, $proposals, $counters, $limit);
        $this->collectOrderDocumentFileProposals($fileIndex, $proposals, $counters, $limit);
        $this->collectOrderDocumentProposals($fileIndex, $proposals, $counters, $limit);

        foreach ($proposals as $fileId => $fileProposals) {
            /** @var ManagedFile|null $file */
            $file = $filesById->get((int) $fileId);
            if (! $file instanceof ManagedFile) {
                continue;
            }

            $proposedSubjects = collect($fileProposals)
                ->map(static fn (array $proposal): string => $proposal['subject_type'].':'.$proposal['subject_id'])
                ->unique();
            $activeSubjects = $file->links
                ->map(static fn (ManagedFileLink $link): string => (string) $link->subject_type.':'.(int) $link->subject_id)
                ->unique();

            if ($proposedSubjects->merge($activeSubjects)->unique()->count() > 1) {
                $counters['ambiguous_files']++;

                continue;
            }

            $counters['matched_files']++;
            if (! $apply) {
                continue;
            }

            $before = $counters['created_links'] + $counters['reactivated_links'];
            foreach ($fileProposals as $proposal) {
                $this->persistProposal($file, $proposal, $counters);
            }

            if (($counters['created_links'] + $counters['reactivated_links']) > $before) {
                $counters['linked_files']++;
            }
        }

        return $counters;
    }

    /**
     * @param  Collection<int, ManagedFile>  $files
     * @return array<string, int>
     */
    private function buildFileIndex(Collection $files): array
    {
        $index = [];
        foreach ($files as $file) {
            try {
                $path = FilePathGuard::normalizeRelativePath((string) $file->storage_key);
            } catch (\InvalidArgumentException) {
                continue;
            }

            $index[$this->indexKey((string) $file->category, (string) $file->storage_disk, $path)] = (int) $file->id;
        }

        return $index;
    }

    /** @param array<string, int> $fileIndex @param array<int, array<string, array<string, mixed>>> $proposals @param array<string, int> $counters */
    private function collectEquipmentPhotoProposals(array $fileIndex, array &$proposals, array &$counters, int $limit): void
    {
        if (! $this->hasSource('equipamentos_fotos', ['id', 'equipamento_id', 'arquivo'])) {
            return;
        }

        $rows = DB::table('equipamentos_fotos')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'equipamento_id', 'arquivo', 'created_at']);

        foreach ($rows as $row) {
            $this->propose(
                $fileIndex,
                $proposals,
                $counters,
                FileCategory::EquipmentPhoto->value,
                $this->equipmentPhotoCandidates((string) $row->arquivo),
                'equipment',
                (int) $row->equipamento_id,
                'photo:'.(int) $row->id,
                'equipamentos_fotos',
                (int) $row->id,
                $row->created_at ?? null
            );
        }
    }

    /** @param array<string, int> $fileIndex @param array<int, array<string, array<string, mixed>>> $proposals @param array<string, int> $counters */
    private function collectOrderPhotoProposals(array $fileIndex, array &$proposals, array &$counters, int $limit): void
    {
        if (! $this->hasSource('os_fotos', ['id', 'os_id', 'arquivo', 'tipo'])) {
            return;
        }

        $rows = DB::table('os_fotos')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'os_id', 'arquivo', 'tipo', 'created_at']);

        foreach ($rows as $row) {
            $this->propose(
                $fileIndex,
                $proposals,
                $counters,
                FileCategory::OrderPhoto->value,
                $this->orderPhotoCandidates((string) $row->arquivo, (string) $row->tipo),
                'order',
                (int) $row->os_id,
                'photo:'.(int) $row->id,
                'os_fotos',
                (int) $row->id,
                $row->created_at ?? null
            );
        }
    }

    /** @param array<string, int> $fileIndex @param array<int, array<string, array<string, mixed>>> $proposals @param array<string, int> $counters */
    private function collectOrderDocumentFileProposals(array $fileIndex, array &$proposals, array &$counters, int $limit): void
    {
        if (
            ! $this->hasSource('os_documento_arquivos', ['id', 'documento_id', 'arquivo'])
            || ! $this->hasSource('os_documentos', ['id', 'os_id'])
        ) {
            return;
        }

        $rows = DB::table('os_documento_arquivos as arquivos')
            ->join('os_documentos as documentos', 'documentos.id', '=', 'arquivos.documento_id')
            ->orderBy('arquivos.id')
            ->limit($limit)
            ->get([
                'arquivos.id',
                'documentos.os_id',
                'arquivos.arquivo',
                'arquivos.created_at as source_created_at',
                'documentos.created_at as parent_created_at',
            ]);

        foreach ($rows as $row) {
            $this->propose(
                $fileIndex,
                $proposals,
                $counters,
                FileCategory::OrderPdf->value,
                $this->orderDocumentCandidates((string) $row->arquivo),
                'order',
                (int) $row->os_id,
                'document_file:'.(int) $row->id,
                'os_documento_arquivos',
                (int) $row->id,
                $row->source_created_at ?? $row->parent_created_at ?? null
            );
        }
    }

    /** @param array<string, int> $fileIndex @param array<int, array<string, array<string, mixed>>> $proposals @param array<string, int> $counters */
    private function collectOrderDocumentProposals(array $fileIndex, array &$proposals, array &$counters, int $limit): void
    {
        if (! $this->hasSource('os_documentos', ['id', 'os_id', 'arquivo'])) {
            return;
        }

        $rows = DB::table('os_documentos')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'os_id', 'arquivo', 'created_at']);

        foreach ($rows as $row) {
            $this->propose(
                $fileIndex,
                $proposals,
                $counters,
                FileCategory::OrderPdf->value,
                $this->orderDocumentCandidates((string) $row->arquivo),
                'order',
                (int) $row->os_id,
                'document:'.(int) $row->id,
                'os_documentos',
                (int) $row->id,
                $row->created_at ?? null
            );
        }
    }

    /**
     * @param  array<string, int>  $fileIndex
     * @param  array<int, array<string, array<string, mixed>>>  $proposals
     * @param  array<string, int>  $counters
     * @param  array<int, array{disk:string,path:string}>  $candidates
     */
    private function propose(
        array $fileIndex,
        array &$proposals,
        array &$counters,
        string $category,
        array $candidates,
        string $subjectType,
        int $subjectId,
        string $relation,
        string $sourceTable,
        int $sourceRecordId,
        mixed $sourceCreatedAt
    ): void {
        $counters['processed_sources']++;
        if ($subjectId <= 0 || $sourceRecordId <= 0 || $candidates === []) {
            $counters['unmatched_sources']++;

            return;
        }

        $matches = [];
        foreach ($candidates as $candidate) {
            $fileId = $fileIndex[$this->indexKey($category, $candidate['disk'], $candidate['path'])] ?? null;
            if (is_int($fileId) && $fileId > 0) {
                $matches[$fileId] = [
                    'file_id' => $fileId,
                    'legacy_disk' => $candidate['disk'],
                    'legacy_path' => $candidate['path'],
                ];
            }
        }

        if ($matches === []) {
            $counters['unmatched_sources']++;

            return;
        }
        if (count($matches) > 1) {
            $counters['ambiguous_sources']++;

            return;
        }

        $match = array_values($matches)[0];
        $sourceDate = $this->normalizeSourceDate($sourceCreatedAt);
        $identity = $subjectType.':'.$subjectId.':'.$relation;
        $proposals[$match['file_id']][$identity] = [
            ...$match,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'relation' => $relation,
            'source_table' => $sourceTable,
            'source_record_id' => (string) $sourceRecordId,
            'source_created_at' => $sourceDate,
        ];
    }

    /** @param array<string, mixed> $proposal @param array<string, int> $counters */
    private function persistProposal(ManagedFile $file, array $proposal, array &$counters): void
    {
        DB::transaction(function () use ($file, $proposal, &$counters): void {
            ManagedFile::query()->whereKey($file->id)->lockForUpdate()->firstOrFail();

            ManagedFileLink::query()
                ->where('subject_type', $proposal['subject_type'])
                ->where('subject_id', $proposal['subject_id'])
                ->where('relation', $proposal['relation'])
                ->where('file_id', '!=', $file->id)
                ->where('is_current', true)
                ->update(['is_current' => false, 'unlinked_at' => now()]);

            $link = ManagedFileLink::query()->firstOrNew([
                'file_id' => $file->id,
                'subject_type' => $proposal['subject_type'],
                'subject_id' => $proposal['subject_id'],
                'relation' => $proposal['relation'],
            ]);
            $wasPersisted = $link->exists;
            $wasActive = $wasPersisted && (bool) $link->is_current && $link->unlinked_at === null;
            $metadata = array_merge((array) ($link->metadata_json ?? []), array_filter([
                'reconciled_from' => $proposal['source_table'],
                'source_record_id' => $proposal['source_record_id'],
                'source_created_at' => $proposal['source_created_at'],
            ], static fn (mixed $value): bool => $value !== null && $value !== ''));

            $link->forceFill([
                'is_current' => true,
                'unlinked_at' => null,
                'metadata_json' => $metadata,
            ]);

            if (! $wasPersisted) {
                $link->created_by = null;
                $link->save();
                $counters['created_links']++;
            } elseif (! $wasActive) {
                $link->save();
                $counters['reactivated_links']++;
            } elseif ($link->isDirty('metadata_json')) {
                $link->save();
                $counters['metadata_updated']++;
            }

            $alias = $this->legacyResolver->addAlias(
                $file,
                $proposal['legacy_disk'],
                $proposal['legacy_path'],
                $proposal['source_table'],
                'arquivo',
                $proposal['source_record_id']
            );
            if ((int) $alias->file_id === (int) $file->id) {
                $alias->forceFill([
                    'source_table' => $proposal['source_table'],
                    'source_column' => 'arquivo',
                    'source_record_id' => $proposal['source_record_id'],
                ]);
                if ($alias->isDirty()) {
                    $alias->save();
                }
            }

            if (! $wasActive) {
                $this->events->record(
                    ManagedFileAction::Linked,
                    'success',
                    $file,
                    null,
                    (string) $file->category,
                    ['relation' => $proposal['relation'], 'origin' => 'domain_reconciliation']
                );
            }
        }, attempts: 3);
    }

    /** @return array<int, array{disk:string,path:string}> */
    private function equipmentPhotoCandidates(string $path): array
    {
        $normalized = $this->normalizeSourcePath($path);
        if ($normalized === null) {
            return [];
        }

        $candidates = [['disk' => 'local', 'path' => $normalized]];
        if (str_starts_with($normalized, 'uploads/')) {
            $candidates[] = ['disk' => 'legacy_public', 'path' => $normalized];
        } else {
            $candidates[] = ['disk' => 'legacy_public', 'path' => 'uploads/equipamentos_perfil/'.$normalized];
            $candidates[] = ['disk' => 'legacy_public', 'path' => 'uploads/equipamentos/'.$normalized];
        }

        return $this->uniqueCandidates($candidates);
    }

    /** @return array<int, array{disk:string,path:string}> */
    private function orderPhotoCandidates(string $path, string $type): array
    {
        $normalized = $this->normalizeSourcePath($path);
        if ($normalized === null) {
            return [];
        }

        $candidates = [['disk' => 'local', 'path' => $normalized]];
        if (str_starts_with($normalized, 'uploads/')) {
            $candidates[] = ['disk' => 'legacy_public', 'path' => $normalized];

            return $this->uniqueCandidates($candidates);
        }

        $folders = mb_strtolower(trim($type)) === 'recepcao'
            ? ['uploads/os_anormalidades', 'uploads/os', 'uploads/os_fotos']
            : ['uploads/os', 'uploads/os_anormalidades', 'uploads/os_fotos'];
        foreach ($folders as $folder) {
            $candidates[] = ['disk' => 'legacy_public', 'path' => $folder.'/'.$normalized];
            if (basename($normalized) !== $normalized) {
                $candidates[] = ['disk' => 'legacy_public', 'path' => $folder.'/'.basename($normalized)];
            }
        }

        return $this->uniqueCandidates($candidates);
    }

    /** @return array<int, array{disk:string,path:string}> */
    private function orderDocumentCandidates(string $path): array
    {
        $normalized = $this->normalizeSourcePath($path);
        if ($normalized === null) {
            return [];
        }

        $candidates = [['disk' => 'local', 'path' => $normalized]];
        if (str_starts_with($normalized, 'uploads/')) {
            $candidates[] = ['disk' => 'legacy_public', 'path' => $normalized];
        } else {
            $candidates[] = ['disk' => 'legacy_public', 'path' => 'uploads/os_documentos/'.$normalized];
            if (basename($normalized) !== $normalized) {
                $candidates[] = ['disk' => 'legacy_public', 'path' => 'uploads/os_documentos/'.basename($normalized)];
            }
        }

        return $this->uniqueCandidates($candidates);
    }

    private function normalizeSourcePath(string $path): ?string
    {
        try {
            return FilePathGuard::normalizeRelativePath($path);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @param array<int, array{disk:string,path:string}> $candidates @return array<int, array{disk:string,path:string}> */
    private function uniqueCandidates(array $candidates): array
    {
        $unique = [];
        foreach ($candidates as $candidate) {
            try {
                $path = FilePathGuard::normalizeRelativePath($candidate['path']);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $unique[$candidate['disk']."\0".$path] = ['disk' => $candidate['disk'], 'path' => $path];
        }

        return array_values($unique);
    }

    /** @param array<int, string> $columns */
    private function hasSource(string $table, array $columns): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeSourceDate(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value, (string) config('app.timezone'))->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function indexKey(string $category, string $disk, string $path): string
    {
        return $category."\0".$disk."\0".$path;
    }
}
