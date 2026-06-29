<?php

namespace App\Support;

class SecretSettings
{
    /**
     * @param array<string, string> $settings
     * @param array<int, string> $secretKeys
     * @return array<string, string>
     */
    public static function blank(array $settings, array $secretKeys): array
    {
        foreach ($secretKeys as $key) {
            if (array_key_exists($key, $settings)) {
                $settings[$key] = '';
            }
        }

        return $settings;
    }

    /**
     * @param array<string, string> $settings
     * @param array<int, string> $secretKeys
     * @return array<string, array<string, bool>>
     */
    public static function status(array $settings, array $secretKeys): array
    {
        $status = [];

        foreach ($secretKeys as $key) {
            $status[$key] = [
                'configured' => trim((string) ($settings[$key] ?? '')) !== '',
            ];
        }

        ksort($status);

        return $status;
    }

    /**
     * @param array<string, string> $normalized
     * @param array<string, string> $current
     * @param array<int, string> $secretKeys
     * @return array<string, string>
     */
    public static function preserveExisting(array $normalized, array $current, array $secretKeys): array
    {
        foreach ($secretKeys as $key) {
            if (! array_key_exists($key, $normalized)) {
                continue;
            }

            if (trim((string) $normalized[$key]) !== '') {
                continue;
            }

            $currentValue = trim((string) ($current[$key] ?? ''));
            if ($currentValue === '') {
                continue;
            }

            $normalized[$key] = $currentValue;
        }

        return $normalized;
    }
}
