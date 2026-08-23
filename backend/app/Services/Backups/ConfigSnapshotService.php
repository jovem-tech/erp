<?php

namespace App\Services\Backups;

use App\Services\Backups\Contracts\ProcessRunner;
use RuntimeException;

class ConfigSnapshotService
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly ArchiveCipher $cipher,
    ) {}

    /**
     * Empacota .env e afins.
     *
     * O APP_KEY PRECISA viajar junto: app/Casts/EncryptedSecret.php torna
     * varias colunas ilegiveis sem ele, entao um dump sem a chave e um dump
     * parcialmente destruido. Ele nunca e RESTAURADO automaticamente - essa
     * separacao entre "capturar" e "aplicar" e o ponto central aqui.
     *
     * @return array{bytes: int, sha256: string, itens: array<int, string>, avisos: array<int, string>}
     */
    public function capture(string $targetPath, string $passphrase, string $workDir): array
    {
        $repoRoot = rtrim(dirname(base_path()), '/');
        // Area de estagio SEPARADA do diretorio do membro: quando as duas
        // coincidiam, o purge() do finally apagava o proprio .enc recem-gerado.
        $stage = $workDir.'/.configuracao-estagio';

        if (! is_dir($stage) && ! mkdir($stage, 0700, true) && ! is_dir($stage)) {
            throw new RuntimeException('Não foi possível preparar a área de configuração do backup.');
        }

        $items = [];
        $warnings = [];

        foreach ((array) config('backup.config_snapshot.files', []) as $source => $name) {
            $absolute = $repoRoot.'/'.ltrim((string) $source, '/');

            if (! is_file($absolute)) {
                $warnings[] = sprintf('Arquivo de configuração ausente: %s', $source);

                continue;
            }

            if (! is_readable($absolute)) {
                $warnings[] = sprintf('Arquivo de configuração ilegível: %s', $source);

                continue;
            }

            copy($absolute, $stage.'/'.$name);
            chmod($stage.'/'.$name, 0600);
            $items[] = (string) $name;
        }

        file_put_contents($stage.'/PROCEDENCIA.txt', $this->provenance($repoRoot));
        $items[] = 'PROCEDENCIA.txt';

        try {
            $script = sprintf(
                'tar --create --directory=%s . | gzip -6 | %s > %s',
                escapeshellarg($stage),
                $this->cipher->encryptFilter(),
                escapeshellarg($targetPath),
            );

            $result = $this->runner->runShell($script, $this->cipher->env($passphrase));

            if (! $result->successful()) {
                throw new RuntimeException(sprintf(
                    'Falha ao empacotar a configuração (%s).',
                    $result->failureMessage(),
                ));
            }

            return [
                'bytes' => (int) filesize($targetPath),
                'sha256' => (string) hash_file('sha256', $targetPath),
                'itens' => $items,
                'avisos' => $warnings,
            ];
        } finally {
            $this->purge($stage);
        }
    }

    /**
     * Identidade nao reversivel do APP_KEY em uso.
     *
     * Permite a restauracao avisar "as colunas criptografadas deste pacote nao
     * vao abrir com a chave atual" ANTES de mexer em qualquer coisa, em vez de
     * o operador descobrir isso com o sistema ja restaurado.
     */
    public function appKeyFingerprint(): string
    {
        $key = (string) config('app.key');

        return $key === '' ? '' : substr(hash('sha256', $key), 0, 16);
    }

    private function provenance(string $repoRoot): string
    {
        $version = trim((string) @file_get_contents($repoRoot.'/VERSION'));
        $commit = $this->runner->run(['git', '-C', $repoRoot, 'rev-parse', 'HEAD']);

        return implode("\n", [
            'Procedência do backup',
            '======================',
            'Gerado em: '.now()->toDateTimeString(),
            'Versão do sistema: '.($version === '' ? 'desconhecida' : $version),
            'Commit: '.($commit->successful() ? trim($commit->output) : 'indisponível'),
            'Host: '.gethostname(),
            'APP_URL: '.(string) config('app.url'),
            'Impressão do APP_KEY: '.$this->appKeyFingerprint(),
            '',
            'ATENÇÃO: este pacote contém .env com senhas e o APP_KEY.',
            'Nunca aplique estes arquivos automaticamente sobre um servidor em uso.',
            '',
        ]);
    }

    private function purge(string $directory): void
    {
        foreach ((array) glob($directory.'/*') as $file) {
            if (is_file((string) $file)) {
                @unlink((string) $file);
            }
        }

        @rmdir($directory);
    }
}
