<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Services\Signatures\SignatureImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class UserSignatureController extends BaseApiController
{
    public function __construct(
        private readonly SignatureImageService $signatureImageService
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! (bool) $user->ativo) {
            return $this->error('Usuário não autenticado.', 401, 'AUTH_REQUIRED', request: $request);
        }

        $signature = $this->signatureImageService->activeFor($user);

        return $this->success([
            'registered' => $signature !== null,
            'signature' => $signature === null ? null : [
                'id' => (int) $signature->id,
                'origin' => (string) $signature->origem,
                'width' => (int) $signature->largura,
                'height' => (int) $signature->altura,
                'updated_at' => $signature->updated_at?->toIso8601String(),
                'preview_url' => '/api/v1/auth/signature/image?v=' . rawurlencode((string) $signature->hash_sha256),
            ],
        ], request: $request);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! (bool) $user->ativo) {
            return $this->error('Usuário não autenticado.', 401, 'AUTH_REQUIRED', request: $request);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:200'],
            'origin' => ['required', 'string', 'in:upload,desenho'],
            'signature_file' => ['nullable', 'file', 'max:2048', 'mimes:png,jpg,jpeg,webp'],
            'signature_data' => ['nullable', 'string', 'max:3000000'],
        ]);

        $key = 'signature-enroll:' . (int) $user->id . '|' . (string) $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $this->error('Muitas tentativas. Aguarde e tente novamente.', 429, 'SIGNATURE_RATE_LIMITED', [
                'retry_after' => RateLimiter::availableIn($key),
            ], request: $request);
        }

        if (! Hash::check((string) $validated['current_password'], (string) $user->senha)) {
            RateLimiter::hit($key, 300);
            return $this->error('A senha atual não confere.', 422, 'SIGNATURE_INVALID_PASSWORD', request: $request);
        }
        RateLimiter::clear($key);

        $source = (string) $validated['origin'] === 'upload'
            ? $request->file('signature_file')
            : (string) ($validated['signature_data'] ?? '');
        if ($source === null || $source === '') {
            return $this->error('Informe o arquivo ou desenhe a assinatura.', 422, 'SIGNATURE_SOURCE_REQUIRED', request: $request);
        }

        try {
            $result = $this->signatureImageService->enroll(
                $user,
                $source,
                (string) $validated['origin'],
                $user,
                (string) $request->ip()
            );
        } catch (Throwable $exception) {
            report($exception);
            $message = $exception instanceof \InvalidArgumentException
                ? $exception->getMessage()
                : 'Não foi possível salvar a assinatura agora.';

            return $this->error($message, 422, 'SIGNATURE_ENROLL_FAILED', request: $request);
        }

        $signature = $result['signature'];
        logger()->notice('[SIGNATURE] Assinatura de usuário cadastrada', [
            'user_id' => (int) $user->id,
            'signature_id' => (int) $signature->id,
            'origin' => (string) $signature->origem,
            'replaced' => (bool) $result['replaced'],
        ]);

        return $this->success([
            'registered' => true,
            'replaced' => (bool) $result['replaced'],
            'signature' => [
                'id' => (int) $signature->id,
                'origin' => (string) $signature->origem,
                'preview_url' => '/api/v1/auth/signature/image?v=' . rawurlencode((string) $signature->hash_sha256),
            ],
        ], request: $request);
    }

    public function image(Request $request): BinaryFileResponse|JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! (bool) $user->ativo) {
            return $this->error('Usuário não autenticado.', 401, 'AUTH_REQUIRED', request: $request);
        }

        $signature = $this->signatureImageService->activeFor($user);
        $path = $signature ? $this->signatureImageService->absolutePath($signature) : null;
        if ($signature === null || $path === null) {
            return $this->error('Assinatura não cadastrada.', 404, 'SIGNATURE_NOT_FOUND', request: $request);
        }

        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="assinatura.png"',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
