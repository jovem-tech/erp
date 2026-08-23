<?php

namespace App\Services\Backups;

class BackupManifestBuilder
{
    /** @var array<string, mixed> */
    private array $manifest;

    public function __construct(
        private readonly ArchiveCipher $cipher,
        private readonly ConfigSnapshotService $config,
    ) {
        $this->manifest = [
            'formato_versao' => (int) config('backup.format_version', 1),
            'gerado_em' => now()->toIso8601String(),
            'host' => gethostname(),
            'versao_sistema' => $this->systemVersion(),
            'app_url' => (string) config('app.url'),
            'app_key_fingerprint' => $this->config->appKeyFingerprint(),
            'cifra' => $this->cipher->describe(),
            'bancos' => [],
            'raizes' => [],
            'configuracao' => null,
            'tls' => $this->tls(),
            'membros' => [],
            'avisos' => [],
            'totais' => ['arquivos' => 0, 'bytes_arquivos' => 0],
        ];
    }

    public function withPassphraseFingerprint(string $fingerprint): self
    {
        $this->manifest['passphrase_fingerprint'] = $fingerprint;

        return $this;
    }

    public function withMigrationHead(?string $migration): self
    {
        // Usado na restauracao para bloquear o caso perigoso: pacote mais
        // ANTIGO que o schema em execucao significa que restaurar o banco
        // regride o schema e quebra o codigo publicado.
        $this->manifest['migracao_mais_recente'] = $migration;

        return $this;
    }

    /** @param array<string, mixed> $info */
    public function addDatabase(string $connection, array $info): self
    {
        $this->manifest['bancos'][$connection] = $info;

        return $this;
    }

    /** @param array<string, mixed> $info */
    public function addRoot(string $id, array $info): self
    {
        $this->manifest['raizes'][$id] = $info;

        if (isset($info['arquivos'], $info['bytes'])) {
            $this->manifest['totais']['arquivos'] += (int) $info['arquivos'];
            $this->manifest['totais']['bytes_arquivos'] += (int) $info['bytes'];
        }

        return $this;
    }

    /** @param array<string, mixed> $info */
    public function addConfig(array $info): self
    {
        $this->manifest['configuracao'] = $info;

        return $this;
    }

    public function addMember(string $path, int $bytes, string $sha256): self
    {
        // O sha256 de cada membro e o que fecha a unica fraqueza real do
        // AES-CBC (maleabilidade): nada e restaurado sem conferir estes hashes.
        $this->manifest['membros'][$path] = ['bytes' => $bytes, 'sha256' => $sha256];

        return $this;
    }

    public function addWarning(string $code, string $component, string $message): self
    {
        $this->manifest['avisos'][] = [
            'codigo' => $code,
            'componente' => $component,
            'mensagem' => $message,
        ];

        return $this;
    }

    /** @param array<int, string> $messages */
    public function addWarnings(string $code, string $component, array $messages): self
    {
        foreach ($messages as $message) {
            $this->addWarning($code, $component, $message);
        }

        return $this;
    }

    public function hasWarnings(): bool
    {
        return $this->manifest['avisos'] !== [];
    }

    /** @return array<int, array<string, string>> */
    public function warnings(): array
    {
        return $this->manifest['avisos'];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->manifest;
    }

    public function toJson(): string
    {
        return (string) json_encode(
            $this->manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    private function systemVersion(): string
    {
        $path = dirname(base_path()).'/VERSION';

        return is_file($path) ? trim((string) file_get_contents($path)) : '';
    }

    /** @return array<string, mixed> */
    private function tls(): array
    {
        // Certificados ficam fora de proposito: na VPS sao Let's Encrypt e
        // renovam sozinhos; na bancada sao autoassinados e se regeneram num
        // comando. Liberar a chave privada para o usuario do site, a fim de
        // manda-la para a nuvem, seria perda liquida de seguranca.
        return [
            'incluido' => (bool) config('backup.config_snapshot.include_tls', false),
            'renovavel' => true,
            'observacao' => 'Certificados TLS não entram no pacote; reemita-os após restaurar.',
        ];
    }
}
