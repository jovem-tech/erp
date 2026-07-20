<?php

namespace App\Services\Files;

use App\Enums\Files\FileCategory;
use App\Enums\Files\FileManagerMode;

class FileManagerConfiguration
{
    public function mode(): FileManagerMode
    {
        $mode = FileManagerMode::tryFrom((string) config('file-manager.mode', 'off'));

        if ($mode === null) {
            throw new \RuntimeException('FILE_MANAGER_MODE invalido.');
        }

        return $mode;
    }

    /**
     * @return array<int, string>
     */
    public function validate(): array
    {
        $errors = [];
        $mode = FileManagerMode::tryFrom((string) config('file-manager.mode', 'off'));

        if ($mode === null) {
            $errors[] = 'mode_invalid';
        }

        $disk = (string) config('file-manager.storage.disk', '');
        $allowedDisks = (array) config('file-manager.storage.allowed_disks', []);
        if ($disk === '' || ! in_array($disk, $allowedDisks, true)) {
            $errors[] = 'storage_disk_not_allowlisted';
        }

        foreach (['root', 'staging_root'] as $key) {
            try {
                FilePathGuard::normalizeRelativePath((string) config("file-manager.storage.{$key}", ''));
            } catch (\InvalidArgumentException) {
                $errors[] = "storage_{$key}_invalid";
            }
        }

        $enabledCategories = (array) config('file-manager.enabled_categories', []);
        foreach ($enabledCategories as $category) {
            if (FileCategory::tryFrom((string) $category) === null || ! is_array(config("file-manager.policies.{$category}"))) {
                $errors[] = 'unknown_enabled_category:'.(string) $category;
            }
        }

        $hybridWriteCategories = (array) config('file-manager.hybrid_write_categories', []);
        foreach ($hybridWriteCategories as $category) {
            if (FileCategory::tryFrom((string) $category) === null) {
                $errors[] = 'unknown_hybrid_write_category:'.(string) $category;
            }
        }

        foreach ((array) config('file-manager.storage.legacy_read_disks', []) as $legacyDisk) {
            if (! is_array(config('filesystems.disks.'.(string) $legacyDisk))) {
                $errors[] = 'legacy_disk_not_configured:'.(string) $legacyDisk;
            }
        }

        if (
            $mode === FileManagerMode::Hybrid
            && (! (bool) config('file-manager.kill_switches.allow_writes', false) || $enabledCategories === [])
        ) {
            $errors[] = 'hybrid_requires_write_switch_and_category_allowlist';
        }

        if ($mode === FileManagerMode::Hybrid) {
            foreach ($enabledCategories as $category) {
                if (! in_array($category, $hybridWriteCategories, true)) {
                    $errors[] = 'category_not_approved_for_hybrid:'.(string) $category;
                }
            }
        }

        foreach ((array) config('file-manager.scanner.roots', []) as $alias => $root) {
            if (! is_array($root) || ! in_array((string) ($root['disk'] ?? ''), $allowedDisks, true)) {
                $errors[] = 'scanner_root_disk_invalid:'.(string) $alias;

                continue;
            }

            try {
                FilePathGuard::normalizeRelativePath((string) ($root['path'] ?? ''));
            } catch (\InvalidArgumentException) {
                $errors[] = 'scanner_root_path_invalid:'.(string) $alias;
            }
        }

        if ((bool) config('file-manager.automatic_sync.enabled', false)) {
            if (! in_array($mode, [FileManagerMode::Shadow, FileManagerMode::Hybrid], true)) {
                $errors[] = 'automatic_sync_requires_shadow_or_hybrid_mode';
            }
            if (! (bool) config('file-manager.kill_switches.allow_scanner', false)) {
                $errors[] = 'automatic_sync_requires_scanner_switch';
            }
            if (! (bool) config('file-manager.kill_switches.allow_mutating_reconcile', false)) {
                $errors[] = 'automatic_sync_requires_mutating_reconcile_switch';
            }

            $automaticRoots = (array) config('file-manager.automatic_sync.roots', []);
            if ($automaticRoots === []) {
                $errors[] = 'automatic_sync_requires_roots';
            }
            foreach ($automaticRoots as $rootAlias) {
                if (! is_string($rootAlias) || ! is_array(config('file-manager.scanner.roots.'.$rootAlias))) {
                    $errors[] = 'automatic_sync_root_not_allowlisted:'.(string) $rootAlias;
                }
            }
        }

        return array_values(array_unique($errors));
    }

    public function assertValid(): void
    {
        $errors = $this->validate();
        if ($errors !== []) {
            throw new \RuntimeException('Configuracao insegura do gerenciador de arquivos: '.implode(', ', $errors));
        }
    }

    public function assertCanWrite(FileCategory $category): void
    {
        $this->assertValid();

        if (
            ! $this->mode()->allowsCentralWrite()
            || ! (bool) config('file-manager.kill_switches.allow_writes', false)
            || ! in_array($category->value, (array) config('file-manager.enabled_categories', []), true)
            || ! $this->isHybridWriteCategory($category)
        ) {
            throw new \RuntimeException('Escrita central desabilitada para a categoria solicitada.');
        }
    }

    public function isSubjectTypeAllowed(string $subjectType): bool
    {
        return in_array($subjectType, (array) config('file-manager.subject_types', []), true);
    }

    public function isCategoryEnabled(FileCategory $category): bool
    {
        return in_array($category->value, (array) config('file-manager.enabled_categories', []), true);
    }

    public function isHybridWriteCategory(FileCategory $category): bool
    {
        return in_array($category->value, (array) config('file-manager.hybrid_write_categories', []), true);
    }

    public function isLegacyReadDiskAllowed(string $disk): bool
    {
        return in_array($disk, (array) config('file-manager.storage.legacy_read_disks', []), true)
            && is_array(config('filesystems.disks.'.$disk));
    }

    /**
     * @return array{disk: string, path: string}
     */
    public function scannerRoot(string $alias): array
    {
        $root = config("file-manager.scanner.roots.{$alias}");
        if (! is_array($root)) {
            throw new \InvalidArgumentException('Root de scanner nao autorizada.');
        }

        $disk = (string) ($root['disk'] ?? '');
        if (! in_array($disk, (array) config('file-manager.storage.allowed_disks', []), true)) {
            throw new \InvalidArgumentException('Disco de scanner nao autorizado.');
        }

        return [
            'disk' => $disk,
            'path' => FilePathGuard::normalizeRelativePath((string) ($root['path'] ?? '')),
        ];
    }
}
