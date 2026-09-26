<?php

namespace App\Support;

/**
 * Perfis de destino do padrão de inserção de imagem (specs/049). Espelham o
 * `PRESETS` de public/assets/js/image-picker.js e os limites reais de cada
 * rota: fotos operacionais (OS/equipamento, specs/046), imagens de cadastro
 * (perfil, logo, fundo do login, assinatura) e anexos que também aceitam PDF.
 */
final class ImagePicker
{
    public const PRESETS = [
        'photo' => [
            'accept' => 'image/jpeg,image/png,image/webp,image/avif,image/heic,image/heif,.heic,.heif,.avif',
            'max_bytes' => 20 * 1024 * 1024,
            'formats' => 'JPEG, PNG, WebP, AVIF ou HEIC',
        ],
        'image' => [
            'accept' => 'image/jpeg,image/png,image/webp',
            'max_bytes' => 4 * 1024 * 1024,
            'formats' => 'JPG, PNG ou WebP',
        ],
        'document' => [
            'accept' => 'image/jpeg,image/png,image/webp,application/pdf,.pdf',
            'max_bytes' => 20 * 1024 * 1024,
            'formats' => 'PDF, JPG, PNG ou WebP',
        ],
    ];

    public static function preset(string $name): array
    {
        return self::PRESETS[$name] ?? self::PRESETS['photo'];
    }

    public static function accept(string $name): string
    {
        return self::preset($name)['accept'];
    }

    public static function maxBytes(string $name, ?int $override = null): int
    {
        return $override !== null && $override > 0 ? $override : self::preset($name)['max_bytes'];
    }

    public static function formats(string $name): string
    {
        return self::preset($name)['formats'];
    }

    public static function humanSize(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? rtrim(rtrim(number_format($bytes / (1024 * 1024), 1, ',', ''), '0'), ',').' MB'
            : max(1, (int) round($bytes / 1024)).' KB';
    }
}
