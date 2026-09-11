<?php

namespace Tests\Feature\Photos;

use App\Exceptions\OperationalPhotoException;
use App\Services\Photos\OperationalPhotoInspector;
use App\Services\Photos\OperationalPhotoOptimizer;
use App\Services\Photos\OperationalPhotoPdfRenderer;
use App\Services\Photos\VipsCommandRunner;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('image-codecs')]
class OperationalPhotoRealCodecTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->app->make(VipsCommandRunner::class)->preflight();
        } catch (OperationalPhotoException $exception) {
            $this->markTestSkipped('libvips com HEIC/AVIF não está disponível: '.$exception->errorCode);
        }
    }

    public function test_real_heic_is_normalized_to_a_safe_avif(): void
    {
        $fixture = (string) getenv('OPERATIONAL_PHOTO_REAL_HEIC_FIXTURE');
        if ($fixture === '' || ! is_file($fixture)) {
            $this->markTestSkipped('Defina OPERATIONAL_PHOTO_REAL_HEIC_FIXTURE com uma foto HEIC real.');
        }

        $optimizer = $this->app->make(OperationalPhotoOptimizer::class);
        $inspector = $this->app->make(OperationalPhotoInspector::class);
        $upload = new UploadedFile($fixture, 'IMG_TEST.HEIC', 'image/heic', null, true);

        $result = $optimizer->optimize($upload);

        try {
            $metadata = $inspector->inspect($result->path);

            $this->assertSame('image/avif', $result->mimeType);
            $this->assertSame('avif', $result->extension);
            $this->assertFalse($result->sourcePreserved);
            $this->assertLessThanOrEqual(700 * 1024, $result->sizeBytes);
            $this->assertLessThanOrEqual(2560, $metadata->longestSide());
            $this->assertSame(1, $metadata->pageCount);
            $this->assertSame(1, $metadata->orientation);
            $this->assertFalse($metadata->hasSensitiveMetadata);
        } finally {
            $path = $result->path;
            $optimizer->cleanup($result);
            $this->assertFileDoesNotExist($path);
        }
    }

    public function test_a_forty_eight_megapixel_photo_is_downscaled_without_being_rejected(): void
    {
        $fixture = (string) getenv('OPERATIONAL_PHOTO_48MP_FIXTURE');
        if ($fixture === '' || ! is_file($fixture)) {
            $this->markTestSkipped('Defina OPERATIONAL_PHOTO_48MP_FIXTURE com uma foto de 48 MP.');
        }

        $optimizer = $this->app->make(OperationalPhotoOptimizer::class);
        $inspector = $this->app->make(OperationalPhotoInspector::class);
        $upload = new UploadedFile($fixture, 'camera-48mp.png', 'image/png', null, true);

        $result = $optimizer->optimize($upload);

        try {
            $metadata = $inspector->inspect($result->path);

            $this->assertSame('image/avif', $result->mimeType);
            $this->assertLessThanOrEqual(400 * 1024, $result->sizeBytes);
            $this->assertLessThanOrEqual(2560, $metadata->longestSide());
            $this->assertLessThan(48_000_000, $metadata->width * $metadata->height);
        } finally {
            $path = $result->path;
            $optimizer->cleanup($result);
            $this->assertFileDoesNotExist($path);
        }
    }

    public function test_exif_orientation_is_applied_and_removed(): void
    {
        $fixture = (string) getenv('OPERATIONAL_PHOTO_ORIENTATION_FIXTURE');
        if ($fixture === '' || ! is_file($fixture)) {
            $this->markTestSkipped('Defina OPERATIONAL_PHOTO_ORIENTATION_FIXTURE com uma foto EXIF rotacionada.');
        }

        $optimizer = $this->app->make(OperationalPhotoOptimizer::class);
        $inspector = $this->app->make(OperationalPhotoInspector::class);
        $source = $inspector->inspect($fixture);
        $this->assertSame(6, $source->orientation);
        $this->assertTrue($source->hasSensitiveMetadata);

        $result = $optimizer->optimize(
            new UploadedFile($fixture, 'portrait.jpg', 'image/jpeg', null, true)
        );

        try {
            $metadata = $inspector->inspect($result->path);

            $this->assertSame(1, $metadata->orientation);
            $this->assertFalse($metadata->hasSensitiveMetadata);
            $this->assertGreaterThan($metadata->width, $metadata->height);
            $this->assertLessThanOrEqual(400 * 1024, $result->sizeBytes);
        } finally {
            $path = $result->path;
            $optimizer->cleanup($result);
            $this->assertFileDoesNotExist($path);
        }
    }

    public function test_avif_is_rendered_as_a_temporary_jpeg_for_pdf(): void
    {
        $fixture = (string) getenv('OPERATIONAL_PHOTO_AVIF_FIXTURE');
        if ($fixture === '' || ! is_file($fixture)) {
            $this->markTestSkipped('Defina OPERATIONAL_PHOTO_AVIF_FIXTURE com uma imagem AVIF.');
        }

        $temporaryDirectory = storage_path('app/private/operational-photo-tmp');
        $before = glob($temporaryDirectory.DIRECTORY_SEPARATOR.'*.jpg') ?: [];
        $bytes = $this->app->make(OperationalPhotoPdfRenderer::class)->jpegBytes($fixture);
        $after = glob($temporaryDirectory.DIRECTORY_SEPARATOR.'*.jpg') ?: [];

        $this->assertIsString($bytes);
        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
        $dimensions = getimagesizefromstring($bytes);
        $this->assertIsArray($dimensions);
        $this->assertLessThanOrEqual(1920, max((int) $dimensions[0], (int) $dimensions[1]));
        $this->assertSame($before, $after);
    }
}
