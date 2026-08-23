<?php

namespace App\Console\Commands\Backups;

use App\Enums\Backups\BackupOrigin;
use App\Enums\Backups\BackupStatus;
use App\Enums\Backups\BackupType;
use App\Models\Backups\Backup;
use App\Services\Backups\BackupPassphraseResolver;
use App\Services\Backups\BackupRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class RunBackup extends Command
{
    protected $signature = 'backup:executar
        {--pendente : Executa apenas backups que já estão na fila}
        {--tipo=completo : Tipo do backup a gerar}
        {--frase= : Frase secreta (use apenas em modo manual, via terminal)}';

    protected $description = 'Gera um backup completo do sistema (bancos, arquivos e configuração).';

    public function handle(BackupRunner $runner, BackupPassphraseResolver $resolver): int
    {
        if (! (bool) config('backup.enabled', true)) {
            $this->warn('O sistema de backup está desligado (BACKUP_ENABLED=false).');

            return self::SUCCESS;
        }

        $pending = $this->option('pendente')
            ? $this->claimPending()
            : [$this->createRun()];

        if ($pending === []) {
            return self::SUCCESS;
        }

        $failures = 0;
        $warnings = 0;

        foreach ($pending as $backup) {
            try {
                $passphrase = $this->resolvePassphrase($resolver);
                $result = $runner->run($backup, $passphrase, $resolver);

                if ($result->status === BackupStatus::ConcluidoComAvisos) {
                    $warnings++;
                    $this->warn(sprintf(
                        'Backup %s concluído com %d aviso(s): %s',
                        (string) $result->arquivo_nome,
                        count($result->warnings()),
                        $this->formatSize((int) $result->tamanho_bytes),
                    ));

                    foreach ($result->warnings() as $warning) {
                        $this->line('  - '.(string) ($warning['mensagem'] ?? ''));
                    }
                } else {
                    $this->info(sprintf(
                        'Backup %s concluído: %s em %ds.',
                        (string) $result->arquivo_nome,
                        $this->formatSize((int) $result->tamanho_bytes),
                        (int) $result->duracao_segundos,
                    ));
                }
            } catch (Throwable $exception) {
                $failures++;
                $this->error('Falha ao gerar o backup: '.$exception->getMessage());
            }
        }

        // Saidas distintas de proposito: cron e monitoracao precisam separar
        // "rodou com ressalvas" de "nao rodou".
        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<int, Backup> */
    private function claimPending(): array
    {
        return Backup::query()
            ->where('status', BackupStatus::Pendente->value)
            ->orderBy('id')
            ->limit(1)
            ->get()
            ->all();
    }

    private function createRun(): Backup
    {
        $type = BackupType::tryFrom((string) $this->option('tipo')) ?? BackupType::Completo;

        return Backup::query()->create([
            'uuid' => (string) Str::uuid(),
            'tipo' => $type->value,
            'origem' => BackupOrigin::Agendado->value,
            'status' => BackupStatus::Pendente->value,
            'gerenciado' => true,
            'etapa_atual' => 'Na fila',
        ]);
    }

    private function resolvePassphrase(BackupPassphraseResolver $resolver): string
    {
        $manual = trim((string) $this->option('frase'));

        if ($manual !== '') {
            if (! $resolver->verify($manual)) {
                throw new \RuntimeException('A frase secreta informada não confere com a cadastrada.');
            }

            return $manual;
        }

        return $resolver->resolveForUnattended();
    }

    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return sprintf('%.1f %s', $value, $units[$index]);
    }
}
