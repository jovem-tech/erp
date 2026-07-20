<?php

namespace App\Services\Files;

use App\Models\Files\FileScanFinding;
use App\Models\Files\FileScanRun;
use App\Models\Files\ManagedFile;
use App\Models\Files\ManagedFileLegacyAlias;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileScanService
{
    public function __construct(private readonly FileManagerConfiguration $configuration) {}

    public function scan(string $rootAlias, ?int $limit = null, ?int $maxDepth = null): FileScanRun
    {
        if (! (bool) config('file-manager.kill_switches.allow_scanner', false)) {
            throw new \RuntimeException('Scanner desabilitado pelo kill switch.');
        }

        $this->configuration->assertValid();
        $root = $this->configuration->scannerRoot($rootAlias);
        $limit = max(1, min(100_000, $limit ?? (int) config('file-manager.scanner.default_limit', 1000)));
        $maxDepth = max(1, min(64, $maxDepth ?? (int) config('file-manager.scanner.max_depth', 12)));
        $timeoutSeconds = max(5, (int) config('file-manager.scanner.timeout_seconds', 60));
        $pauseMicroseconds = max(0, min(500_000, (int) config('file-manager.scanner.pause_milliseconds', 0) * 1000));
        $disk = Storage::disk($root['disk']);
        $diskBase = realpath($disk->path(''));
        $absoluteRoot = realpath($disk->path($root['path']));

        if ($diskBase === false || $absoluteRoot === false || ! $this->isWithin($absoluteRoot, $diskBase)) {
            throw new \RuntimeException('Root inexistente ou fora do disco autorizado.');
        }

        $run = FileScanRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'process_name' => 'scan',
            'mode' => 'dry_run',
            'roots_fingerprint' => hash('sha256', $root['disk']."\0".$root['path']),
            'status' => 'running',
            'started_at' => now(),
            'heartbeat_at' => now(),
        ]);

        $startedAt = microtime(true);
        $stack = [[$absoluteRoot, 0]];
        $processed = 0;
        $skipped = 0;
        $failed = 0;
        $findingCount = 0;
        $processedBytes = 0;
        $extensionCounts = [];
        $status = 'completed';

        try {
            while ($stack !== [] && $processed < $limit) {
                if ((microtime(true) - $startedAt) >= $timeoutSeconds) {
                    $status = 'interrupted';
                    break;
                }

                [$directory, $depth] = array_pop($stack);

                try {
                    $iterator = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
                } catch (\UnexpectedValueException) {
                    $failed++;
                    $findingCount += $this->finding($run, 'permission_denied', 'high', $directory);

                    continue;
                }

                foreach ($iterator as $entry) {
                    if ($processed >= $limit) {
                        $status = 'interrupted';
                        break 2;
                    }

                    $path = $entry->getPathname();
                    if ($entry->isLink()) {
                        $skipped++;
                        $findingCount += $this->finding($run, 'symlink', 'medium', $path);

                        continue;
                    }

                    if ($entry->isDir()) {
                        if ($depth < $maxDepth) {
                            $stack[] = [$path, $depth + 1];
                        } else {
                            $skipped++;
                        }

                        continue;
                    }

                    if (! $entry->isFile()) {
                        $skipped++;
                        $findingCount += $this->finding($run, 'special_file', 'high', $path);

                        continue;
                    }

                    $before = @stat($path);
                    $relativeToRoot = ltrim(str_replace('\\', '/', substr($path, strlen($absoluteRoot))), '/');
                    $storageKey = FilePathGuard::normalizeRelativePath($root['path'].'/'.$relativeToRoot);
                    $extension = strtolower((string) pathinfo($storageKey, PATHINFO_EXTENSION));
                    $fileSize = $entry->getSize();
                    $processedBytes += $fileSize;
                    $extensionCounts[$extension !== '' ? $extension : '(sem-extensao)'] = ($extensionCounts[$extension !== '' ? $extension : '(sem-extensao)'] ?? 0) + 1;
                    $managed = ManagedFile::query()
                        ->where('storage_disk', $root['disk'])
                        ->where('storage_key', $storageKey)
                        ->first();

                    if (! $managed instanceof ManagedFile) {
                        $findingCount += $this->finding($run, 'orphan', 'low', $storageKey, [
                            'size_bytes' => $fileSize,
                            'extension' => $extension !== '' ? $extension : null,
                            'modified_at' => is_array($before) ? (int) $before['mtime'] : null,
                        ]);
                    }

                    $after = @stat($path);
                    if ($before === false || $after === false || $before['size'] !== $after['size'] || $before['mtime'] !== $after['mtime']) {
                        $findingCount += $this->finding($run, 'changed_during_scan', 'medium', $storageKey);
                    }

                    $processed++;
                    if ($pauseMicroseconds > 0) {
                        usleep($pauseMicroseconds);
                    }
                }

                $run->forceFill([
                    'processed_count' => $processed,
                    'skipped_count' => $skipped,
                    'finding_count' => $findingCount,
                    'failed_count' => $failed,
                    'heartbeat_at' => now(),
                    'checkpoint_json' => [
                        'pending_directories' => count($stack),
                        'processed_bytes' => $processedBytes,
                        'extension_counts' => $extensionCounts,
                    ],
                ])->save();
            }

            ManagedFile::query()
                ->where('storage_disk', $root['disk'])
                ->where('storage_key', 'like', $root['path'].'/%')
                ->orderBy('id')
                ->limit($limit)
                ->get()
                ->each(function (ManagedFile $file) use ($disk, $run, &$findingCount): void {
                    if (! $disk->exists($file->storage_key)) {
                        $findingCount += $this->finding($run, 'missing', 'high', $file->storage_key, [], $file->id);
                    }
                });

            ManagedFileLegacyAlias::query()
                ->where('legacy_disk', $root['disk'])
                ->where('legacy_path', 'like', $root['path'].'/%')
                ->whereNull('retired_at')
                ->orderBy('id')
                ->limit($limit)
                ->get()
                ->each(function (ManagedFileLegacyAlias $alias) use ($disk, $run, &$findingCount): void {
                    if (! $disk->exists($alias->legacy_path)) {
                        $findingCount += $this->finding(
                            $run,
                            'broken_reference',
                            'high',
                            $alias->legacy_path,
                            [],
                            $alias->file_id
                        );
                    }
                });
        } catch (\Throwable $exception) {
            $status = 'failed';
            $failed++;
            report($exception);
            throw $exception;
        } finally {
            $run->forceFill([
                'status' => $status,
                'processed_count' => $processed,
                'skipped_count' => $skipped,
                'finding_count' => $findingCount,
                'failed_count' => $failed,
                'heartbeat_at' => now(),
                'completed_at' => now(),
                'checkpoint_json' => [
                    'pending_directories' => count($stack),
                    'processed_bytes' => $processedBytes,
                    'extension_counts' => $extensionCounts,
                ],
            ])->save();
        }

        return $run->fresh('findings') ?? $run;
    }

    /**
     * @param  array<string, scalar|null>  $evidence
     */
    private function finding(
        FileScanRun $run,
        string $type,
        string $severity,
        string $path,
        array $evidence = [],
        ?int $fileId = null
    ): int {
        $normalizedPath = str_replace('\\', '/', $path);
        $pathHash = hash('sha256', $normalizedPath);
        $existing = FileScanFinding::query()
            ->where('finding_type', $type)
            ->where('path_hash', $pathHash)
            ->whereHas('run', static fn ($query) => $query->where('roots_fingerprint', $run->roots_fingerprint))
            ->latest('id')
            ->first();

        if ($existing instanceof FileScanFinding) {
            if ($existing->resolution_status === 'open') {
                return 0;
            }

            $existingEvidence = (array) ($existing->evidence_json ?? []);
            if (
                $type === 'orphan'
                && in_array($existing->resolution_status, ['acknowledged', 'false_positive'], true)
                && ($existingEvidence['size_bytes'] ?? null) === ($evidence['size_bytes'] ?? null)
                && ($existingEvidence['modified_at'] ?? null) === ($evidence['modified_at'] ?? null)
            ) {
                return 0;
            }
        }

        FileScanFinding::query()->create([
            'scan_run_id' => $run->id,
            'finding_type' => $type,
            'severity' => $severity,
            'path_hash' => $pathHash,
            'restricted_path' => $normalizedPath,
            'file_id' => $fileId,
            'evidence_json' => $evidence !== [] ? $evidence : null,
            'resolution_status' => 'open',
        ]);

        return 1;
    }

    private function isWithin(string $candidate, string $base): bool
    {
        $candidate = rtrim(str_replace('\\', '/', $candidate), '/');
        $base = rtrim(str_replace('\\', '/', $base), '/');

        return $candidate === $base || str_starts_with($candidate, $base.'/');
    }
}
