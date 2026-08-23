<?php

namespace App\Console\Commands\Backups;

use App\Models\Backups\Backup;
use App\Services\Backups\BackupVerificationService;
use Illuminate\Console\Command;

class VerifyBackup extends Command
{
    protected $signature = 'backup:verificar {uuid : UUID ou nome do arquivo do backup} {--frase= : Frase secreta, para também provar que o pacote decifra}';

    protected $description = 'Confere a integridade de um backup sem restaurar nada.';

    public function handle(BackupVerificationService $verifier): int
    {
        $reference = (string) $this->argument('uuid');

        $backup = Backup::query()
            ->where('uuid', $reference)
            ->orWhere('arquivo_nome', $reference)
            ->first();

        if ($backup === null) {
            $this->error(sprintf('Backup "%s" não encontrado no catálogo. Rode backup:varrer primeiro.', $reference));

            return self::FAILURE;
        }

        $frase = trim((string) $this->option('frase'));
        $result = $verifier->verify($backup, $frase === '' ? null : $frase);

        $this->line(sprintf('Arquivo: %s', (string) $backup->arquivo_nome));
        $this->line(sprintf('Conteúdo: %s', $backup->conteudo?->label() ?? '-'));

        foreach ($result['membros'] as $member) {
            $this->line(sprintf(
                '  [%s] %s',
                $member['ok'] ? ' ok ' : 'FALHA',
                (string) $member['nome'],
            ));
        }

        if ($result['ok']) {
            $this->info('Integridade confirmada.');

            return self::SUCCESS;
        }

        foreach ($result['problemas'] as $problem) {
            $this->error('  '.$problem);
        }

        return self::FAILURE;
    }
}
