<?php

namespace App\Services\Backups;

class BackupRootRegistry
{
    /**
     * Resolve o ID LOGICO de uma raiz para o caminho absoluto ATUAL.
     *
     * Esta indirecao e a propriedade de seguranca mais importante da
     * restauracao. LEGACY_PUBLIC_PATH difere entre a bancada e a VPS; se o
     * manifesto guardasse caminhos absolutos, restaurar um pacote de producao
     * na bancada escreveria no caminho de producao. Guardando ids logicos, a
     * raiz e sempre relida da configuracao da maquina onde a restauracao roda.
     *
     * @return array<string, array{id: string, label: string, path: string|null, optional: bool, available: bool, reason: string|null}>
     */
    public function all(): array
    {
        $resolved = [];

        foreach ((array) config('backup.roots', []) as $id => $definition) {
            $resolved[$id] = $this->resolve((string) $id, (array) $definition);
        }

        return $resolved;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $definition = config('backup.roots.'.$id);

        return is_array($definition) ? $this->resolve($id, $definition) : null;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{id: string, label: string, path: string|null, optional: bool, available: bool, reason: string|null}
     */
    private function resolve(string $id, array $definition): array
    {
        $path = $this->resolvePath((string) ($definition['resolver'] ?? ''));

        if ($path !== null && isset($definition['suffix'])) {
            $path = rtrim($path, '/').'/'.trim((string) $definition['suffix'], '/');
        }

        [$available, $reason] = $this->probe($path);

        return [
            'id' => $id,
            'label' => (string) ($definition['label'] ?? $id),
            'path' => $path,
            'optional' => (bool) ($definition['optional'] ?? false),
            'available' => $available,
            'reason' => $reason,
        ];
    }

    private function resolvePath(string $resolver): ?string
    {
        if (str_starts_with($resolver, 'storage:')) {
            return storage_path(substr($resolver, strlen('storage:')));
        }

        if (str_starts_with($resolver, 'disk:')) {
            $disk = substr($resolver, strlen('disk:'));
            $root = config('filesystems.disks.'.$disk.'.root');

            return is_string($root) && $root !== '' ? $root : null;
        }

        if (str_starts_with($resolver, 'path:')) {
            return substr($resolver, strlen('path:'));
        }

        return null;
    }

    /** @return array{0: bool, 1: string|null} */
    private function probe(?string $path): array
    {
        if ($path === null || $path === '') {
            return [false, 'caminho não configurado'];
        }

        if (! is_dir($path)) {
            return [false, 'diretório inexistente'];
        }

        // Nao basta existir: o backup roda como www-data e as subpastas
        // privadas sao 0700. Um diretorio ilegivel silenciosamente produziria
        // um pacote incompleto, que e pior que nenhum pacote.
        if (! is_readable($path)) {
            return [false, 'sem permissão de leitura'];
        }

        return [true, null];
    }
}
