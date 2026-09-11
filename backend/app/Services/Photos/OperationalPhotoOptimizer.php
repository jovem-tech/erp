<?php

namespace App\Services\Photos;

use App\DTO\Photos\OperationalPhotoMetadata;
use App\DTO\Photos\OptimizedOperationalPhoto;
use App\Exceptions\OperationalPhotoException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class OperationalPhotoOptimizer
{
    public function __construct(
        private readonly OperationalPhotoInspector $inspector,
        private readonly VipsCommandRunner $runner,
    ) {}

    /**
     * @param  array<int, mixed>  $files
     * @return list<OptimizedOperationalPhoto>
     */
    public function optimizeMany(array $files): array
    {
        $uploadedFiles = array_values(array_filter(
            $files,
            static fn (mixed $file): bool => $file instanceof UploadedFile && $file->isValid(),
        ));

        if (count($uploadedFiles) > (int) config('operational-photos.max_files_per_group', 4)) {
            throw new OperationalPhotoException(
                'PHOTO_FORMAT_UNSUPPORTED',
                'Envie no máximo quatro fotos por grupo.',
            );
        }

        $optimized = [];
        try {
            foreach ($uploadedFiles as $file) {
                $optimized[] = $this->optimize($file);
            }
        } catch (\Throwable $exception) {
            $this->cleanupMany($optimized);

            throw $exception;
        }

        return $optimized;
    }

    public function optimize(UploadedFile $file): OptimizedOperationalPhoto
    {
        $startedAt = hrtime(true);
        $deadline = $startedAt + ((int) config('operational-photos.timeout_seconds', 12) * 1_000_000_000);
        $this->runner->assertAvailable();

        $sourcePath = $file->getRealPath();
        if ($sourcePath === false) {
            throw new OperationalPhotoException('PHOTO_FORMAT_UNSUPPORTED', 'A foto enviada não pôde ser lida.');
        }

        $source = $this->inspector->inspect($sourcePath);
        $extension = strtolower($file->getClientOriginalExtension());
        if (! OperationalPhotoInspector::extensionMatchesMime($extension, $source->mimeType)) {
            throw new OperationalPhotoException(
                'PHOTO_FORMAT_UNSUPPORTED',
                'A extensão da foto não corresponde ao conteúdo real do arquivo.',
            );
        }

        $avif = $this->findAvifCandidate($sourcePath, $source, $deadline);
        $preserveSource = $this->canPreserveSource($source) && $source->sizeBytes <= $avif->sizeBytes;

        if ($preserveSource) {
            $this->cleanup($avif);
            $result = new OptimizedOperationalPhoto(
                $sourcePath,
                $extension,
                $source->mimeType,
                $source->sizeBytes,
                $file->getClientOriginalName(),
                false,
                true,
            );
        } else {
            $result = new OptimizedOperationalPhoto(
                $avif->path,
                'avif',
                'image/avif',
                $avif->sizeBytes,
                $file->getClientOriginalName(),
                true,
                false,
            );
        }

        Log::info('Operational photo optimized.', [
            'input_mime' => $source->mimeType,
            'input_bytes' => $source->sizeBytes,
            'output_mime' => $result->mimeType,
            'output_bytes' => $result->sizeBytes,
            'source_preserved' => $result->sourcePreserved,
            'compression_ratio' => round($result->sizeBytes / max(1, $source->sizeBytes), 4),
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
        ]);

        return $result;
    }

    /** @param iterable<OptimizedOperationalPhoto> $photos */
    public function cleanupMany(iterable $photos): void
    {
        foreach ($photos as $photo) {
            $this->cleanup($photo);
        }
    }

    public function cleanup(OptimizedOperationalPhoto $photo): void
    {
        if ($photo->temporary && is_file($photo->path)) {
            @unlink($photo->path);
        }
    }

    private function findAvifCandidate(
        string $sourcePath,
        OperationalPhotoMetadata $source,
        int $deadline,
    ): OptimizedOperationalPhoto {
        $candidate = $this->search(
            $sourcePath,
            $source,
            (array) config('operational-photos.target_dimensions'),
            (int) config('operational-photos.stage_one_min_quality'),
            (int) config('operational-photos.stage_one_max_quality'),
            (int) config('operational-photos.target_bytes'),
            $deadline,
            true,
        );

        $candidate ??= $this->search(
            $sourcePath,
            $source,
            (array) config('operational-photos.fallback_dimensions'),
            (int) config('operational-photos.stage_two_min_quality'),
            (int) config('operational-photos.stage_two_max_quality'),
            (int) config('operational-photos.hard_limit_bytes'),
            $deadline,
            false,
        );

        if ($candidate === null) {
            throw new OperationalPhotoException(
                'PHOTO_CANNOT_MEET_POLICY',
                'Não foi possível reduzir a foto sem ultrapassar o piso de qualidade técnica.',
            );
        }

        try {
            $metadata = $this->inspector->inspect($candidate->path);
            if (
                $metadata->mimeType !== 'image/avif'
                || $metadata->pageCount !== 1
                || $metadata->longestSide() > (int) config('operational-photos.max_output_dimension')
                || $metadata->sizeBytes > (int) config('operational-photos.hard_limit_bytes')
            ) {
                throw new OperationalPhotoException(
                    'PHOTO_PROCESSING_FAILED',
                    'O resultado da otimização não passou pela validação final.',
                );
            }
        } catch (\Throwable $exception) {
            $this->cleanup($candidate);

            throw $exception;
        }

        return $candidate;
    }

    /**
     * @param  list<int|string>  $dimensions
     */
    private function search(
        string $sourcePath,
        OperationalPhotoMetadata $source,
        array $dimensions,
        int $minQuality,
        int $maxQuality,
        int $byteLimit,
        int $deadline,
        bool $probeSmallestFirst,
    ): ?OptimizedOperationalPhoto {
        $effectiveDimensions = [];
        foreach ($dimensions as $dimension) {
            $effectiveDimensions[] = min((int) $dimension, $source->longestSide());
        }
        $effectiveDimensions = array_values(array_unique(array_filter($effectiveDimensions)));

        if ($probeSmallestFirst && count($effectiveDimensions) > 1) {
            $smallestDimension = (int) end($effectiveDimensions);
            $floorProbe = $this->encodeCandidate(
                $sourcePath,
                $smallestDimension,
                $minQuality,
                $deadline,
            );
            $smallestCanMeetLimit = $floorProbe->sizeBytes <= $byteLimit;
            $this->cleanup($floorProbe);

            // A menor resolução no piso de qualidade é a última chance deste
            // estágio. Se ela não couber, tentativas maiores só gastariam o
            // orçamento total de 12 segundos antes do estágio excepcional.
            if (! $smallestCanMeetLimit) {
                return null;
            }
        }

        foreach ($effectiveDimensions as $dimension) {
            $best = $this->encodeCandidate(
                $sourcePath,
                $dimension,
                $minQuality,
                $deadline,
            );

            if ($best->sizeBytes > $byteLimit) {
                $this->cleanup($best);

                continue;
            }

            $low = $minQuality + 1;
            $high = $maxQuality;
            $qualityAttempts = 0;

            while ($low <= $high && $qualityAttempts < 3) {
                $qualityAttempts++;
                $quality = intdiv($low + $high, 2);
                try {
                    $candidate = $this->encodeCandidate($sourcePath, $dimension, $quality, $deadline);
                } catch (\Throwable $exception) {
                    $this->cleanup($best);

                    throw $exception;
                }

                if ($candidate->sizeBytes <= $byteLimit) {
                    $this->cleanup($best);
                    $best = $candidate;
                    $low = $quality + 1;
                } else {
                    $this->cleanup($candidate);
                    $high = $quality - 1;
                }
            }

            return $best;
        }

        return null;
    }

    private function encodeCandidate(
        string $sourcePath,
        int $dimension,
        int $quality,
        int $deadline,
    ): OptimizedOperationalPhoto {
        $path = $this->temporaryPath('avif');

        try {
            $this->runner->thumbnail(
                $sourcePath,
                $path,
                $dimension,
                $quality,
                'avif',
                $this->remainingSeconds($deadline),
            );

            return new OptimizedOperationalPhoto(
                $path,
                'avif',
                'image/avif',
                (int) filesize($path),
                '',
                true,
                false,
            );
        } catch (\Throwable $exception) {
            @unlink($path);

            throw $exception;
        }
    }

    private function remainingSeconds(int $deadline): float
    {
        $remaining = ($deadline - hrtime(true)) / 1_000_000_000;
        if ($remaining <= 0.1) {
            throw new OperationalPhotoException(
                'PHOTO_PROCESSING_TIMEOUT',
                'A foto excedeu o tempo seguro de processamento.',
            );
        }

        return $remaining;
    }

    private function canPreserveSource(OperationalPhotoMetadata $source): bool
    {
        return in_array($source->mimeType, ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], true)
            && $source->sizeBytes <= (int) config('operational-photos.hard_limit_bytes')
            && $source->longestSide() <= (int) config('operational-photos.max_output_dimension')
            && $source->orientation === 1
            && ! $source->hasSensitiveMetadata;
    }

    private function temporaryPath(string $extension): string
    {
        $directory = (string) config(
            'operational-photos.temporary_directory',
            storage_path('app/private/operational-photo-tmp'),
        );
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new OperationalPhotoException(
                'PHOTO_PROCESSOR_UNAVAILABLE',
                'Não foi possível criar a área privada de processamento de fotos.',
                503,
            );
        }
        @chmod($directory, 0700);

        return $directory.DIRECTORY_SEPARATOR.Str::uuid()->toString().'.'.$extension;
    }
}
