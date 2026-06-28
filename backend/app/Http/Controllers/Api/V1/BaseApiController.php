<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

abstract class BaseApiController extends Controller
{
    protected function success(mixed $data = null, int $status = 200, array $meta = [], ?Request $request = null): JsonResponse
    {
        return ApiResponse::success($data, $status, $meta, $request);
    }

    protected function error(
        string $message,
        int $status = 400,
        string $code = 'API_ERROR',
        mixed $details = null,
        array $meta = [],
        ?Request $request = null
    ): JsonResponse {
        return ApiResponse::error($message, $status, $code, $details, $meta, $request);
    }

    protected function authenticatedUser(Request $request): ?User
    {
        $user = $request->user();

        if (! $user instanceof User || ! (bool) $user->ativo) {
            return null;
        }

        return $user;
    }

    protected function unauthenticatedResponse(Request $request): JsonResponse
    {
        return $this->error(
            'Usuário não autenticado.',
            401,
            'AUTH_REQUIRED',
            null,
            request: $request
        );
    }

    protected function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }
}
