<?php

namespace App\Services\Signatures;

use App\DTO\Files\FileContext;
use App\Enums\Files\FileCategory;
use App\Enums\Files\FileOrigin;
use App\Models\User;
use App\Models\UserSignature;
use App\Services\Files\LegacyCompatibleFileAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class SignatureImageService
{
    public const MAX_BYTES = 2_097_152;

    private const MAX_DIMENSION = 4096;

    public function __construct(
        private readonly LegacyCompatibleFileAdapter $fileManager
    ) {}

    /** @return array{signature: UserSignature, replaced: bool} */
    public function enroll(
        User $user,
        UploadedFile|string $source,
        string $origin,
        User $actor,
        ?string $ip = null
    ): array {
        $origin = $origin === 'desenho' ? 'desenho' : 'upload';
        $bytes = $source instanceof UploadedFile
            ? $this->readUploadedFile($source)
            : $this->decodeCanvasData($source);
        $normalized = $this->normalizePng($bytes);
        $path = sprintf(
            'private/assinaturas/usuarios/%d/%s.png',
            (int) $user->id,
            (string) Str::uuid()
        );

        if (! Storage::disk('local')->put($path, $normalized['bytes'])) {
            throw new RuntimeException('Não foi possível armazenar a assinatura com segurança.');
        }

        try {
            return DB::transaction(function () use ($user, $actor, $origin, $ip, $path, $normalized): array {
                $current = UserSignature::query()
                    ->where('usuario_id', (int) $user->id)
                    ->where('ativa', true)
                    ->lockForUpdate()
                    ->get();

                foreach ($current as $signature) {
                    $signature->forceFill([
                        'ativa' => false,
                        'revogada_em' => now(),
                    ])->save();
                }

                $signature = UserSignature::query()->create([
                    'usuario_id' => (int) $user->id,
                    'arquivo' => $path,
                    'hash_sha256' => hash('sha256', $normalized['bytes']),
                    'origem' => $origin,
                    'largura' => $normalized['width'],
                    'altura' => $normalized['height'],
                    'ativa' => true,
                    'criada_por' => (int) $actor->id,
                    'ip_hash' => $this->fingerprint($ip),
                ]);

                $this->fileManager->synchronizeExisting(
                    new FileContext(
                        category: FileCategory::UserSignature,
                        origin: FileOrigin::Upload,
                        operationKey: 'user-signature:'.(int) $signature->id,
                        subjectType: 'user_signature',
                        subjectId: (int) $signature->id,
                        relation: 'signature_image',
                        createdBy: (int) $actor->id,
                        metadata: ['origin' => $origin]
                    ),
                    'local',
                    $path,
                    'usuario_assinaturas',
                    'arquivo',
                    (string) $signature->id
                );

                return [
                    'signature' => $signature,
                    'replaced' => $current->isNotEmpty(),
                ];
            }, 3);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function activeFor(User $user): ?UserSignature
    {
        $loaded = $user->relationLoaded('activeSignature') ? $user->activeSignature : null;
        if ($loaded instanceof UserSignature && (bool) $loaded->ativa) {
            return $loaded;
        }

        return UserSignature::query()
            ->where('usuario_id', (int) $user->id)
            ->where('ativa', true)
            ->orderByDesc('id')
            ->first();
    }

    public function dataUri(UserSignature $signature): ?string
    {
        if (! (bool) $signature->ativa && $signature->arquivo === '') {
            return null;
        }

        $path = trim((string) $signature->arquivo);
        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        $bytes = Storage::disk('local')->get($path);
        if ($bytes === '' || ! hash_equals((string) $signature->hash_sha256, hash('sha256', $bytes))) {
            logger()->error('[SIGNATURE] Integridade da assinatura inválida', [
                'signature_id' => (int) $signature->id,
                'user_id' => (int) $signature->usuario_id,
            ]);

            return null;
        }

        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    public function absolutePath(UserSignature $signature): ?string
    {
        $path = trim((string) $signature->arquivo);
        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return Storage::disk('local')->path($path);
    }

    /** @return array{path: string, hash_sha256: string, data_uri: string, width: int, height: int} */
    public function storeCustomerDrawing(string $data, int $requestId): array
    {
        $normalized = $this->normalizePng($this->decodeCanvasData($data));
        $path = sprintf('private/assinaturas/clientes/%d/%s.png', $requestId, (string) Str::uuid());
        if (! Storage::disk('local')->put($path, $normalized['bytes'])) {
            throw new RuntimeException('Não foi possível armazenar a assinatura do cliente.');
        }

        return [
            'path' => $path,
            'hash_sha256' => hash('sha256', $normalized['bytes']),
            'data_uri' => 'data:image/png;base64,'.base64_encode($normalized['bytes']),
            'width' => $normalized['width'],
            'height' => $normalized['height'],
        ];
    }

    private function readUploadedFile(UploadedFile $file): string
    {
        if (! $file->isValid()) {
            throw new InvalidArgumentException('O arquivo de assinatura não pôde ser lido.');
        }
        if (($file->getSize() ?? 0) > self::MAX_BYTES) {
            throw new InvalidArgumentException('A assinatura deve ter no máximo 2 MB.');
        }

        $bytes = file_get_contents($file->getRealPath());
        if (! is_string($bytes) || $bytes === '') {
            throw new InvalidArgumentException('O arquivo de assinatura está vazio.');
        }

        return $bytes;
    }

    private function decodeCanvasData(string $data): string
    {
        if (! preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=\r\n]+)$/', trim($data), $matches)) {
            throw new InvalidArgumentException('O desenho da assinatura possui formato inválido.');
        }

        $bytes = base64_decode($matches[1], true);
        if (! is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException('O desenho da assinatura é inválido ou excede 2 MB.');
        }

        return $bytes;
    }

    /** @return array{bytes: string, width: int, height: int} */
    private function normalizePng(string $bytes): array
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException('A assinatura deve ter no máximo 2 MB.');
        }

        $info = @getimagesizefromstring($bytes);
        if (! is_array($info)) {
            throw new InvalidArgumentException('Envie uma imagem PNG, JPG ou WebP válida.');
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        $mime = strtolower((string) ($info['mime'] ?? ''));
        if ($width < 20 || $height < 20 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw new InvalidArgumentException('A imagem deve ter entre 20 e 4096 pixels por dimensão.');
        }
        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new InvalidArgumentException('Use somente arquivos PNG, JPG ou WebP.');
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new InvalidArgumentException('Não foi possível decodificar a imagem da assinatura.');
        }

        $maxOutputWidth = 1200;
        $scale = min(1, $maxOutputWidth / max(1, $width));
        $outputWidth = max(1, (int) round($width * $scale));
        $outputHeight = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($outputWidth, $outputHeight);
        if ($canvas === false) {
            imagedestroy($image);
            throw new RuntimeException('Não foi possível normalizar a assinatura.');
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
        imagefill($canvas, 0, 0, $transparent);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $outputWidth, $outputHeight, $width, $height);

        ob_start();
        imagepng($canvas, null, 7);
        $png = ob_get_clean();
        imagedestroy($image);
        imagedestroy($canvas);

        if (! is_string($png) || $png === '') {
            throw new RuntimeException('Não foi possível codificar a assinatura.');
        }

        return ['bytes' => $png, 'width' => $outputWidth, 'height' => $outputHeight];
    }

    private function fingerprint(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
