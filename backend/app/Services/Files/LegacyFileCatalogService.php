<?php

namespace App\Services\Files;

use App\Contracts\Files\FileCatalog;
use App\DTO\Files\FileContext;
use App\DTO\Files\FileDescriptor;
use App\DTO\Files\StoredFileResult;
use App\Enums\Files\FileCategory;
use App\Enums\Files\FileOrigin;
use App\Models\Files\FileScanFinding;
use App\Models\Files\FileScanRun;
use App\Models\Files\ManagedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LegacyFileCatalogService
{
    /** @var array<int, string> */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        private readonly FileManagerConfiguration $configuration,
        private readonly FilePolicyRegistry $policies,
        private readonly FileCatalog $catalog,
        private readonly LegacyFileResolver $legacyResolver
    ) {}

    /**
     * @return array{run_uuid: string, mode: string, processed: int, candidates: int, cataloged: int, already_cataloged: int, skipped: int, failed: int, categories: array<string, int>}
     */
    public function catalog(
        bool $apply,
        int $limit = 500,
        ?string $rootAlias = null,
        ?FileCategory $categoryOverride = null,
        ?string $resumeRunUuid = null
    ): array {
        if (! (bool) config('file-manager.kill_switches.allow_scanner', false)) {
            throw new \RuntimeException('Catalogacao legada desabilitada pelo kill switch do scanner.');
        }
        if ($apply && ! (bool) config('file-manager.kill_switches.allow_mutating_reconcile', false)) {
            throw new \RuntimeException('Catalogacao legada mutavel desabilitada pelo kill switch.');
        }

        $this->configuration->assertValid();
        $limit = max(1, min(10_000, $limit));
        $roots = $this->rootsByFingerprint();
        $rootFingerprint = null;
        if ($rootAlias !== null && $rootAlias !== '') {
            $root = $this->configuration->scannerRoot($rootAlias);
            $rootFingerprint = hash('sha256', $root['disk']."\0".$root['path']);
        }

        $afterFindingId = 0;
        if ($resumeRunUuid !== null && $resumeRunUuid !== '') {
            $previousRun = FileScanRun::query()
                ->where('uuid', $resumeRunUuid)
                ->where('process_name', 'catalog_legacy')
                ->firstOrFail();
            if ((string) $previousRun->mode !== ($apply ? 'apply' : 'dry_run')) {
                throw new \InvalidArgumentException('O checkpoint pertence a outro modo de catalogacao.');
            }
            $afterFindingId = max(0, (int) (($previousRun->checkpoint_json ?? [])['last_finding_id'] ?? 0));
        }

        $run = FileScanRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'process_name' => 'catalog_legacy',
            'mode' => $apply ? 'apply' : 'dry_run',
            'roots_fingerprint' => hash('sha256', ($rootAlias ?: 'all')."\0".($categoryOverride?->value ?? 'auto')),
            'status' => 'running',
            'started_at' => now(),
            'heartbeat_at' => now(),
        ]);

        $query = FileScanFinding::query()
            ->with('run')
            ->where('finding_type', 'orphan')
            ->where('resolution_status', 'open')
            ->where('id', '>', $afterFindingId)
            ->orderBy('id');
        if ($rootFingerprint !== null) {
            $query->whereHas('run', static fn ($query) => $query->where('roots_fingerprint', $rootFingerprint));
        }

        $result = [
            'run_uuid' => (string) $run->uuid,
            'mode' => $apply ? 'apply' : 'dry_run',
            'processed' => 0,
            'candidates' => 0,
            'cataloged' => 0,
            'already_cataloged' => 0,
            'skipped' => 0,
            'failed' => 0,
            'categories' => [],
        ];
        $lastFindingId = $afterFindingId;

        foreach ($query->limit($limit)->get() as $finding) {
            $lastFindingId = (int) $finding->id;
            $result['processed']++;

            try {
                $root = $roots[(string) $finding->run?->roots_fingerprint] ?? null;
                if (! is_array($root)) {
                    throw new \InvalidArgumentException('Finding sem root allowlisted.');
                }

                $storagePath = FilePathGuard::normalizeRelativePath((string) $finding->restricted_path);
                if (! $this->pathBelongsToRoot($storagePath, $root['path'])) {
                    throw new \InvalidArgumentException('Finding fora da root declarada.');
                }

                $disk = Storage::disk($root['disk']);
                $diskBase = realpath($disk->path(''));
                $absoluteRoot = realpath($disk->path($root['path']));
                $candidatePath = $disk->path($storagePath);
                $absolutePath = realpath($candidatePath);
                if (
                    $diskBase === false
                    || $absoluteRoot === false
                    || $absolutePath === false
                    || ! $this->isWithin($absoluteRoot, $diskBase)
                    || ! $this->isWithin($absolutePath, $absoluteRoot)
                    || $this->containsSymlink($disk->path($root['path']), $candidatePath)
                    || ! is_file($absolutePath)
                    || ! is_readable($absolutePath)
                ) {
                    throw new \InvalidArgumentException('Arquivo legado inseguro ou indisponivel.');
                }

                $category = $categoryOverride ?? $this->inferCategory($root['alias'], $root['path'], $storagePath);
                if (! $category instanceof FileCategory) {
                    throw new \InvalidArgumentException('Categoria nao reconhecida para o arquivo legado.');
                }

                $before = @stat($absolutePath);
                $descriptor = new FileDescriptor($absolutePath, basename($storagePath));
                $validated = $this->policies->validate($descriptor, $category);
                $sha256 = hash_file('sha256', $absolutePath);
                $after = @stat($absolutePath);
                if (
                    ! is_string($sha256)
                    || $before === false
                    || $after === false
                    || $before['size'] !== $after['size']
                    || $before['mtime'] !== $after['mtime']
                ) {
                    throw new \RuntimeException('Arquivo mudou durante a catalogacao.');
                }

                $result['categories'][$category->value] = ($result['categories'][$category->value] ?? 0) + 1;
                $existing = ManagedFile::query()
                    ->where('storage_disk', $root['disk'])
                    ->where('storage_key', $storagePath)
                    ->first();
                if (! $apply) {
                    $result[$existing instanceof ManagedFile ? 'already_cataloged' : 'candidates']++;

                    continue;
                }

                $file = $existing;
                if (! $file instanceof ManagedFile) {
                    $file = $this->catalog->register(
                        $descriptor,
                        new FileContext(
                            category: $category,
                            origin: FileOrigin::Legacy,
                            operationKey: 'legacy-catalog:'.hash('sha256', $root['disk']."\0".$storagePath),
                            metadata: [
                                'migration_process' => 'catalog_legacy',
                                'scanner_root' => $root['alias'],
                                'source_finding_id' => (int) $finding->id,
                            ]
                        ),
                        new StoredFileResult(
                            disk: $root['disk'],
                            storageKey: $storagePath,
                            safeDownloadName: FilePathGuard::safeFileName(basename($storagePath), $validated['extension']),
                            extension: $validated['extension'],
                            detectedMimeType: $validated['detected_mime_type'],
                            sizeBytes: $validated['size_bytes'],
                            sha256: $sha256
                        )
                    );
                    $result['cataloged']++;
                } else {
                    $result['already_cataloged']++;
                }

                $this->legacyResolver->addAlias($file, $root['disk'], $storagePath);
                $finding->forceFill([
                    'file_id' => $file->id,
                    'resolution_status' => 'resolved',
                    'resolved_at' => now(),
                ])->save();
            } catch (\InvalidArgumentException $exception) {
                $result['skipped']++;
                if ($apply) {
                    $finding->forceFill([
                        'evidence_json' => array_merge((array) ($finding->evidence_json ?? []), [
                            'catalog_disposition' => 'rejected_by_policy',
                            'catalog_reason' => $exception->getMessage(),
                        ]),
                        'resolution_status' => 'acknowledged',
                        'resolved_at' => now(),
                    ])->save();
                }
                Log::notice('[FILE_MANAGER] Candidato legado ignorado.', [
                    'finding_id' => (int) $finding->id,
                    'path_hash' => (string) $finding->path_hash,
                    'reason_type' => $exception::class,
                ]);
            } catch (\Throwable $exception) {
                $result['failed']++;
                report($exception);
            }

            $run->forceFill([
                'processed_count' => $result['processed'],
                'skipped_count' => $result['skipped'],
                'failed_count' => $result['failed'],
                'heartbeat_at' => now(),
                'checkpoint_json' => [
                    'last_finding_id' => $lastFindingId,
                    'cataloged' => $result['cataloged'],
                    'already_cataloged' => $result['already_cataloged'],
                    'categories' => $result['categories'],
                ],
            ])->save();
        }

        $run->forceFill([
            'status' => $result['failed'] > 0 ? 'completed_with_errors' : 'completed',
            'processed_count' => $result['processed'],
            'skipped_count' => $result['skipped'],
            'failed_count' => $result['failed'],
            'heartbeat_at' => now(),
            'completed_at' => now(),
            'checkpoint_json' => [
                'last_finding_id' => $lastFindingId,
                'cataloged' => $result['cataloged'],
                'already_cataloged' => $result['already_cataloged'],
                'categories' => $result['categories'],
            ],
        ])->save();

        ksort($result['categories']);

        return $result;
    }

    /** @return array<string, array{alias: string, disk: string, path: string}> */
    private function rootsByFingerprint(): array
    {
        $roots = [];
        foreach ((array) config('file-manager.scanner.roots', []) as $alias => $definition) {
            if (! is_string($alias) || ! is_array($definition)) {
                continue;
            }

            try {
                $root = $this->configuration->scannerRoot($alias);
            } catch (\InvalidArgumentException) {
                continue;
            }

            $roots[hash('sha256', $root['disk']."\0".$root['path'])] = [
                'alias' => $alias,
                'disk' => $root['disk'],
                'path' => $root['path'],
            ];
        }

        return $roots;
    }

    private function inferCategory(string $rootAlias, string $rootPath, string $storagePath): ?FileCategory
    {
        $extension = strtolower((string) pathinfo($storagePath, PATHINFO_EXTENSION));

        return match ($rootAlias) {
            'branding' => str_contains(strtolower(basename($storagePath)), 'logo')
                ? FileCategory::CompanyLogo
                : FileCategory::CompanyLoginBackground,
            'equipment_photos' => FileCategory::EquipmentPhoto,
            'legacy_equipment_profiles', 'legacy_equipment_files' => FileCategory::EquipmentPhoto,
            'order_photos' => in_array($extension, self::IMAGE_EXTENSIONS, true)
                ? FileCategory::OrderPhoto
                : null,
            'order_files' => $extension === 'pdf'
                ? FileCategory::OrderPdf
                : (in_array($extension, self::IMAGE_EXTENSIONS, true) ? FileCategory::OrderPhoto : null),
            'legacy_order_anomalies',
            'legacy_order_state',
            'legacy_order_accessories',
            'legacy_order_checklists' => in_array($extension, self::IMAGE_EXTENSIONS, true)
                ? FileCategory::OrderPhoto
                : null,
            'legacy_order_documents' => $extension === 'pdf' ? FileCategory::OrderPdf : null,
            'budget_documents', 'legacy_budgets' => $extension === 'pdf' ? FileCategory::BudgetPdf : null,
            'signatures' => FileCategory::UserSignature,
            'legacy_users' => in_array($extension, self::IMAGE_EXTENSIONS, true)
                ? FileCategory::UserProfilePhoto
                : null,
            'chat', 'legacy_chat', 'legacy_whatsapp' => FileCategory::ChatAttachment,
            'legacy_system' => str_contains(strtolower(basename($storagePath)), 'logo')
                ? FileCategory::CompanyLogo
                : FileCategory::CompanyLoginBackground,
            'managed' => FileCategory::tryFrom(explode('/', substr($storagePath, strlen($rootPath) + 1), 2)[0] ?? ''),
            default => null,
        };
    }

    private function pathBelongsToRoot(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/').'/');
    }

    private function isWithin(string $candidate, string $base): bool
    {
        $candidate = rtrim(str_replace('\\', '/', $candidate), '/');
        $base = rtrim(str_replace('\\', '/', $base), '/');

        return $candidate === $base || str_starts_with($candidate, $base.'/');
    }

    private function containsSymlink(string $root, string $file): bool
    {
        $relative = ltrim(str_replace('\\', '/', substr($file, strlen($root))), '/');
        $cursor = rtrim($root, '/\\');
        foreach (array_filter(explode('/', $relative), static fn (string $part): bool => $part !== '') as $part) {
            $cursor .= DIRECTORY_SEPARATOR.$part;
            if (is_link($cursor)) {
                return true;
            }
        }

        return false;
    }
}
