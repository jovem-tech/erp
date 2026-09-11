<?php

namespace Tests\Unit\Photos;

use App\DTO\Photos\OperationalPhotoMetadata;
use App\Exceptions\OperationalPhotoException;
use App\Services\Photos\OperationalPhotoInspector;
use App\Services\Photos\OperationalPhotoOptimizer;
use App\Services\Photos\VipsCommandRunner;
use Illuminate\Http\UploadedFile;
use Mockery;
use Tests\TestCase;

class OperationalPhotoOptimizerTest extends TestCase
{
    /** @var list<string> */
    private array $sources = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('operational-photos.max_input_bytes', 20 * 1024 * 1024);
        config()->set('operational-photos.max_pixels', 60_000_000);
        config()->set('operational-photos.max_output_dimension', 2560);
        config()->set('operational-photos.target_bytes', 400 * 1024);
        config()->set('operational-photos.hard_limit_bytes', 700 * 1024);
        config()->set('operational-photos.target_dimensions', [2560, 2304, 2048]);
        config()->set('operational-photos.fallback_dimensions', [2560, 2304, 2048, 1600]);
        config()->set('operational-photos.stage_one_min_quality', 60);
        config()->set('operational-photos.stage_one_max_quality', 72);
        config()->set('operational-photos.stage_two_min_quality', 50);
        config()->set('operational-photos.stage_two_max_quality', 72);
    }

    protected function tearDown(): void
    {
        foreach ($this->sources as $source) {
            @unlink($source);
        }

        parent::tearDown();
    }

    public function test_preserves_a_90_kb_safe_source_when_avif_is_larger(): void
    {
        $file = $this->uploadedJpeg(90 * 1024);
        $optimizer = $this->optimizer($file, static fn (): int => 120 * 1024);

        $result = $optimizer->optimize($file);

        $this->assertTrue($result->sourcePreserved);
        $this->assertFalse($result->temporary);
        $this->assertSame(90 * 1024, $result->sizeBytes);
        $this->assertSame($file->getRealPath(), $result->path);
    }

    public function test_prefers_an_avif_that_meets_the_400_kb_target(): void
    {
        $file = $this->uploadedJpeg(900 * 1024);
        $optimizer = $this->optimizer($file, static fn (): int => 300 * 1024);

        $result = $optimizer->optimize($file);

        $this->assertFalse($result->sourcePreserved);
        $this->assertSame('image/avif', $result->mimeType);
        $this->assertLessThanOrEqual(400 * 1024, $result->sizeBytes);

        $optimizer->cleanup($result);
        $this->assertFileDoesNotExist($result->path);
    }

    public function test_uses_the_exceptional_band_between_400_and_700_kb(): void
    {
        $file = $this->uploadedJpeg(900 * 1024);
        $optimizer = $this->optimizer($file, static fn (): int => 500 * 1024);

        $result = $optimizer->optimize($file);

        $this->assertGreaterThan(400 * 1024, $result->sizeBytes);
        $this->assertLessThanOrEqual(700 * 1024, $result->sizeBytes);
        $optimizer->cleanup($result);
    }

    public function test_rejects_when_the_quality_floor_cannot_meet_700_kb(): void
    {
        $file = $this->uploadedJpeg(900 * 1024);
        $optimizer = $this->optimizer($file, static fn (): int => 800 * 1024);

        try {
            $optimizer->optimize($file);
            $this->fail('Expected PHOTO_CANNOT_MEET_POLICY.');
        } catch (OperationalPhotoException $exception) {
            $this->assertSame('PHOTO_CANNOT_MEET_POLICY', $exception->errorCode);
        }
    }

    private function uploadedJpeg(int $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'operational-photo-test-');
        $this->assertIsString($path);
        file_put_contents($path, str_repeat('j', $bytes));
        $this->sources[] = $path;

        return new UploadedFile($path, 'equipment.jpg', 'image/jpeg', null, true);
    }

    /** @param callable(int, int): int $encodedSize */
    private function optimizer(UploadedFile $sourceFile, callable $encodedSize): OperationalPhotoOptimizer
    {
        $sourcePath = (string) $sourceFile->getRealPath();
        $sourceMetadata = new OperationalPhotoMetadata(
            'image/jpeg',
            (int) filesize($sourcePath),
            2400,
            1600,
            1,
            1,
            false,
        );

        $runner = Mockery::mock(VipsCommandRunner::class);
        $runner->shouldReceive('assertAvailable')->once();
        $runner->shouldReceive('thumbnail')->andReturnUsing(
            static function (string $_source, string $target, int $dimension, int $quality) use ($encodedSize): void {
                file_put_contents($target, str_repeat('a', $encodedSize($dimension, $quality)));
            },
        );

        $inspector = Mockery::mock(OperationalPhotoInspector::class);
        $inspector->shouldReceive('inspect')->andReturnUsing(
            static function (string $path) use ($sourcePath, $sourceMetadata): OperationalPhotoMetadata {
                if ($path === $sourcePath) {
                    return $sourceMetadata;
                }

                return new OperationalPhotoMetadata(
                    'image/avif',
                    (int) filesize($path),
                    2400,
                    1600,
                    1,
                    1,
                    false,
                );
            },
        );

        return new OperationalPhotoOptimizer($inspector, $runner);
    }
}
