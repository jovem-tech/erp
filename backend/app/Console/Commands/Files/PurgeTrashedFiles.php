<?php

namespace App\Console\Commands\Files;

use App\Enums\Files\FileIntegrityStatus;
use App\Enums\Files\FileLifecycleStatus;
use App\Models\Files\ManagedFile;
use App\Services\Files\FileTrashRetentionPolicy;
use App\Services\Files\ManagedFilePurgeService;
use Illuminate\Console\Command;

class PurgeTrashedFiles extends Command
{
    protected $signature = 'file-manager:purge-trash {--limit= : Limite máximo desta execução}';

    protected $description = 'Exclui definitivamente binários cujo prazo configurado na lixeira expirou.';

    public function handle(FileTrashRetentionPolicy $policy, ManagedFilePurgeService $purger): int
    {
        if (! (bool) config('file-manager.kill_switches.allow_permanent_deletion', false)) {
            $this->info('Exclusão definitiva desabilitada pelo kill switch.');

            return self::SUCCESS;
        }

        $settings = $policy->settings();
        if (! $settings['enabled']) {
            $this->info('Retenção automática da lixeira desativada.');

            return self::SUCCESS;
        }

        $limit = (int) ($this->option('limit') ?: config('file-manager.retention.purge_batch_size', 250));
        $limit = max(1, min(1000, $limit));
        $cutoff = now()->subDays($settings['days']);
        $files = ManagedFile::query()
            ->where('lifecycle_status', FileLifecycleStatus::Trashed->value)
            // Registros sem conteúdo pertencem à coleção de auditoria e não
            // seguem o prazo operacional da lixeira.
            ->where('integrity_status', '!=', FileIntegrityStatus::Missing->value)
            ->whereNotNull('trashed_at')
            ->where('trashed_at', '<=', $cutoff)
            ->orderBy('trashed_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $purged = 0;
        $failed = 0;
        foreach ($files as $file) {
            try {
                $purger->purge(
                    $file,
                    null,
                    'Retenção automática da lixeira expirada.',
                    $settings['configured_by'],
                    'scheduled_retention',
                    $settings['days']
                );
                $purged++;
            } catch (\Throwable $exception) {
                report($exception);
                $failed++;
                $this->warn('Falha ao expurgar arquivo '.$file->uuid.'.');
            }
        }

        $this->info(sprintf(
            'Purga concluída: %d removido(s), %d falha(s), corte %s.',
            $purged,
            $failed,
            $cutoff->toIso8601String()
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
