<?php

namespace App\Services\Photos;

use App\Services\Files\FilePathGuard;

final class OperationalPhotoFileName
{
    public static function forEquipment(
        string $type,
        string $brand,
        string $model,
        string $client,
        string $extension,
        int $sequence = 1,
    ): string {
        $brandModel = self::compactSegment($brand).self::compactSegment($model);
        $brandModel = $brandModel !== '' ? $brandModel : 'SemMarcaModelo';
        $name = self::segment($type, 'Equipamento').'_'.$brandModel.'-'.self::segment($client, 'Cliente');

        return self::withSequence($name, $extension, $sequence);
    }

    public static function forOrder(
        string $orderNumber,
        string $client,
        string $photoType,
        string $extension,
        int $sequence = 1,
    ): string {
        $order = self::segment($orderNumber, 'sem_numero');
        $name = 'os_'.$order.'_'.self::segment($client, 'Cliente').'_'.self::segment($photoType, 'foto');

        return self::withSequence($name, $extension, $sequence);
    }

    private static function withSequence(string $name, string $extension, int $sequence): string
    {
        $normalizedExtension = strtolower(trim($extension, " .\t\n\r\0\x0B"));
        $normalizedExtension = preg_replace('/[^a-z0-9]+/', '', $normalizedExtension) ?: 'bin';
        $suffix = $sequence > 1 ? '_'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT) : '';

        return FilePathGuard::safeFileName($name.$suffix.'.'.$normalizedExtension, $normalizedExtension);
    }

    private static function segment(string $value, string $fallback): string
    {
        $value = trim($value);
        $value = preg_replace('/[^\pL\pN]+/u', '_', $value) ?? '';
        $value = trim($value, '_');

        return $value !== '' ? $value : $fallback;
    }

    private static function compactSegment(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[^\pL\pN]+/u', '', $value) ?? '';

        return $value;
    }
}
