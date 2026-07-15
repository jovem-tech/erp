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

    /**
     * Traduz o resultado de App\Services\Auth\AdminCredentialVerifier::verify()
     * numa resposta de erro pronta — reaproveitado por todo fluxo que exige
     * confirmação de um administrador (cancelar baixa de OS, editar orçamento
     * de OS encerrada, cancelar título de OS encerrada). Retorna null quando a
     * verificação foi bem-sucedida (o chamador segue o fluxo normal).
     *
     * @param array{ok: bool, admin?: User, error?: string, retry_after?: int} $verification
     */
    protected function respondToAdminVerification(
        array $verification,
        Request $request,
        string $rateLimitedCode,
        string $invalidCode,
        string $rateLimitedMessage = 'Muitas tentativas de verificação de administrador. Aguarde um pouco e tente novamente.',
        string $invalidMessage = 'Credenciais de administrador inválidas.'
    ): ?JsonResponse {
        if (($verification['error'] ?? null) === 'rate_limited') {
            return $this->error(
                $rateLimitedMessage,
                429,
                $rateLimitedCode,
                ['retry_after' => $verification['retry_after'] ?? null],
                request: $request
            );
        }

        if (! ($verification['ok'] ?? false)) {
            return $this->error($invalidMessage, 422, $invalidCode, null, request: $request);
        }

        return null;
    }
}
