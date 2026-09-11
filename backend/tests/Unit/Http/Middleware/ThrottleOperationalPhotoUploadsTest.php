<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\ThrottleOperationalPhotoUploads;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ThrottleOperationalPhotoUploadsTest extends TestCase
{
    public function test_ninth_photo_request_for_the_same_user_and_ip_is_rejected(): void
    {
        $userId = 91234;
        $ip = '198.51.100.24';
        $key = 'operational-photo-upload:'.hash('sha256', $userId.'|'.$ip);
        RateLimiter::clear($key);

        try {
            for ($attempt = 1; $attempt <= 9; $attempt++) {
                $request = $this->photoRequest($userId, $ip);
                $response = app(ThrottleOperationalPhotoUploads::class)->handle(
                    $request,
                    static fn (): Response => response()->json(['ok' => true]),
                );

                if ($attempt <= 8) {
                    $this->assertSame(200, $response->getStatusCode());
                    $this->assertSame((string) (8 - $attempt), $response->headers->get('X-RateLimit-Remaining'));
                } else {
                    $this->assertSame(429, $response->getStatusCode());
                    $this->assertSame('PHOTO_RATE_LIMITED', json_decode((string) $response->getContent(), true)['error']['code']);
                    $this->assertNotNull($response->headers->get('Retry-After'));
                }
            }
        } finally {
            RateLimiter::clear($key);
        }
    }

    public function test_request_without_photos_does_not_consume_the_limit(): void
    {
        $request = Request::create('/api/v1/orders', 'POST');
        $response = app(ThrottleOperationalPhotoUploads::class)->handle(
            $request,
            static fn (): Response => response()->json(['ok' => true]),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->headers->has('X-RateLimit-Limit'));
    }

    private function photoRequest(int $userId, string $ip): Request
    {
        $file = UploadedFile::fake()->image('equipment.jpg');
        $request = Request::create(
            '/api/v1/orders',
            'POST',
            [],
            [],
            ['fotos' => [$file]],
            ['REMOTE_ADDR' => $ip],
        );
        $request->setUserResolver(static fn (): object => new class($userId)
        {
            public function __construct(private readonly int $id) {}

            public function getAuthIdentifier(): int
            {
                return $this->id;
            }
        });

        return $request;
    }
}
