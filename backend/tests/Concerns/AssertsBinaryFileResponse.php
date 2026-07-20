<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait AssertsBinaryFileResponse
{
    private function assertBinaryResponseParity(
        TestResponse $response,
        int $expectedStatus,
        string $expectedMimeType,
        string $expectedDisposition,
        string $expectedDownloadName,
        string $expectedSha256,
        int $expectedSize
    ): void {
        $response->assertStatus($expectedStatus)->assertHeader('Content-Type', $expectedMimeType);
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith($expectedDisposition.';', $disposition);
        $this->assertStringContainsString($expectedDownloadName, $disposition);

        $baseResponse = $response->baseResponse;
        $content = match (true) {
            $baseResponse instanceof BinaryFileResponse => file_get_contents($baseResponse->getFile()->getPathname()),
            $baseResponse instanceof StreamedResponse => $response->streamedContent(),
            default => $response->getContent(),
        };
        $this->assertIsString($content);
        $this->assertSame($expectedSize, strlen($content));
        $this->assertSame($expectedSha256, hash('sha256', $content));
    }
}
