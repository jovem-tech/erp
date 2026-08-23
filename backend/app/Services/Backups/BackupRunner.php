<?php

namespace App\Services\Backups;

use App\Enums\Backups\BackupContent;
use App\Enums\Backups\BackupStatus;
use App\Models\Backups\Backup;
use App\Services\Backups\Contracts\ProcessRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class BackupRunner
{
    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly ArchiveCipher $cipher,
        private readonly BackupPreflight $preflight,
        private readonly BackupSettingsService $settings,
        private readonly BackupRootRegistry $roots,
        private readonly DatabaseDumpService $databases,
        private readonly FileTreeArchiveService $files,
        private readonly ConfigSnapshotService $config,
        private readonly BackupRetentionPolicy $retention,
    ) {}

    public function run(Backup $backup, string $passphrase, BackupPassphraseResolver $resolver): Backup
    {
        // Trava nao bloqueante: duas execucoes simultaneas competiriam pelo
        // mesmo diretorio de trabalho e produziriam dois pacotes pela metade.
        if (! $this->acquireLock()) {
            throw new RuntimeException('Já existe um backup em andamento neste servidor.');
        }

        $workDir = null;

        try {
            $check = $this->preflight->check();

            if (! $check['ok']) {
                throw new RuntimeException(implode(' ', $check['erros']));
            }

            $store = rtrim((string) config('backup.store.path'), '/');
            $workDir = $store.'/'.trim((string) config('backup.store.work_dirname', '.trabalho'), '/').'/'.$backup->uuid;

            $this->makeDirectory($workDir);

            $backup->forceFill([
                'status' => BackupStatus::Executando->value,
                'iniciado_em' => now(),
                'heartbeat_em' => now(),
                'etapa_atual' => 'Preparando',
                'progresso_percentual' => 5,
            ])->save();

            $manifest = (new BackupManifestBuilder($this->cipher, $this->config))
                ->withPassphraseFingerprint($resolver->fingerprint($passphrase))
                ->withMigrationHead($this->latestMigration())
                ->addWarnings('preflight', 'ambiente', $check['avisos']);

            $this->dumpDatabases($backup, $manifest, $workDir, $passphrase);
            $this->archiveRoots($backup, $manifest, $workDir, $passphrase);
            $this->captureConfig($backup, $manifest, $workDir, $passphrase);

            $finalPath = $this->seal($backup, $manifest, $workDir, $store);

            $this->finish($backup, $manifest, $finalPath);

            $this->retention->apply();

            return $backup->refresh();
        } catch (Throwable $exception) {
            Log::error('[BACKUP] Falha ao gerar backup', [
                'uuid' => $backup->uuid,
                'erro' => $exception->getMessage(),
            ]);

            $backup->forceFill([
                'status' => BackupStatus::Falhou->value,
                'etapa_atual' => 'Falhou',
                'concluido_em' => now(),
                'erro_mensagem' => $exception->getMessage(),
                'duracao_segundos' => $this->elapsed($backup),
            ])->save();

            throw $exception;
        } finally {
            if ($workDir !== null) {
                $this->purgeDirectory($workDir);
            }

            $this->releaseLock();
        }
    }

    private function dumpDatabases(Backup $backup, BackupManifestBuilder $manifest, string $workDir, string $passphrase): void
    {
        $this->progress($backup, 'Copiando bancos de dados', 15);

        $this->makeDirectory($workDir.'/db');
        $included = [];

        foreach ((array) config('backup.databases.connections', []) as $connection) {
            $connection = (string) $connection;

            if ($connection === 'chat' && ! $this->settings->bool('backup_incluir_banco_chat')) {
                continue;
            }

            $probe = $this->databases->probe($connection);

            if (! $probe['ok']) {
                // Ausencia vira aviso, nunca falha. Na bancada o
                // sistema_erp_chat nao existe; exigir sua presenca faria o
                // sistema nunca produzir backup nenhum.
                $manifest->addWarning(
                    'banco_ausente',
                    $connection,
                    sprintf('Banco "%s" não copiado: %s.', (string) $probe['database'], (string) $probe['reason'])
                );

                continue;
            }

            $engines = array_keys($probe['engines']);
            if ($engines !== [] && $engines !== ['InnoDB']) {
                $manifest->addWarning(
                    'consistencia_parcial',
                    $connection,
                    sprintf(
                        'O banco contém tabelas fora do InnoDB (%s): a consistência do instantâneo não é garantida para elas.',
                        implode(', ', $engines)
                    )
                );
            }

            $name = 'db/'.$probe['database'].'.sql.gz.enc';
            $target = $workDir.'/'.$name;

            $dump = $this->databases->dump($connection, $target, $passphrase, $workDir);

            $manifest
                ->addDatabase($connection, [
                    'banco' => $probe['database'],
                    'membro' => $name,
                    'bytes' => $dump['bytes'],
                    'engines' => $probe['engines'],
                    'tabelas_sem_dados' => $dump['tabelas_sem_dados'],
                ])
                ->addMember($name, $dump['bytes'], $dump['sha256']);

            if ($dump['tabelas_sem_dados'] !== []) {
                $manifest->addWarning(
                    'tabelas_sem_dados',
                    $connection,
                    sprintf(
                        'Telemetria copiada apenas como estrutura (sem dados): %s.',
                        implode(', ', $dump['tabelas_sem_dados'])
                    )
                );
            }

            $included[] = (string) $probe['database'];
        }

        if ($included === []) {
            throw new RuntimeException('Nenhum banco de dados pôde ser copiado; o backup foi abortado.');
        }

        $backup->forceFill(['bancos_incluidos' => $included])->save();
    }

    private function archiveRoots(Backup $backup, BackupManifestBuilder $manifest, string $workDir, string $passphrase): void
    {
        $this->progress($backup, 'Copiando arquivos', 45);

        $this->makeDirectory($workDir.'/arquivos');
        $included = [];

        foreach ($this->roots->all() as $id => $root) {
            if ($id === 'legado_uploads' && ! $this->settings->bool('backup_incluir_legado')) {
                continue;
            }

            if (! $root['available']) {
                $manifest->addWarning(
                    'raiz_indisponivel',
                    $id,
                    sprintf('Raiz "%s" não copiada: %s.', $root['label'], (string) $root['reason'])
                );

                continue;
            }

            $member = 'arquivos/'.$id.'.tar.gz.enc';
            $indexMember = 'arquivos/'.$id.'.index.tsv.gz';

            $result = $this->files->archive(
                $root,
                $workDir.'/'.$member,
                $workDir.'/'.$indexMember,
                $passphrase,
                $workDir,
            );

            $manifest
                ->addRoot($id, [
                    'rotulo' => $root['label'],
                    'membro' => $member,
                    'indice' => $indexMember,
                    'arquivos' => $result['arquivos'],
                    'bytes' => $result['bytes'],
                ])
                ->addMember($member, $result['bytes_pacote'], $result['sha256'])
                ->addMember(
                    $indexMember,
                    (int) filesize($workDir.'/'.$indexMember),
                    (string) hash_file('sha256', $workDir.'/'.$indexMember)
                )
                ->addWarnings('arquivo_ignorado', $id, $result['avisos']);

            $included[] = $id;
        }

        $backup->forceFill(['raizes_incluidas' => $included])->save();
    }

    private function captureConfig(Backup $backup, BackupManifestBuilder $manifest, string $workDir, string $passphrase): void
    {
        if (! $this->settings->bool('backup_incluir_config')) {
            $manifest->addWarning(
                'configuracao_omitida',
                'configuracao',
                'A cópia da configuração está desligada: sem o APP_KEY, colunas criptografadas não poderão ser lidas.'
            );

            return;
        }

        $this->progress($backup, 'Copiando configuração', 70);

        $this->makeDirectory($workDir.'/configuracao');
        $member = 'configuracao/segredos.tar.gz.enc';

        $result = $this->config->capture($workDir.'/'.$member, $passphrase, $workDir);

        $manifest
            ->addConfig([
                'membro' => $member,
                'bytes' => $result['bytes'],
                'itens' => $result['itens'],
                'contem_segredos' => true,
            ])
            ->addMember($member, $result['bytes'], $result['sha256'])
            ->addWarnings('configuracao', 'configuracao', $result['avisos']);
    }

    private function seal(Backup $backup, BackupManifestBuilder $manifest, string $workDir, string $store): string
    {
        $this->progress($backup, 'Selando o pacote', 85);

        file_put_contents($workDir.'/manifest.json', $manifest->toJson());
        file_put_contents(
            $workDir.'/manifest.json.sha256',
            hash_file('sha256', $workDir.'/manifest.json')."  manifest.json\n"
        );
        file_put_contents($workDir.'/LEIA-ME-RESTAURACAO.txt', $this->readme($manifest));

        $name = sprintf('erp-backup-%s-%s.tar', now()->format('Ymd-His'), substr((string) $backup->uuid, 0, 8));
        $finalPath = $store.'/'.$name;

        // Os membros ja estao comprimidos e cifrados; o tar externo e so o
        // envelope, e o manifesto fica em texto claro de proposito para que a
        // interface e a retencao leiam o conteudo sem a frase secreta.
        $result = $this->runner->runShell(sprintf(
            'tar --create --file=%s --directory=%s --exclude=%s .',
            escapeshellarg($finalPath),
            escapeshellarg($workDir),
            escapeshellarg('./.*'),
        ));

        if (! $result->successful()) {
            throw new RuntimeException(sprintf('Falha ao selar o pacote (%s).', $result->failureMessage()));
        }

        chmod($finalPath, 0600);
        $backup->forceFill(['arquivo_nome' => $name, 'arquivo_caminho' => $finalPath])->save();

        return $finalPath;
    }

    private function finish(Backup $backup, BackupManifestBuilder $manifest, string $finalPath): void
    {
        $data = $manifest->toArray();

        $backup->forceFill([
            'status' => $manifest->hasWarnings()
                ? BackupStatus::ConcluidoComAvisos->value
                : BackupStatus::Concluido->value,
            'conteudo' => BackupContent::Completo->value,
            'etapa_atual' => 'Concluído',
            'progresso_percentual' => 100,
            'tamanho_bytes' => (int) filesize($finalPath),
            'sha256' => (string) hash_file('sha256', $finalPath),
            'arquivo_modificado_em' => now(),
            'formato_versao' => (int) $data['formato_versao'],
            'cifra' => (string) $data['cifra']['algoritmo'],
            'kdf_iteracoes' => (int) $data['cifra']['iteracoes'],
            'passphrase_fingerprint' => (string) ($data['passphrase_fingerprint'] ?? ''),
            'versao_sistema' => (string) $data['versao_sistema'],
            'total_arquivos' => (int) $data['totais']['arquivos'],
            'total_bytes_arquivos' => (int) $data['totais']['bytes_arquivos'],
            'manifesto_json' => $data,
            'avisos_json' => $manifest->warnings(),
            'concluido_em' => now(),
            'duracao_segundos' => $this->elapsed($backup),
        ])->save();
    }

    private function readme(BackupManifestBuilder $manifest): string
    {
        $data = $manifest->toArray();

        return implode("\n", [
            'COMO RESTAURAR ESTE BACKUP SEM O SISTEMA',
            '========================================',
            '',
            'Gerado em: '.$data['gerado_em'],
            'Versão do sistema: '.$data['versao_sistema'],
            'Cifra: '.$data['cifra']['algoritmo'].' + PBKDF2 ('.$data['cifra']['iteracoes'].' iterações, '.$data['cifra']['digest'].')',
            '',
            'Quando você precisar deste arquivo, é bem provável que o painel esteja fora',
            'do ar. Os passos abaixo funcionam em qualquer Linux, sem PHP e sem o sistema.',
            '',
            '1) Abrir o envelope:',
            '     tar -xvf erp-backup-....tar',
            '',
            '2) Guardar a frase secreta num arquivo temporário:',
            '     umask 077; printf %s \'SUA-FRASE-SECRETA\' > frase.txt',
            '',
            '3) Decifrar e restaurar o banco principal:',
            '     '.$this->cipher->manualDecryptCommand('db/NOME-DO-BANCO.sql.gz.enc').' \\',
            '       | gunzip | mysql -u USUARIO -p NOME-DO-BANCO',
            '',
            '4) Decifrar e extrair os arquivos:',
            '     '.$this->cipher->manualDecryptCommand('arquivos/backend_privado.tar.gz.enc').' \\',
            '       | tar -xzpv --no-same-owner -C /destino',
            '',
            '5) Apagar a frase:  shred -u frase.txt',
            '',
            'CONFERÊNCIA DE INTEGRIDADE',
            '--------------------------',
            'manifest.json traz o sha256 de cada membro. Confira antes de restaurar:',
            '     sha256sum db/*.enc arquivos/*.enc configuracao/*.enc',
            '',
            'AVISOS IMPORTANTES',
            '------------------',
            '* configuracao/segredos.tar.gz.enc contém .env com senhas e o APP_KEY.',
            '  Sem o APP_KEY as colunas criptografadas do banco não abrem.',
            '  NUNCA sobrescreva o .env de um servidor em uso automaticamente.',
            '* Certificados TLS não estão neste pacote: reemita-os após restaurar.',
            '* Sem a frase secreta, nada aqui pode ser recuperado.',
            '',
        ]);
    }

    private function latestMigration(): ?string
    {
        try {
            $migration = DB::table('laravel_migrations')->orderByDesc('id')->value('migration');

            return is_string($migration) ? $migration : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function progress(Backup $backup, string $step, int $percent): void
    {
        $backup->forceFill([
            'etapa_atual' => $step,
            'progresso_percentual' => $percent,
            'heartbeat_em' => now(),
        ])->save();
    }

    private function elapsed(Backup $backup): int
    {
        return $backup->iniciado_em === null ? 0 : max(0, now()->diffInSeconds($backup->iniciado_em, true));
    }

    private function makeDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException(sprintf('Não foi possível criar o diretório de trabalho: %s', $path));
        }
    }

    private function purgeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        // rm -rf via runner mantem o comportamento previsivel com arvores
        // profundas e evita reimplementar remocao recursiva em PHP.
        $this->runner->run(['rm', '-rf', '--', $path]);
    }

    private function acquireLock(): bool
    {
        $lockFile = (string) config('backup.store.lock_file');
        $handle = @fopen($lockFile, 'c');

        if ($handle === false) {
            // Sem poder criar a trava em /var/lock, cai para o diretorio da
            // loja - que o www-data com certeza escreve.
            $handle = @fopen(rtrim((string) config('backup.store.path'), '/').'/.lock', 'c');
        }

        if ($handle === false) {
            return false;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->lockHandle = $handle;

        return true;
    }

    private function releaseLock(): void
    {
        if ($this->lockHandle === null) {
            return;
        }

        flock($this->lockHandle, LOCK_UN);
        fclose($this->lockHandle);
        $this->lockHandle = null;
    }
}
