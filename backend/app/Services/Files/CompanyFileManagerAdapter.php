<?php

namespace App\Services\Files;

use App\DTO\Files\FileContext;
use App\Enums\Files\FileCategory;
use App\Enums\Files\FileManagerMode;
use App\Enums\Files\FileOrigin;
use App\Models\Files\ManagedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CompanyFileManagerAdapter
{
    public function __construct(
        private readonly FileManagerConfiguration $configuration,
        private readonly LegacyCompatibleFileAdapter $adapter,
        private readonly LegacyFileResolver $legacyResolver,
        private readonly FileManagerMetrics $metrics
    ) {}

    public function synchronize(
        FileCategory $category,
        string $diskName,
        string $storagePath,
        string $configurationKey
    ): void {
        $mode = $this->configuration->mode();
        if ($mode === FileManagerMode::Off || ! $this->configuration->isCategoryEnabled($category)) {
            return;
        }

        $operationKey = 'company-existing:'.$category->value.':'.hash('sha256', $diskName."\0".$storagePath);
        $this->adapter->synchronizeExisting(
            new FileContext(
                category: $category,
                origin: FileOrigin::Upload,
                operationKey: $operationKey,
                subjectType: 'configuration',
                subjectId: 1,
                relation: $configurationKey,
                metadata: ['source' => 'legacy_compatible_path']
            ),
            $diskName,
            $storagePath,
            'configuracoes',
            'valor',
            $configurationKey
        );
    }

    public function shouldRetainPrevious(FileCategory $category): bool
    {
        return $this->configuration->mode() === FileManagerMode::Hybrid
            && $this->configuration->isCategoryEnabled($category);
    }

    public function resolveCompatiblePath(
        FileCategory $category,
        string $diskName,
        ?string $legacyPath
    ): ?string {
        if ($legacyPath === null) {
            return null;
        }

        $mode = $this->configuration->mode();
        if ($mode === FileManagerMode::Off || ! $this->configuration->isCategoryEnabled($category)) {
            return $legacyPath;
        }

        try {
            $central = $this->legacyResolver->resolve($diskName, $legacyPath);
            if ($central instanceof ManagedFile && Storage::disk($central->storage_disk)->exists($central->storage_key)) {
                $this->metrics->increment($category, 'central_read');

                return $central->storage_key;
            }
        } catch (\Throwable $exception) {
            Log::warning('[FILE_MANAGER][COMPANY] Falha isolada na leitura central.', [
                'category' => $category->value,
                'error_type' => $exception::class,
            ]);
        }

        $this->metrics->increment($category, 'legacy_fallback');

        return $legacyPath;
    }
}
