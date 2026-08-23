<?php

namespace App\Services\Backups;

use App\Enums\Backups\BackupContent;
use App\Models\Backups\Backup;
use App\Services\Backups\Contracts\ProcessRunner;

class BackupVerificationService
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly ArchiveCipher $cipher,
    ) {}

    /**
     * Confere a integridade do pacote SEM restaurar nada.
     *
     * Passar a frase secreta e opcional: sem ela ainda da para conferir o
     * manifesto e o sha256 de cada membro (que e o que fecha a maleabilidade
     * do AES-CBC). Com ela, tambem se prova que cada membro realmente decifra.
     *
     * @return array{ok: bool, conteudo: string, membros: array<int, array<string, mixed>>, problemas: array<int, string>, manifesto: array<string, mixed>|null}
     */
    public function verify(Backup $backup, ?string $passphrase = null): array
    {
        $path = (string) $backup->arquivo_caminho;

        if ($path === '' || ! is_file($path)) {
            return [
                'ok' => false,
                'conteudo' => (string) $backup->conteudo?->value,
                'membros' => [],
                'problemas' => ['O arquivo do backup não foi encontrado no disco.'],
                'manifesto' => null,
            ];
        }

        if ($backup->conteudo === BackupContent::SomenteBanco) {
            return $this->verifyPlainDump($backup, $path);
        }

        return $this->verifyPackage($backup, $path, $passphrase);
    }

    /**
     * Dumps do cron de root sao .sql.gz simples, sem manifesto.
     *
     * Verificado na pratica: `gzip -t` sozinho PASSA num arquivo de 20 bytes
     * deixado por um mysqldump que falhou. A prova real e o rodape
     * "Dump completed" que o mysqldump escreve ao terminar.
     *
     * @return array<string, mixed>
     */
    private function verifyPlainDump(Backup $backup, string $path): array
    {
        $problems = [];

        $integrity = $this->runner->runShell(sprintf('gzip -t %s', escapeshellarg($path)));

        if (! $integrity->successful()) {
            $problems[] = 'O arquivo não é um gzip válido: '.$integrity->failureMessage();
        }

        $tail = $this->runner->runShell(sprintf('gunzip -c %s | tail -c 400', escapeshellarg($path)));

        if (! $tail->successful()) {
            $problems[] = 'Não foi possível ler o final do dump: '.$tail->failureMessage();
        } elseif (! str_contains($tail->output, 'Dump completed')) {
            $problems[] = 'O dump está truncado: o rodapé "Dump completed" não foi encontrado. '
                .'Este backup NÃO deve ser usado para restaurar.';
        }

        $sha = (string) hash_file('sha256', $path);

        if ($backup->sha256 === null) {
            // Primeiro exame deste arquivo: guarda o hash como linha de base
            // para detectar corrupcao silenciosa daqui para frente.
            $backup->forceFill(['sha256' => $sha])->save();
        } elseif (! hash_equals((string) $backup->sha256, $sha)) {
            $problems[] = 'O conteúdo do arquivo mudou desde o último exame (sha256 diferente).';
        }

        return [
            'ok' => $problems === [],
            'conteudo' => BackupContent::SomenteBanco->value,
            'membros' => [[
                'nome' => basename($path),
                'bytes' => (int) filesize($path),
                'sha256' => $sha,
                'ok' => $problems === [],
            ]],
            'problemas' => $problems,
            'manifesto' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function verifyPackage(Backup $backup, string $path, ?string $passphrase): array
    {
        $problems = [];
        $manifest = $this->readManifest($path);

        if ($manifest === null) {
            return [
                'ok' => false,
                'conteudo' => BackupContent::Completo->value,
                'membros' => [],
                'problemas' => ['O manifesto do pacote não pôde ser lido; o arquivo pode estar corrompido.'],
                'manifesto' => null,
            ];
        }

        $members = [];

        foreach ((array) ($manifest['membros'] ?? []) as $name => $expected) {
            $actual = $this->memberHash($path, (string) $name);
            $ok = $actual !== null && hash_equals((string) ($expected['sha256'] ?? ''), $actual);

            if (! $ok) {
                $problems[] = sprintf('Membro corrompido ou ausente: %s.', (string) $name);
            }

            $members[] = [
                'nome' => (string) $name,
                'bytes' => (int) ($expected['bytes'] ?? 0),
                'sha256' => $actual,
                'ok' => $ok,
            ];
        }

        if ($passphrase !== null && $passphrase !== '') {
            $problems = array_merge($problems, $this->probeDecryption($path, $manifest, $passphrase));
        }

        return [
            'ok' => $problems === [],
            'conteudo' => BackupContent::Completo->value,
            'membros' => $members,
            'problemas' => $problems,
            'manifesto' => $manifest,
        ];
    }

    /** @return array<string, mixed>|null */
    public function readManifest(string $path): ?array
    {
        $result = $this->runner->run(['tar', '-xOf', $path, './manifest.json']);

        if (! $result->successful()) {
            $result = $this->runner->run(['tar', '-xOf', $path, 'manifest.json']);
        }

        if (! $result->successful()) {
            return null;
        }

        $decoded = json_decode($result->output, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function memberHash(string $archive, string $member): ?string
    {
        $result = $this->runner->runShell(sprintf(
            'tar -xOf %s %s 2>/dev/null | sha256sum | cut -d" " -f1',
            escapeshellarg($archive),
            escapeshellarg('./'.ltrim($member, './')),
        ));

        $hash = trim($result->output);

        // sha256 de entrada vazia: o membro nao existe no pacote.
        if (! $result->successful() || $hash === '' || $hash === hash('sha256', '')) {
            return null;
        }

        return $hash;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<int, string>
     */
    private function probeDecryption(string $archive, array $manifest, string $passphrase): array
    {
        $problems = [];

        foreach ((array) ($manifest['membros'] ?? []) as $name => $_) {
            $name = (string) $name;

            if (! str_ends_with($name, '.enc')) {
                continue;
            }

            $result = $this->runner->runShell(
                sprintf(
                    'tar -xOf %s %s | %s | gunzip > /dev/null',
                    escapeshellarg($archive),
                    escapeshellarg('./'.ltrim($name, './')),
                    $this->cipher->decryptFilter(),
                ),
                $this->cipher->env($passphrase),
            );

            if (! $result->successful()) {
                $problems[] = sprintf(
                    'O membro %s não pôde ser decifrado com esta frase secreta.',
                    $name
                );
            }
        }

        return $problems;
    }
}
