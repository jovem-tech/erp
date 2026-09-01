<?php

namespace App\Support;

use Closure;

/**
 * CPF e CNPJ: normalização e dígito verificador.
 *
 * Existe porque o mesmo documento entrava no banco em dois formatos. O wizard
 * da OS já reduzia a dígitos em `UpsertOrderRequest::prepareForValidation()`;
 * o CRUD de cliente gravava o que viesse. Resultado: `123.456.789-00` por uma
 * porta e `12345678900` pela outra, com o `Rule::unique` sem enxergar a
 * duplicata, porque as strings diferem. A regra passa a morar aqui, e as duas
 * portas chamam a mesma coisa.
 *
 * **CNPJ alfanumérico**: desde 06/07/2026 as 12 primeiras posições do CNPJ
 * aceitam letras maiúsculas (A–Z) além de dígitos; só os 2 verificadores
 * continuam numéricos. O módulo 11 não mudou — o que mudou é o valor de cada
 * caractere, que passou a ser `ord($c) - 48` (assim '0'..'9' seguem valendo
 * 0..9 e 'A' vale 17). Por isso a normalização **não** pode ser um
 * `preg_replace('/\D+/')`: isso apagaria as letras de um CNPJ novo e o
 * transformaria em lixo silenciosamente.
 *
 * O desktop não duplica esta classe. Ele é BFF e encaminha; quem recusa é o
 * backend. O feedback instantâneo do formulário é JavaScript, de propósito:
 * uma segunda cópia em PHP divergiria da primeira com o tempo.
 */
final class Documento
{
    public const CPF_TAMANHO = 11;

    public const CNPJ_TAMANHO = 14;

    /**
     * @var array<int, int>
     */
    private const CPF_PESOS_PRIMEIRO = [10, 9, 8, 7, 6, 5, 4, 3, 2];

    /**
     * @var array<int, int>
     */
    private const CPF_PESOS_SEGUNDO = [11, 10, 9, 8, 7, 6, 5, 4, 3, 2];

    /**
     * @var array<int, int>
     */
    private const CNPJ_PESOS_PRIMEIRO = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    /**
     * @var array<int, int>
     */
    private const CNPJ_PESOS_SEGUNDO = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    /**
     * Forma canônica de armazenamento: só 0-9 e A-Z, maiúsculo. Devolve `null`
     * para valor vazio — a coluna é nullable e string vazia não é documento.
     */
    public static function normalizar(?string $valor): ?string
    {
        $limpo = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $valor) ?? '');

        return $limpo === '' ? null : $limpo;
    }

    public static function ehCpf(?string $valor): bool
    {
        $documento = self::normalizar($valor);

        if ($documento === null || strlen($documento) !== self::CPF_TAMANHO) {
            return false;
        }

        // CPF é numérico: o alfanumérico é exclusividade do CNPJ.
        if (preg_match('/^\d{11}$/', $documento) !== 1) {
            return false;
        }

        if (self::caracteresRepetidos($documento)) {
            return false;
        }

        $base = substr($documento, 0, 9);
        $primeiro = self::digito($base, self::CPF_PESOS_PRIMEIRO);
        $segundo = self::digito($base.$primeiro, self::CPF_PESOS_SEGUNDO);

        return $documento === $base.$primeiro.$segundo;
    }

    public static function ehCnpj(?string $valor): bool
    {
        $documento = self::normalizar($valor);

        if ($documento === null || strlen($documento) !== self::CNPJ_TAMANHO) {
            return false;
        }

        // 12 posições alfanuméricas + 2 verificadores sempre numéricos.
        if (preg_match('/^[0-9A-Z]{12}\d{2}$/', $documento) !== 1) {
            return false;
        }

        if (self::caracteresRepetidos($documento)) {
            return false;
        }

        $base = substr($documento, 0, 12);
        $primeiro = self::digito($base, self::CNPJ_PESOS_PRIMEIRO);
        $segundo = self::digito($base.$primeiro, self::CNPJ_PESOS_SEGUNDO);

        return $documento === $base.$primeiro.$segundo;
    }

    public static function valido(?string $valor): bool
    {
        return self::ehCpf($valor) || self::ehCnpj($valor);
    }

    /**
     * Máscara de exibição. Valor que não seja CPF nem CNPJ volta como está —
     * formatar não é lugar de esconder dado inválido.
     */
    public static function formatar(?string $valor): string
    {
        $documento = self::normalizar($valor);

        if ($documento === null) {
            return '';
        }

        if (strlen($documento) === self::CPF_TAMANHO && preg_match('/^\d{11}$/', $documento) === 1) {
            return vsprintf('%s%s%s.%s%s%s.%s%s%s-%s%s', str_split($documento));
        }

        if (strlen($documento) === self::CNPJ_TAMANHO) {
            return vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($documento));
        }

        return $documento;
    }

    /**
     * Regra de validação pronta para `$request->validate()`. Vazio passa: a
     * obrigatoriedade é decisão de cada porta, não desta classe.
     */
    public static function regra(): Closure
    {
        return static function (string $atributo, mixed $valor, Closure $falha): void {
            if (self::normalizar(is_scalar($valor) ? (string) $valor : null) === null) {
                return;
            }

            if (! self::valido(is_scalar($valor) ? (string) $valor : null)) {
                $falha('O campo :attribute não é um CPF ou CNPJ válido — confira os dígitos.');
            }
        };
    }

    /**
     * `00000000000` e `11111111111` passam no módulo 11 e não são documento
     * nenhum. Vale para o CNPJ alfanumérico também ('AAAAAAAAAAAA' + DV).
     */
    private static function caracteresRepetidos(string $documento): bool
    {
        return preg_match('/^(.)\1*$/', $documento) === 1;
    }

    /**
     * Módulo 11 sobre o valor ASCII do caractere menos 48 — a fórmula que o
     * CNPJ alfanumérico manteve.
     *
     * @param  array<int, int>  $pesos
     */
    private static function digito(string $base, array $pesos): int
    {
        $soma = 0;

        foreach (str_split($base) as $indice => $caractere) {
            $soma += (ord($caractere) - 48) * $pesos[$indice];
        }

        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }
}
