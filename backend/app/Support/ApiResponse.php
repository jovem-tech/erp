<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ApiResponse
{
    public static function success(mixed $data = null, int $status = 200, array $meta = [], ?Request $request = null): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $data,
            'error' => null,
            'meta' => self::meta($request, $meta),
        ], $status, [], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function error(
        string $message,
        int $status = 400,
        string $code = 'API_ERROR',
        mixed $details = null,
        array $meta = [],
        ?Request $request = null
    ): JsonResponse {
        return response()->json([
            'status' => 'error',
            'data' => null,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'meta' => self::meta($request, $meta),
        ], $status, [], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function meta(?Request $request = null, array $meta = []): array
    {
        if ($request === null && app()->bound('request')) {
            $request = request();
        }

        $requestId = '';
        if ($request instanceof Request) {
            $requestId = trim((string) $request->header('X-Request-Id', ''));
        }

        if ($requestId === '') {
            $requestId = 'req_' . Str::uuid()->toString();
        }

        return array_merge([
            'timestamp' => now()->toIso8601String(),
            'request_id' => $requestId,
        ], $meta);
    }
}
