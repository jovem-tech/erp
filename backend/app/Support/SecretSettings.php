<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class SecretSettings
{
    /**
     * Cifra o valor quando a chave e' secreta.
     *
     * Ate' aqui blank()/status() so' mascaravam a LEITURA pela API - o valor
     * seguia em texto puro na tabela `configuracoes`, e portanto tambem no dump
     * diario do banco (mysqldump | gzip, sem cifra). Quem lesse o banco ou um
     * backup lia o segredo.
     */
    public static function encrypt(string $key, string $value, array $secretKeys): string
    {
        if ($value === '' || ! in_array($key, $secretKeys, true)) {
            return $value;
        }

        return Crypt::encryptString($value);
    }

    /**
     * Decifra o valor quando a chave e' secreta, tolerando o legado.
     *
     * Mesmo raciocinio do cast App\Casts\EncryptedSecret: a base ja tem valores
     * gravados em claro, e estourar DecryptException neles viraria HTTP 500 na
     * tela de integracoes. Devolve como esta' quando nao da' para decifrar; o
     * proximo save recifra.
     */
    public static function decrypt(string $key, string $value, array $secretKeys): string
    {
        if ($value === '' || ! in_array($key, $secretKeys, true)) {
            return trim($value);
        }

        try {
            return trim(Crypt::decryptString($value));
        } catch (DecryptException) {
            return trim($value);
        }
    }

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
