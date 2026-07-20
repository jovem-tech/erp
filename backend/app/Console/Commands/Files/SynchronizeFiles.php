<?php

namespace App\Console\Commands\Files;

use App\Models\Files\FileScanRun;
use App\Services\Files\AutomaticFileSyncService;
use Illuminate\Console\Command;

class SynchronizeFiles extends Command
{
    protected $signature = 'file-manager:sync
        {--root=* : Executa somente nas roots allowlisted informadas}
        {--status : Mostra somente o estado seguro da ultima sincronizacao}
        {--pending : Processa uma solicitacao manual pendente, se existir}';

    protected $description = 'Descobre e cataloga automaticamente arquivos novos sem mover os binarios de origem.';

    public function handle(AutomaticFileSyncService $synchronizer): int
    {
        if ((bool) $this->option('status')) {
            return $this->showStatus();
        }

        try {
            if ((bool) $this->option('pending')) {
                $result = $synchronizer->synchronizePendingRequest();
            } else {
                $roots = array_values(array_filter(
                    (array) $this->option('root'),
                    static fn (mixed $root): bool => is_string($root) && trim($root) !== ''
                ));
                $result = $synchronizer->synchronize($roots !== [] ? $roots : null);
            }
        } catch (\Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return in_array($result['status'] ?? null, [
            'disabled',
            'locked',
            'completed',
            'no_request',
            'satisfied_by_automatic_sync',
        ], true)
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function showStatus(): int
    {
        $run = FileScanRun::query()
            ->whereIn('process_name', ['automatic_sync', 'manual_sync'])
            ->latest('id')
            ->first();
        $checkpoint = (array) ($run?->checkpoint_json ?? []);
        $result = [
            'enabled' => (bool) config('file-manager.automatic_sync.enabled', false),
            'interval_minutes' => (int) config('file-manager.automatic_sync.interval_minutes', 5),
            'last_run' => $run === null ? null : [
                'uuid' => (string) $run->uuid,
                'status' => (string) $run->status,
                'processed' => (int) $run->processed_count,
                'findings' => (int) $run->finding_count,
                'cataloged' => (int) ($checkpoint['cataloged'] ?? 0),
                'skipped' => (int) $run->skipped_count,
                'failed' => (int) $run->failed_count,
                'skipped_roots' => (int) ($checkpoint['skipped_roots'] ?? 0),
                'partial_roots' => (int) ($checkpoint['partial_roots'] ?? 0),
                'failed_roots' => (int) ($checkpoint['failed_roots'] ?? 0),
                'started_at' => $run->started_at?->toIso8601String(),
                'completed_at' => $run->completed_at?->toIso8601String(),
            ],
        ];

        $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $run?->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
