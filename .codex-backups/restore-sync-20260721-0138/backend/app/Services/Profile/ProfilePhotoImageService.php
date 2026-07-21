<?php

namespace App\Services\Profile;

use App\DTO\Files\FileContext;
use App\Enums\Files\FileCategory;
use App\Enums\Files\FileOrigin;
use App\Models\Files\ManagedFile;
use App\Models\User;
use App\Services\Files\FileStateMachine;
use App\Services\Files\LegacyCompatibleFileAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ProfilePhotoImageService
{
    public const MAX_BYTES = 4_194_304;

    private const MAX_DIMENSION = 6000;

    private const OUTPUT_SIZE = 512;

    public function __construct(
        private readonly LegacyCompatibleFileAdapter $fileManager,
        private readonly FileStateMachine $fileStateMachine
    ) {}

    public function update(User $user, UploadedFile $file, User $actor): string
    {
        $bytes = $this->readUploadedFile($file);
        $normalized = $this->normalize($bytes);
        $path = sprintf(
            'private/usuarios/%d/foto-perfil-%s.jpg',
            (int) $user->id,
            (string) Str::random(10)
        );
        $previousPath = trim((string) $user->foto);

        if (! Storage::disk('local')->put($path, $normalized)) {
            throw new RuntimeException('Não foi possível armazenar a foto de perfil.');
        }

        try {
            DB::transaction(function () use ($user, $actor, $path): void {
                $user->forceFill(['foto' => $path])->save();

                $this->fileManager->synchronizeExisting(
                    new FileContext(
                        category: FileCategory::UserProfilePhoto,
                        origin: FileOrigin::Upload,
                        operationKey: 'user-profile-photo:' . (int) $user->id . ':' . hash('sha256', $path),
                        subjectType: 'user',
                        subjectId: (int) $user->id,
                        relation: 'profile_photo',
                        createdBy: (int) $actor->id
                    ),
                    'local',
                    $path,
                    'usuarios',
                    'foto',
                    (string) $user->id
                );
            }, 3);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        if ($previousPath !== '' && $previousPath !== $path) {
            Storage::disk('local')->delete($previousPath);
            $this->retireCatalogedFile($previousPath, (int) $actor->id);
        }

        return $path;
    }

    public function remove(User $user, User $actor): void
    {
        $path = trim((string) $user->foto);
        $user->forceFill(['foto' => null])->save();

        if ($path !== '') {
            Storage::disk('local')->delete($path);
            $this->retireCatalogedFile($path, (int) $actor->id);
        }
    }

    /**
     * Marca como "trashed" no Gerenciador de Arquivos o registro cadastrado
     * para uma foto de perfil substituída ou removida — sem isso, o binário
     * some do disco mas o card continua "Ativo" na listagem principal.
     */
    private function retireCatalogedFile(string $storagePath, int $actorId): void
    {
        $managedFile = ManagedFile::query()
            ->where('category', FileCategory::UserProfilePhoto->value)
            ->where('storage_disk', 'local')
            ->where('storage_key', $storagePath)
            ->first();

        if ($managedFile === null || $managedFile->lifecycle_status->value !== 'active') {
            return;
        }

        try {
            $this->fileStateMachine->trash($managedFile, $actorId, 'Foto de perfil substituída ou removida pelo usuário.');
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function absolutePath(User $user): ?string
    {
        $path = trim((string) $user->foto);
        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return Storage::disk('local')->path($path);
    }

    private function readUploadedFile(UploadedFile $file): string
    {
        if (! $file->isValid()) {
            throw new InvalidArgumentException('O arquivo de foto não pôde ser lido.');
        }
        if (($file->getSize() ?? 0) > self::MAX_BYTES) {
            throw new InvalidArgumentException('A foto deve ter no máximo 4 MB.');
        }

        $bytes = file_get_contents($file->getRealPath());
        if (! is_string($bytes) || $bytes === '') {
            throw new InvalidArgumentException('O arquivo de foto está vazio.');
        }

        return $bytes;
    }

    private function normalize(string $bytes): string
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException('A foto deve ter no máximo 4 MB.');
        }

        $info = @getimagesizefromstring($bytes);
        if (! is_array($info)) {
            throw new InvalidArgumentException('Envie uma imagem PNG, JPG ou WebP válida.');
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        $mime = strtolower((string) ($info['mime'] ?? ''));
        if ($width < 20 || $height < 20 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw new InvalidArgumentException('A imagem deve ter entre 20 e 6000 pixels por dimensão.');
        }
        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new InvalidArgumentException('Use somente arquivos PNG, JPG ou WebP.');
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new InvalidArgumentException('Não foi possível decodificar a imagem enviada.');
        }

        $side = min($width, $height);
        $cropX = (int) floor(($width - $side) / 2);
        $cropY = (int) floor(($height - $side) / 2);

        $canvas = imagecreatetruecolor(self::OUTPUT_SIZE, self::OUTPUT_SIZE);
        if ($canvas === false) {
            imagedestroy($image);
            throw new RuntimeException('Não foi possível normalizar a foto de perfil.');
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopyresampled(
            $canvas,
            $image,
            0,
            0,
            $cropX,
            $cropY,
            self::OUTPUT_SIZE,
            self::OUTPUT_SIZE,
            $side,
            $side
        );

        ob_start();
        imagejpeg($canvas, null, 85);
        $jpeg = ob_get_clean();
        imagedestroy($image);
        imagedestroy($canvas);

        if (! is_string($jpeg) || $jpeg === '') {
            throw new RuntimeException('Não foi possível codificar a foto de perfil.');
        }

        return $jpeg;
    }
}
