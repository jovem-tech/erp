<?php

namespace App\Support;

class DocumentFormatter
{
    public static function format(?string $documento): ?string
    {
        if ($documento === null || $documento === '') {
            return null;
        }

        $documento = preg_replace('/\D/', '', $documento);

        if (strlen($documento) === 11) {
            return self::formatarCpf($documento);
        }

        if (strlen($documento) === 14) {
            return self::formatarCnpj($documento);
        }

        return $documento;
    }

    private static function formatarCpf(string $cpf): string
    {
        return sprintf(
            '%s.%s.%s-%s',
            substr($cpf, 0, 3),
            substr($cpf, 3, 3),
            substr($cpf, 6, 3),
            substr($cpf, 9, 2)
        );
    }

    private static function formatarCnpj(string $cnpj): string
    {
        return sprintf(
            '%s.%s.%s/%s-%s',
            substr($cnpj, 0, 2),
            substr($cnpj, 2, 3),
            substr($cnpj, 5, 3),
            substr($cnpj, 8, 4),
            substr($cnpj, 12, 2)
        );
    }
}
