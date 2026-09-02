<?php

namespace App\Support;

/**
 * Resolve código IBGE de município em nome e sigla de UF.
 *
 * A NT-008 manda o DANFSe imprimir "Município / Sigla UF" para prestador,
 * tomador, destinatário e intermediário — e o XML da NFS-e só traz `cMun`, o
 * código de 7 dígitos. Para o emitente o arquivo ainda oferece `xLocEmi`; para
 * o tomador não há nome nenhum, então sem esta tradução o campo ficaria vazio
 * sempre que o cliente fosse de outra cidade.
 *
 * A UF sai dos dois primeiros dígitos do código (a faixa 33xxxxx é o Rio de
 * Janeiro inteiro, por construção da tabela do IBGE) em vez de ser guardada
 * junto de cada município: é exato, cabe em 27 linhas e não pode divergir do
 * nome ao lado.
 */
class MunicipioIbge
{
    /**
     * Prefixo de 2 dígitos do código IBGE -> sigla da UF.
     */
    private const UFS = [
        11 => 'RO', 12 => 'AC', 13 => 'AM', 14 => 'RR', 15 => 'PA', 16 => 'AP', 17 => 'TO',
        21 => 'MA', 22 => 'PI', 23 => 'CE', 24 => 'RN', 25 => 'PB', 26 => 'PE', 27 => 'AL',
        28 => 'SE', 29 => 'BA',
        31 => 'MG', 32 => 'ES', 33 => 'RJ', 35 => 'SP',
        41 => 'PR', 42 => 'SC', 43 => 'RS',
        50 => 'MS', 51 => 'MT', 52 => 'GO', 53 => 'DF',
    ];

    /**
     * @var array<int, string>|null
     */
    private static ?array $municipios = null;

    /**
     * Nome do município, ou null quando o código não existe na tabela.
     */
    public static function nome(?string $codigo): ?string
    {
        $codigo = self::normalizar($codigo);

        if ($codigo === null) {
            return null;
        }

        self::$municipios ??= require resource_path('data/municipios-ibge.php');

        return self::$municipios[$codigo] ?? null;
    }

    /**
     * Sigla da UF, ou null quando o código não começa por prefixo conhecido.
     */
    public static function uf(?string $codigo): ?string
    {
        $codigo = self::normalizar($codigo);

        return $codigo === null ? null : (self::UFS[intdiv($codigo, 100000)] ?? null);
    }

    /**
     * "Município / UF" no formato que a NT-008 pede, item 2.4.5.
     *
     * Quando o nome não é conhecido devolve o que se sabe — o código cru é mais
     * útil que um traço, porque ainda identifica a cidade para quem consulta a
     * tabela do IBGE.
     */
    public static function nomeComUf(?string $codigo, ?string $nomeConhecido = null): ?string
    {
        $nome = $nomeConhecido !== null && trim($nomeConhecido) !== ''
            ? trim($nomeConhecido)
            : self::nome($codigo);

        $uf = self::uf($codigo);

        if ($nome === null && $uf === null) {
            return null;
        }

        if ($nome === null) {
            $nome = self::normalizar($codigo) !== null ? (string) self::normalizar($codigo) : '';
        }

        return $uf === null ? $nome : trim($nome.' / '.$uf);
    }

    private static function normalizar(?string $codigo): ?int
    {
        $digitos = preg_replace('/\D/', '', (string) $codigo);

        return strlen((string) $digitos) === 7 ? (int) $digitos : null;
    }
}
