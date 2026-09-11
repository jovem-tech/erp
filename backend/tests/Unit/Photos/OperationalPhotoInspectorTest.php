<?php

namespace Tests\Unit\Photos;

use App\Exceptions\OperationalPhotoException;
use App\Services\Photos\OperationalPhotoInspector;
use App\Services\Photos\VipsCommandRunner;
use Mockery;
use Tests\TestCase;

class OperationalPhotoInspectorTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('operational-photos.max_input_bytes', 20 * 1024 * 1024);
        config()->set('operational-photos.max_pixels', 60_000_000);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_marks_metadata_that_prevents_source_preservation(): void
    {
        $path = $this->pngFile();
        $inspector = $this->inspector(<<<'HEADER'
width: 1200
height: 800
n-pages: 1
orientation: 1
exif-data: 182 bytes of binary data
HEADER);

        $metadata = $inspector->inspect($path);

        $this->assertSame('image/png', $metadata->mimeType);
        $this->assertTrue($metadata->hasSensitiveMetadata);
    }

    public function test_marks_format_specific_comments_as_sensitive_metadata(): void
    {
        $path = $this->pngFile();
        $inspector = $this->inspector(<<<'HEADER'
width: 1200
height: 800
n-pages: 1
orientation: 1
png-comment-0: operator note
HEADER);

        $this->assertTrue($inspector->inspect($path)->hasSensitiveMetadata);
    }

    public function test_rejects_multiframe_images(): void
    {
        $path = $this->pngFile();
        $inspector = $this->inspector("width: 1200\nheight: 800\nn-pages: 2\norientation: 1\n");

        $this->expectException(OperationalPhotoException::class);
        $this->expectExceptionMessage('múltiplos quadros');

        $inspector->inspect($path);
    }

    public function test_rejects_images_over_sixty_megapixels(): void
    {
        $path = $this->pngFile();
        $inspector = $this->inspector("width: 10000\nheight: 7000\nn-pages: 1\norientation: 1\n");

        try {
            $inspector->inspect($path);
            $this->fail('Expected oversized image rejection.');
        } catch (OperationalPhotoException $exception) {
            $this->assertSame('PHOTO_FORMAT_UNSUPPORTED', $exception->errorCode);
            $this->assertStringContainsString('60 megapixels', $exception->getMessage());
        }
    }

    public function test_rejects_content_with_a_false_image_extension(): void
    {
        $path = $this->temporaryFile("not an image\n");
        $runner = Mockery::mock(VipsCommandRunner::class);
        $runner->shouldNotReceive('header');

        try {
            (new OperationalPhotoInspector($runner))->inspect($path);
            $this->fail('Expected spoofed content rejection.');
        } catch (OperationalPhotoException $exception) {
            $this->assertSame('PHOTO_FORMAT_UNSUPPORTED', $exception->errorCode);
        }
    }

    public function test_detects_an_iphone_heic_brand_from_the_file_header(): void
    {
        $path = $this->temporaryFile(pack('N', 24).'ftypheic'.str_repeat("\0", 12));

        $this->assertSame('image/heic', OperationalPhotoInspector::detectMimeType($path));
        $this->assertTrue(OperationalPhotoInspector::extensionMatchesMime('HEIC', 'image/heic'));
        $this->assertTrue(OperationalPhotoInspector::extensionMatchesMime('heif', 'image/heic'));
    }

    private function inspector(string $header): OperationalPhotoInspector
    {
        $runner = Mockery::mock(VipsCommandRunner::class);
        $runner->shouldReceive('header')->once()->andReturn($header);

        return new OperationalPhotoInspector($runner);
    }

    private function pngFile(): string
    {
        return $this->temporaryFile((string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        ));
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'operational-photo-inspector-');
        $this->assertIsString($path);
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
