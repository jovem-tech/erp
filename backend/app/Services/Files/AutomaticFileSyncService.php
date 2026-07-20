<?php

namespace App\Services\Files;

use App\Models\Files\FileScanRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AutomaticFileSyncService
{
    private const LOCK_KEY = 'file-manager:automatic-sync';

    private const MANUAL_REQUEST_KEY = 'file-manager:manual-sync-request';

    private const MANUAL_REQUEST_TTL_SECONDS = 600;

    public function __construct(
        private readonly FileManagerConfiguration $configuration,
        private readonly FileScanService $scanner,
        private readonly LegacyFileCatalogService $catalog,
        private readonly DomainFileLinkReconciliationService $domainLinks
    ) {}

    /**
     * @param  array<int, string>|null  $rootAliases
     * @return array<string, mixed>
     */
    public function synchronize(?array $rootAliases = null, ?int $startedBy = null, string $trigger = 'automatic'): array
    {
        if (! (bool) config('file-manager.automatic_sync.enabled', false)) {
            return ['status' => 'disabled', 'run_uuid' => null, 'roots' => []];
        }

        $this->configuration->assertValid();
        if (! in_array($trigger, ['automatic', 'manual'], true)) {
            throw new \InvalidArgumentException('Gatilho de sincronizacao invalido.');
        }
        $roots = $this->normalizeRoots($rootAliases ?? (array) config('file-manager.automatic_sync.roots', []));
        $lock = Cache::lock(
            self::LOCK_KEY,
            (int) config('file-manager.automatic_sync.lock_seconds', 3600)
        );

        if (! $lock->get()) {
            return ['status' => 'locked', 'run_uuid' => null, 'roots' => []];
        }

        $run = null;

        try {
            $run = FileScanRun::query()->create([
                'uuid' => Str::uuid()->toString(),
                'process_name' => $trigger === 'manual' ? 'manual_sync' : 'automatic_sync',
                'mode' => 'apply',
                'roots_fingerprint' => hash('sha256', implode("\0", $roots)),
                'status' => 'running',
                'started_by' => $startedBy,
                'started_at' => now(),
                'heartbeat_at' => now(),
            ]);

            $result = [
                'status' => 'completed',
                'trigger' => $trigger,
                'run_uuid' => (string) $run->uuid,
                'processed' => 0,
                'findings' => 0,
                'cataloged' => 0,
                'already_cataloged' => 0,
                'skipped' => 0,
                'failed' => 0,
                'skipped_roots' => 0,
                'partial_roots' => 0,
                'failed_roots' => 0,
                'domain_links' => [],
                'roots' => [],
            ];

            foreach ($roots as $rootAlias) {
                $rootResult = $this->synchronizeRoot($rootAlias);
                $result['roots'][$rootAlias] = $rootResult;
                $result['processed'] += (int) ($rootResult['processed'] ?? 0);
                $result['findings'] += (int) ($rootResult['findings'] ?? 0);
                $result['cataloged'] += (int) ($rootResult['cataloged'] ?? 0);
                $result['already_cataloged'] += (int) ($rootResult['already_cataloged'] ?? 0);
                $result['skipped'] += (int) ($rootResult['skipped'] ?? 0);
                $result['failed'] += (int) ($rootResult['failed'] ?? 0);
                $result['skipped_roots'] += ($rootResult['status'] ?? null) === 'not_found' ? 1 : 0;
                $result['partial_roots'] += ($rootResult['status'] ?? null) === 'partial' ? 1 : 0;
                $result['failed_roots'] += ($rootResult['status'] ?? null) === 'failed' ? 1 : 0;

                $this->persistProgress($run, $result, false);
            }

            $result['domain_links'] = $this->domainLinks->reconcile(
                true,
                (int) config('file-manager.automatic_sync.domain_link_limit', 10_000)
            );

            if ($result['failed'] > 0 || $result['failed_roots'] > 0 || $result['partial_roots'] > 0) {
                $result['status'] = 'completed_with_errors';
            }
            $this->persistProgress($run, $result, true);

            return $result;
        } catch (\Throwable $exception) {
            if ($run instanceof FileScanRun) {
                $run->forceFill([
                    'status' => 'failed',
                    'failed_count' => max(1, (int) $run->failed_count),
                    'heartbeat_at' => now(),
                    'completed_at' => now(),
                ])->save();
            }

            report($exception);
            throw $exception;
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $exception) {
                Log::warning('[FILE_MANAGER] Falha ao liberar lock da sincronizacao; o TTL permanece como protecao.', [
                    'error_type' => $exception::class,
                ]);
                report($exception);
            }
        }
    }

    /** @return array{status: string, queued: bool, requested_at: string} */
    public function requestManualSynchronization(int $actorId): array
    {
        if (! (bool) config('file-manager.automatic_sync.enabled', false)) {
            throw new \RuntimeException('Sincronizacao de arquivos desabilitada.');
        }

        $this->configuration->assertValid();
        $request = [
            'requested_at' => now()->getTimestamp(),
            'requested_by' => $actorId,
        ];
        $queued = Cache::add(self::MANUAL_REQUEST_KEY, $request, self::MANUAL_REQUEST_TTL_SECONDS);

        return [
            'status' => $queued ? 'queued' : 'already_queued',
            'queued' => $queued,
            'requested_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function synchronizePendingRequest(): array
    {
        $request = Cache::pull(self::MANUAL_REQUEST_KEY);
        if (! is_array($request)) {
            return ['status' => 'no_request', 'run_uuid' => null, 'roots' => []];
        }

        $requestedAt = max(0, (int) ($request['requested_at'] ?? 0));
        $actorId = max(0, (int) ($request['requested_by'] ?? 0));
        $lastAutomatic = FileScanRun::query()
            ->where('process_name', 'automatic_sync')
            ->where('status', 'completed')
            ->where('failed_count', 0)
            ->latest('completed_at')
            ->first();
        if ($lastAutomatic?->completed_at?->getTimestamp() >= $requestedAt) {
            return [
                'status' => 'satisfied_by_automatic_sync',
                'run_uuid' => (string) $lastAutomatic->uuid,
                'roots' => [],
            ];
        }

        try {
            $result = $this->synchronize(null, $actorId > 0 ? $actorId : null, 'manual');
        } catch (\Throwable $exception) {
            Cache::put(self::MANUAL_REQUEST_KEY, $request, self::MANUAL_REQUEST_TTL_SECONDS);
            throw $exception;
        }

        if (($result['status'] ?? null) === 'locked') {
            Cache::put(self::MANUAL_REQUEST_KEY, $request, self::MANUAL_REQUEST_TTL_SECONDS);
        }

        return $result;
    }

    /** @return array<string, int|string> */
    private function synchronizeRoot(string $rootAlias): array
    {
        try {
            $root = $this->configuration->scannerRoot($rootAlias);
            if (! Storage::disk($root['disk'])->directoryExists($root['path'])) {
                return [
                    'status' => 'not_found',
                    'processed' => 0,
                    'findings' => 0,
                    'cataloged' => 0,
                    'already_cataloged' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                ];
            }

            $scan = $this->scanner->scan(
                $rootAlias,
                (int) config('file-manager.automatic_sync.scan_limit_per_root', 10_000),
                (int) config('file-manager.automatic_sync.max_depth', 12)
            );
            $catalog = $this->catalog->catalog(
                true,
                (int) config('file-manager.automatic_sync.catalog_limit_per_root', 10_000),
                $rootAlias
            );
            $failed = (int) $scan->failed_count + (int) $catalog['failed'];
            $partial = $scan->status === 'interrupted';

            return [
                'status' => $failed > 0 ? 'failed' : ($partial ? 'partial' : 'completed'),
                'processed' => (int) $scan->processed_count,
                'findings' => (int) $scan->finding_count,
                'cataloged' => (int) $catalog['cataloged'],
                'already_cataloged' => (int) $catalog['already_cataloged'],
                'skipped' => (int) $scan->skipped_count + (int) $catalog['skipped'],
                'failed' => $failed,
            ];
        } catch (\Throwable $exception) {
            Log::error('[FILE_MANAGER] Falha isolada na sincronizacao automatica.', [
                'root_alias' => $rootAlias,
                'error_type' => $exception::class,
            ]);
            report($exception);

            return [
                'status' => 'failed',
                'processed' => 0,
                'findings' => 0,
                'cataloged' => 0,
                'already_cataloged' => 0,
                'skipped' => 0,
                'failed' => 1,
            ];
        }
    }

    /**
     * @param  array<int, mixed>  $roots
     * @return array<int, string>
     */
    private function normalizeRoots(array $roots): array
    {
        $normalized = [];
        foreach ($roots as $rootAlias) {
            if (! is_string($rootAlias) || trim($rootAlias) === '') {
                throw new \InvalidArgumentException('Root de sincronizacao invalida.');
            }

            $rootAlias = trim($rootAlias);
            $this->configuration->scannerRoot($rootAlias);
            $normalized[$rootAlias] = $rootAlias;
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException('Nenhuma root allowlisted para sincronizacao.');
        }

        return array_values($normalized);
    }

    /** @param array<string, mixed> $result */
    private function persistProgress(FileScanRun $run, array $result, bool $completed): void
    {
        $run->forceFill([
            'status' => $completed ? (string) $result['status'] : 'running',
            'processed_count' => (int) $result['processed'],
            'skipped_count' => (int) $result['skipped'],
            'finding_count' => (int) $result['findings'],
            'failed_count' => (int) $result['failed'],
            'heartbeat_at' => now(),
            'completed_at' => $completed ? now() : null,
            'checkpoint_json' => [
                'cataloged' => (int) $result['cataloged'],
                'already_cataloged' => (int) $result['already_cataloged'],
                'skipped_roots' => (int) $result['skipped_roots'],
                'partial_roots' => (int) $result['partial_roots'],
                'failed_roots' => (int) $result['failed_roots'],
                'domain_links' => (array) ($result['domain_links'] ?? []),
                'roots' => $result['roots'],
            ],
        ])->save();
    }
}
