<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Services\Profile\ProfilePhotoImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class UserPhotoController extends BaseApiController
{
    public function __construct(
        private readonly ProfilePhotoImageService $profilePhotoImageService
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $request->validate([
            'photo_file' => ['required', 'file', 'image', 'max:4096', 'mimes:png,jpg,jpeg,webp'],
        ], [], [
            'photo_file' => 'foto de perfil',
        ]);

        try {
            $this->profilePhotoImageService->update($user, $request->file('photo_file'), $user);
        } catch (Throwable $exception) {
            report($exception);
            $message = $exception instanceof \InvalidArgumentException
                ? $exception->getMessage()
                : 'Não foi possível salvar a foto de perfil agora.';

            return $this->error($message, 422, 'PROFILE_PHOTO_UPLOAD_FAILED', request: $request);
        }

        $user->refresh();
        logger()->notice('[PROFILE_PHOTO] Foto de perfil atualizada', [
            'user_id' => (int) $user->id,
        ]);

        return $this->success([
            'has_photo' => true,
            'preview_url' => $this->previewUrl($user),
        ], request: $request);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $this->profilePhotoImageService->remove($user, $user);

        logger()->notice('[PROFILE_PHOTO] Foto de perfil removida', [
            'user_id' => (int) $user->id,
        ]);

        return $this->success(['has_photo' => false], request: $request);
    }

    public function image(Request $request): BinaryFileResponse|JsonResponse
    {
        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $path = $this->profilePhotoImageService->absolutePath($user);
        if ($path === null) {
            return $this->error('Foto de perfil não cadastrada.', 404, 'PROFILE_PHOTO_NOT_FOUND', request: $request);
        }

        return response()->file($path, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline; filename="foto-perfil.jpg"',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function previewUrl(User $user): string
    {
        $version = $user->updated_at?->timestamp ?? time();

        return '/api/v1/auth/photo/image?v=' . rawurlencode((string) $version);
    }
}
