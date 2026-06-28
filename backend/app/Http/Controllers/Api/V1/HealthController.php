<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends BaseApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $throwable) {
            logger()->error('[API V1][HEALTH] Banco indisponivel', [
                'message' => $throwable->getMessage(),
            ]);

            return $this->error(
                'Banco de dados indisponivel.',
                503,
                'HEALTH_DATABASE_UNAVAILABLE',
                ['database' => 'unavailable'],
                request: $request
            );
        }

        return $this->success([
            'status' => 'ok',
            'service' => config('app.name'),
            'version' => config('app.version'),
            'environment' => app()->environment(),
            'laravel' => Application::VERSION,
            'database' => 'ok',
        ], 200, [
            'checked_at' => now()->toIso8601String(),
        ], $request);
    }
}
