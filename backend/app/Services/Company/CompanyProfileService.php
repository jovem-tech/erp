<?php

namespace App\Services\Company;

use App\Enums\Files\FileCategory;
use App\Models\Configuration;
use App\Services\Files\CompanyFileManagerAdapter;
use App\Services\Pdf\Contexts\CompanyContextProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CompanyProfileService
{
    public function __construct(private readonly CompanyFileManagerAdapter $fileManagerAdapter) {}

    private const LOGO_CONFIG_KEY = 'empresa_logo';

    private const LOGIN_BACKGROUND_CONFIG_KEY = 'login_background_image';

    private const LOGO_DIRECTORY = 'private/empresa';

    private const LOGIN_BACKGROUND_DIRECTORY = 'private/empresa/login';

    /**
     * @var array<int, string>
     */
    private const ALLOWED_LOGO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * @var array<int, string>
     */
    private const ALLOWED_LOGIN_BACKGROUND_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * @var array<string, array<int, string>>
     */
    private const ALLOWED_LOGO_UPLOAD_MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    /**
     * GIF permanece apenas para leitura de configuracoes legadas. Novos
     * uploads aceitam somente formatos raster estaticos e reencodaveis.
     *
     * @var array<string, array<int, string>>
     */
    private const ALLOWED_LOGO_READ_MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/gif' => ['gif'],
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private const ALLOWED_LOGIN_BACKGROUND_MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    private const MAX_LOGO_DIMENSION = 800;

    private const MAX_LOGIN_BACKGROUND_DIMENSION = 1920;

    private const LOGIN_BACKGROUND_JPEG_QUALITY = 82;

    /**
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'sistema_nome' => '',
        'empresa_razao_social' => '',
        'empresa_nome_fantasia' => '',
        'empresa_cnpj' => '',
        'empresa_inscricao_estadual' => '',
        'empresa_telefone' => '',
        'empresa_email' => '',
        'empresa_endereco' => '',
    ];

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'settings' => $this->loadSettings(),
            'logo' => $this->logoMeta(),
            'login_background' => $this->loginBackgroundMeta(),
        ];
    }

    /**
     * Dados mínimos e não sensíveis para telas públicas, como o login.
     *
     * @return array<string, mixed>
     */
    public function publicBranding(): array
    {
        $settings = $this->loadSettings();

        $systemName = trim((string) ($settings['sistema_nome'] ?? ''));
        if ($systemName === '') {
            $systemName = trim((string) ($settings['empresa_nome_fantasia'] ?? ''));
        }
        if ($systemName === '') {
            $systemName = trim((string) ($settings['empresa_razao_social'] ?? ''));
        }

        return [
            'sistema_nome' => $systemName !== '' ? $systemName : 'Sistema ERP',
            'logo' => $this->logoMeta(),
            'login_background' => $this->loginBackgroundMeta(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function save(array $payload): array
    {
        $normalized = $this->normalizePayload($payload);

        foreach ($normalized as $key => $value) {
            $this->upsert((string) $key, (string) $value);
        }

        return $this->payload();
    }

    public function storeLogo(UploadedFile $file): void
    {
        $this->storeImage(
            file: $file,
            configKey: self::LOGO_CONFIG_KEY,
            directory: self::LOGO_DIRECTORY,
            filenamePrefix: 'logo',
            allowedExtensions: self::ALLOWED_LOGO_EXTENSIONS,
            allowedMimeExtensions: self::ALLOWED_LOGO_UPLOAD_MIME_EXTENSIONS
        );

        CompanyContextProvider::forgetLogoCache();
    }

    public function removeLogo(): void
    {
        $this->clearStoredImage(self::LOGO_CONFIG_KEY, self::LOGO_DIRECTORY);

        CompanyContextProvider::forgetLogoCache();
    }

    public function storeLoginBackground(UploadedFile $file): void
    {
        $this->storeImage(
            file: $file,
            configKey: self::LOGIN_BACKGROUND_CONFIG_KEY,
            directory: self::LOGIN_BACKGROUND_DIRECTORY,
            filenamePrefix: 'login_background',
            allowedExtensions: self::ALLOWED_LOGIN_BACKGROUND_EXTENSIONS,
            allowedMimeExtensions: self::ALLOWED_LOGIN_BACKGROUND_MIME_EXTENSIONS
        );
    }

    public function removeLoginBackground(): void
    {
        $this->clearStoredImage(self::LOGIN_BACKGROUND_CONFIG_KEY, self::LOGIN_BACKGROUND_DIRECTORY);
    }

    /**
     * @return array{absolute_path: string, mime_type: string, filename: string}|null
     */
    public function resolveLogoFile(): ?array
    {
        return $this->resolveStoredImageFile(
            self::LOGO_CONFIG_KEY,
            self::LOGO_DIRECTORY,
            self::ALLOWED_LOGO_READ_MIME_EXTENSIONS
        );
    }

    /**
     * @return array{absolute_path: string, mime_type: string, filename: string}|null
     */
    public function resolveLoginBackgroundFile(): ?array
    {
        return $this->resolveStoredImageFile(
            self::LOGIN_BACKGROUND_CONFIG_KEY,
            self::LOGIN_BACKGROUND_DIRECTORY,
            self::ALLOWED_LOGIN_BACKGROUND_MIME_EXTENSIONS
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function logoMeta(): array
    {
        return $this->mediaMeta($this->resolveLogoFile());
    }

    /**
     * @return array<string, mixed>
     */
    private function loginBackgroundMeta(): array
    {
        return $this->mediaMeta($this->resolveLoginBackgroundFile());
    }

    /**
     * @return array<string, string>
     */
    private function loadSettings(): array
    {
        $stored = Configuration::query()
            ->whereIn('chave', array_keys(self::DEFAULTS))
            ->pluck('valor', 'chave')
            ->all();

        return array_merge(self::DEFAULTS, array_map(
            static fn ($value): string => trim((string) $value),
            is_array($stored) ? $stored : []
        ));
    }

    private function configValue(string $key): string
    {
        $value = Configuration::query()->where('chave', $key)->value('valor');

        return trim((string) $value);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function normalizePayload(array $payload): array
    {
        $normalized = [];

        foreach (self::DEFAULTS as $key => $defaultValue) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];

            if ($key === 'empresa_email') {
                $normalized[$key] = strtolower(trim((string) $value));

                continue;
            }

            $normalized[$key] = is_scalar($value) ? trim((string) $value) : (string) $defaultValue;
        }

        return $normalized;
    }

    private function upsert(string $key, string $value): void
    {
        Configuration::query()->updateOrInsert(
            ['chave' => $key],
            [
                'valor' => $value,
                'tipo' => 'texto',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    /**
     * @param  array<int, string>  $allowedExtensions
     * @param  array<string, array<int, string>>  $allowedMimeExtensions
     */
    private function storeImage(
        UploadedFile $file,
        string $configKey,
        string $directory,
        string $filenamePrefix,
        array $allowedExtensions,
        array $allowedMimeExtensions
    ): void {
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension() ?: ''));
        $mimeType = $this->normalizeMimeType((string) $file->getMimeType());
        if (
            ! in_array($extension, $allowedExtensions, true)
            || ! in_array($extension, $allowedMimeExtensions[$mimeType] ?? [], true)
        ) {
            throw new \RuntimeException('O tipo de imagem enviado nao e permitido.');
        }

        $disk = Storage::disk('local');
        $previousPath = $this->safeStoredImagePath($configKey, $directory);
        $filename = $filenamePrefix.'_'.Str::uuid()->toString().'.'.$extension;
        $relativePath = $directory.'/'.$filename;
        $candidatePaths = [$relativePath];
        $configurationPublished = false;
        $category = $configKey === self::LOGIN_BACKGROUND_CONFIG_KEY
            ? FileCategory::CompanyLoginBackground
            : FileCategory::CompanyLogo;

        try {
            $storedPath = $disk->putFileAs($directory, $file, $filename);
            if (! is_string($storedPath) || $storedPath !== $relativePath || ! $disk->exists($relativePath)) {
                throw new \RuntimeException('Falha ao persistir a imagem da empresa.');
            }

            $optimizedPath = $configKey === self::LOGIN_BACKGROUND_CONFIG_KEY
                ? $this->optimizeLoginBackground($relativePath, $extension)
                : $this->capImageDimensions($relativePath, $extension, self::MAX_LOGO_DIMENSION);
            $candidatePaths[] = $optimizedPath;

            if (! $this->isAllowedStoredImage($optimizedPath, $allowedMimeExtensions)) {
                throw new \RuntimeException('A imagem persistida nao passou pela validacao de seguranca.');
            }

            $this->upsert($configKey, $optimizedPath);
            $configurationPublished = true;
            $this->fileManagerAdapter->synchronize(
                $category,
                'local',
                $optimizedPath,
                $configKey
            );
        } catch (\Throwable $exception) {
            $configurationRestored = ! $configurationPublished;
            if ($configurationPublished) {
                try {
                    $this->upsert($configKey, $previousPath ?? '');
                    $configurationRestored = true;
                } catch (\Throwable $restoreException) {
                    report($restoreException);
                }
            }

            if ($configurationRestored) {
                foreach (array_unique($candidatePaths) as $candidatePath) {
                    try {
                        if ($disk->exists($candidatePath)) {
                            $disk->delete($candidatePath);
                        }
                    } catch (\Throwable $cleanupException) {
                        report($cleanupException);
                    }
                }
            }

            throw $exception;
        }

        if (
            $previousPath !== null
            && $previousPath !== $optimizedPath
            && ! $this->fileManagerAdapter->shouldRetainPrevious($category)
        ) {
            try {
                if ($disk->exists($previousPath)) {
                    $disk->delete($previousPath);
                }
            } catch (\Throwable $cleanupException) {
                report($cleanupException);
            }
        }
    }

    /**
     * Fundos de login sao fotos de tela cheia enviadas com frequencia como PNG
     * sem necessidade real de transparencia (ver CHANGELOG); reencodar para
     * JPEG reduz o peso em ~90% sem perda visual perceptivel atras do
     * gradiente que sempre cobre a imagem (layouts/auth/login.blade.php).
     */
    private function optimizeLoginBackground(string $relativePath, string $extension): string
    {
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) || ! extension_loaded('gd')) {
            return $relativePath;
        }

        $absolutePath = Storage::disk('local')->path($relativePath);
        $image = $this->loadGdImage($absolutePath, $extension);
        if ($image === null) {
            throw new \RuntimeException('Nao foi possivel decodificar a imagem de fundo do login.');
        }

        $image = $this->resizeToMaxDimension($image, self::MAX_LOGIN_BACKGROUND_DIMENSION);
        $flattened = $this->flattenOntoWhite($image);

        $jpegPath = preg_replace('/\.'.preg_quote($extension, '/').'$/', '.jpg', $absolutePath);
        $encoded = imagejpeg($flattened, $jpegPath, self::LOGIN_BACKGROUND_JPEG_QUALITY);
        imagedestroy($image);
        imagedestroy($flattened);

        if (! $encoded) {
            throw new \RuntimeException('Nao foi possivel otimizar a imagem de fundo do login.');
        }

        $jpegRelativePath = preg_replace('/\.'.preg_quote($extension, '/').'$/', '.jpg', $relativePath);
        if ($jpegPath !== $absolutePath) {
            Storage::disk('local')->delete($relativePath);
        }

        return is_string($jpegRelativePath) ? $jpegRelativePath : $relativePath;
    }

    /**
     * Logos raramente precisam de mais que algumas centenas de pixels (sao
     * exibidos em ~90px na tela de login e na sidebar); limita o lado maior
     * preservando formato e transparencia.
     */
    private function capImageDimensions(string $relativePath, string $extension, int $maxDimension): string
    {
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) || ! extension_loaded('gd')) {
            return $relativePath;
        }

        $absolutePath = Storage::disk('local')->path($relativePath);
        $image = $this->loadGdImage($absolutePath, $extension);
        if ($image === null) {
            throw new \RuntimeException('Nao foi possivel decodificar a logo da empresa.');
        }

        if (imagesx($image) <= $maxDimension && imagesy($image) <= $maxDimension) {
            imagedestroy($image);

            return $relativePath;
        }

        $resized = $this->resizeToMaxDimension($image, $maxDimension);
        imagedestroy($image);

        if ($extension === 'png') {
            imagesavealpha($resized, true);
            $encoded = imagepng($resized, $absolutePath, 6);
        } elseif ($extension === 'webp') {
            imagesavealpha($resized, true);
            $encoded = function_exists('imagewebp') && imagewebp($resized, $absolutePath, 85);
        } else {
            $encoded = imagejpeg($resized, $absolutePath, 85);
        }
        imagedestroy($resized);

        if (! $encoded) {
            throw new \RuntimeException('Nao foi possivel otimizar a logo da empresa.');
        }

        return $relativePath;
    }

    /**
     * @return \GdImage|null
     */
    private function loadGdImage(string $absolutePath, string $extension)
    {
        $image = match ($extension) {
            'png' => @imagecreatefrompng($absolutePath),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absolutePath) : false,
            default => @imagecreatefromjpeg($absolutePath),
        };

        return $image instanceof \GdImage ? $image : null;
    }

    /**
     * @param  \GdImage  $image
     * @return \GdImage
     */
    private function resizeToMaxDimension($image, int $maxDimension)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $largestSide = max($width, $height);

        if ($largestSide <= $maxDimension) {
            return $image;
        }

        $ratio = $maxDimension / $largestSide;
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $resized;
    }

    /**
     * @param  \GdImage  $image
     * @return \GdImage
     */
    private function flattenOntoWhite($image)
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $flattened = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($flattened, 255, 255, 255);
        imagefill($flattened, 0, 0, $white);
        imagecopy($flattened, $image, 0, 0, 0, 0, $width, $height);

        return $flattened;
    }

    private function clearStoredImage(string $configKey, string $directory): void
    {
        $relativePath = $this->safeStoredImagePath($configKey, $directory);
        $this->upsert($configKey, '');

        if ($relativePath === null) {
            return;
        }

        try {
            if (Storage::disk('local')->exists($relativePath)) {
                Storage::disk('local')->delete($relativePath);
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  array<string, array<int, string>>  $allowedMimeExtensions
     * @return array{absolute_path: string, mime_type: string, filename: string}|null
     */
    private function resolveStoredImageFile(
        string $configKey,
        string $directory,
        array $allowedMimeExtensions
    ): ?array {
        $legacyPath = $this->safeStoredImagePath($configKey, $directory);
        $category = $configKey === self::LOGIN_BACKGROUND_CONFIG_KEY
            ? FileCategory::CompanyLoginBackground
            : FileCategory::CompanyLogo;
        $relativePath = $this->fileManagerAdapter->resolveCompatiblePath($category, 'local', $legacyPath);
        if ($relativePath === null || ! $this->isAllowedStoredImage($relativePath, $allowedMimeExtensions)) {
            return null;
        }

        $mimeType = $this->normalizeMimeType((string) Storage::disk('local')->mimeType($relativePath));

        return [
            'absolute_path' => Storage::disk('local')->path($relativePath),
            'mime_type' => $mimeType,
            'filename' => basename($relativePath),
        ];
    }

    /**
     * @param  array<string, array<int, string>>  $allowedMimeExtensions
     */
    private function isAllowedStoredImage(string $relativePath, array $allowedMimeExtensions): bool
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($relativePath)) {
            return false;
        }

        $mimeType = $this->normalizeMimeType((string) ($disk->mimeType($relativePath) ?: ''));
        $extension = strtolower((string) pathinfo($relativePath, PATHINFO_EXTENSION));

        return in_array($extension, $allowedMimeExtensions[$mimeType] ?? [], true);
    }

    private function normalizeMimeType(string $mimeType): string
    {
        $mimeType = strtolower(trim(explode(';', $mimeType, 2)[0] ?? ''));

        return match ($mimeType) {
            'image/jpg', 'image/pjpeg' => 'image/jpeg',
            default => $mimeType,
        };
    }

    private function safeStoredImagePath(string $configKey, string $directory): ?string
    {
        $relativePath = str_replace('\\', '/', trim((string) $this->configValue($configKey)));
        $expectedPrefix = rtrim($directory, '/').'/';

        if (
            $relativePath === ''
            || str_contains($relativePath, "\0")
            || str_contains($relativePath, '..')
            || ! str_starts_with($relativePath, $expectedPrefix)
        ) {
            return null;
        }

        return $relativePath;
    }

    /**
     * @param  array{absolute_path: string, mime_type: string, filename: string}|null  $file
     * @return array<string, mixed>
     */
    private function mediaMeta(?array $file): array
    {
        return [
            'exists' => $file !== null,
        ];
    }
}
