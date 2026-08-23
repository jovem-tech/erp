<?php

namespace App\Services\Backups;

use App\Services\Backups\Contracts\ProcessRunner;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

class FileTreeArchiveService
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly ArchiveCipher $cipher,
    ) {}

    /**
     * Empacota uma arvore de arquivos e emite o indice por arquivo.
     *
     * O indice (.index.tsv.gz) custa pouco - hashear 76 MB sai em ~1s vindo do
     * cache de pagina - e compra verificacao granular, restauracao de um
     * arquivo so e um diff real depois da restauracao.
     *
     * @param  array{id: string, label: string, path: string|null}  $root
     * @return array{arquivos: int, bytes: int, bytes_pacote: int, sha256: string, avisos: array<int, string>}
     */
    public function archive(array $root, string $targetPath, string $indexPath, string $passphrase, string $workDir): array
    {
        $basePath = rtrim((string) $root['path'], '/');

        if (! is_dir($basePath)) {
            throw new RuntimeException(sprintf('Raiz "%s" não encontrada em %s.', $root['id'], $basePath));
        }

        [$entries, $totalBytes, $warnings] = $this->walk($basePath);

        $listFile = $workDir.'/.lista-'.$root['id'].'-'.bin2hex(random_bytes(4)).'.txt';
        $indexTmp = $workDir.'/.indice-'.$root['id'].'-'.bin2hex(random_bytes(4)).'.tsv';

        try {
            $list = '';
            $index = "caminho\tbytes\tmtime\tsha256\n";

            foreach ($entries as $entry) {
                $list .= $entry['relative']."\n";
                $index .= sprintf(
                    "%s\t%d\t%d\t%s\n",
                    $entry['relative'],
                    $entry['bytes'],
                    $entry['mtime'],
                    $entry['sha256'],
                );
            }

            file_put_contents($listFile, $list);
            file_put_contents($indexTmp, $index);

            // --no-recursion + --files-from: a lista vem do walk em PHP, que e a
            // fonte unica de verdade das exclusoes e da recusa a symlinks. Deixar
            // o tar recursar duplicaria essa logica em outro lugar.
            $script = sprintf(
                // --directory PRECISA vir antes de --files-from: no GNU tar as
                // opcoes sao posicionais, e invertido ele resolve os caminhos da
                // lista antes de trocar de diretorio, falhando com ENOENT.
                'tar --create --no-recursion --directory=%s --files-from=%s | gzip -6 | %s > %s',
                escapeshellarg($basePath),
                escapeshellarg($listFile),
                $this->cipher->encryptFilter(),
                escapeshellarg($targetPath),
            );

            $result = $this->runner->runShell($script, $this->cipher->env($passphrase));

            if (! $result->successful()) {
                throw new RuntimeException(sprintf(
                    'Falha ao empacotar a raiz "%s" (%s).',
                    $root['id'],
                    $result->failureMessage(),
                ));
            }

            $indexResult = $this->runner->runShell(sprintf(
                'gzip -6 -c %s > %s',
                escapeshellarg($indexTmp),
                escapeshellarg($indexPath),
            ));

            if (! $indexResult->successful()) {
                throw new RuntimeException(sprintf(
                    'Falha ao gravar o índice da raiz "%s" (%s).',
                    $root['id'],
                    $indexResult->failureMessage(),
                ));
            }

            return [
                'arquivos' => count($entries),
                'bytes' => $totalBytes,
                'bytes_pacote' => (int) filesize($targetPath),
                'sha256' => (string) hash_file('sha256', $targetPath),
                'avisos' => $warnings,
            ];
        } finally {
            @unlink($listFile);
            @unlink($indexTmp);
        }
    }

    /**
     * @return array{0: array<int, array{relative: string, bytes: int, mtime: int, sha256: string}>, 1: int, 2: array<int, string>}
     */
    private function walk(string $basePath): array
    {
        $excluded = array_values(array_filter(
            (array) config('backup.exclude', []),
            static fn ($value): bool => is_string($value) && $value !== ''
        ));

        $entries = [];
        $totalBytes = 0;
        $warnings = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $basePath,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO
            ),
            RecursiveIteratorIterator::SELF_FIRST,
            RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $absolute = $file->getPathname();
            $relative = ltrim(substr($absolute, strlen($basePath)), '/');

            if ($relative === '' || $this->isExcluded($relative, $excluded)) {
                continue;
            }

            // Symlinks e arquivos especiais ficam de fora por politica: um link
            // dentro do pacote pode apontar para qualquer lugar do sistema de
            // arquivos na hora de extrair (ver acesso-seguro-a-arquivos.md).
            if ($file->isLink()) {
                $warnings[] = sprintf('Link simbólico ignorado: %s', $relative);

                continue;
            }

            if ($file->isDir()) {
                // Diretorio ilegivel PRECISA virar aviso, nunca omissao muda.
                // As pastas privadas sao 0700 www-data: rodar o backup com o
                // usuario errado produziria um pacote silenciosamente incompleto
                // - que e pior que nenhum pacote, porque parece um backup.
                if (! $file->isReadable()) {
                    $warnings[] = sprintf(
                        'Diretório ilegível NÃO copiado: %s (o backup precisa rodar como o dono dos arquivos).',
                        $relative
                    );
                }

                continue;
            }

            if (! $file->isFile()) {
                $warnings[] = sprintf('Arquivo especial ignorado: %s', $relative);

                continue;
            }

            if (! $file->isReadable()) {
                $warnings[] = sprintf('Arquivo ilegível ignorado: %s', $relative);

                continue;
            }

            $bytes = (int) $file->getSize();
            $hash = hash_file('sha256', $absolute);

            if ($hash === false) {
                $warnings[] = sprintf('Não foi possível calcular o hash de: %s', $relative);

                continue;
            }

            $entries[] = [
                'relative' => $relative,
                'bytes' => $bytes,
                'mtime' => (int) $file->getMTime(),
                'sha256' => $hash,
            ];

            $totalBytes += $bytes;
        }

        usort($entries, static fn (array $a, array $b): int => strcmp($a['relative'], $b['relative']));

        return [$entries, $totalBytes, $warnings];
    }

    /** @param array<int, string> $excluded */
    private function isExcluded(string $relative, array $excluded): bool
    {
        $segments = explode('/', $relative);

        foreach ($excluded as $pattern) {
            $pattern = trim($pattern, '/');

            // Sem barra: casa com qualquer segmento do caminho.
            if (! str_contains($pattern, '/')) {
                if (in_array($pattern, $segments, true)) {
                    return true;
                }

                continue;
            }

            // Com barra: casa por prefixo a partir da raiz.
            if ($relative === $pattern || str_starts_with($relative, $pattern.'/')) {
                return true;
            }
        }

        return false;
    }
}
