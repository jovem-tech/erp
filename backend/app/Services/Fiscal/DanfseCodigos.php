<?php

namespace App\Services\Fiscal;

/**
 * Tabelas de domínio do leiaute da NFS-e nacional.
 *
 * A NT-008 repete, campo a campo, "utilizar a descrição destas opções": o
 * DANFSe não imprime o código, imprime o que ele significa. Estas são as
 * tabelas do **Anexo IV — Leiautes RN ADN/SN NFS-e v1.00.02 (produção)**,
 * publicado em gov.br/nfse (documentação técnica), transcritas literalmente.
 *
 * **Código desconhecido volta como código.** Um leiaute novo (a NT-009 já
 * mexeu no domínio de `opSimpNac`, por exemplo) traz valores que esta tabela
 * ainda não tem, e inventar uma descrição num documento fiscal é pior do que
 * imprimir o número cru: o número ainda é verificável contra o XML.
 */
class DanfseCodigos
{
    /** Situação da NFS-e (`infNFSe/cStat`). */
    private const SITUACAO = [
        '100' => 'NFS-e Gerada',
        '101' => 'NFS-e de Substituição Gerada',
        '102' => 'NFS-e de Decisão Judicial',
        '103' => 'NFS-e Avulsa',
        // 107 não consta do Anexo IV v1.00.02; é o código que o Emissor
        // Nacional devolve para nota de MEI, confirmado pelo DANFSe que o
        // próprio portal gera.
        '107' => 'NFS-e MEI',
    ];

    /** Emitente da DPS (`infDPS/tpEmit`). */
    private const EMITENTE = [
        '1' => 'Prestador',
        '2' => 'Tomador',
        '3' => 'Intermediário',
    ];

    /** Situação perante o Simples Nacional (`prest/regTrib/opSimpNac`). */
    private const SIMPLES_NACIONAL = [
        '1' => 'Não Optante',
        '2' => 'Optante - Microempreendedor Individual (MEI)',
        '3' => 'Optante - Microempresa ou Empresa de Pequeno Porte (ME/EPP)',
    ];

    /** Regime de apuração pelo Simples Nacional (`prest/regTrib/regApTribSN`). */
    private const REGIME_APURACAO_SN = [
        '1' => 'Regime de apuração dos tributos federais e municipal pelo SN',
        '2' => 'Regime de apuração dos tributos federais pelo SN e o ISSQN pela NFS-e conforme respectiva legislação municipal do tributo',
        '3' => 'Regime de apuração dos tributos federais e municipal pela NFS-e conforme respectivas legislações federal e municipal de cada tributo',
    ];

    /** Regime especial de tributação municipal (`prest/regTrib/regEspTrib`). */
    private const REGIME_ESPECIAL = [
        '0' => 'Nenhum',
        '1' => 'Ato Cooperado (Cooperativa)',
        '2' => 'Estimativa',
        '3' => 'Microempresa Municipal',
        '4' => 'Notário ou Registrador',
        '5' => 'Profissional Autônomo',
        '6' => 'Sociedade de Profissionais',
    ];

    /** Tributação do ISSQN (`tribMun/tribISSQN`). */
    private const TRIBUTACAO_ISSQN = [
        '1' => 'Operação tributável',
        '2' => 'Imunidade',
        '3' => 'Exportação de serviço',
        '4' => 'Não Incidência',
    ];

    /** Tipo de retenção do ISSQN (`tribMun/tpRetISSQN`). */
    private const RETENCAO_ISSQN = [
        '1' => 'Não Retido',
        '2' => 'Retido pelo Tomador',
        '3' => 'Retido pelo Intermediário',
    ];

    /** Tipo de imunidade do ISSQN (`tribMun/tpImunidade`). */
    private const IMUNIDADE = [
        '0' => 'Imunidade (tipo não informado na nota de origem)',
        '1' => 'Patrimônio, renda ou serviços, uns dos outros (CF88, Art 150, VI, a)',
        '2' => 'Templos de qualquer culto (CF88, Art 150, VI, b)',
        '3' => 'Patrimônio, renda ou serviços dos partidos políticos, inclusive suas fundações, das entidades sindicais dos trabalhadores, das instituições de educação e de assistência social, sem fins lucrativos (CF88, Art 150, VI, c)',
        '4' => 'Livros, jornais, periódicos e o papel destinado a sua impressão (CF88, Art 150, VI, d)',
        '5' => 'Fonogramas e videofonogramas musicais produzidos no Brasil (CF88, Art 150, VI, e)',
    ];

    /** Exigibilidade suspensa (`tribMun/exigSusp/tpSusp`). */
    private const SUSPENSAO = [
        '1' => 'Exigibilidade Suspensa por Decisão Judicial',
        '2' => 'Exigibilidade Suspensa por Processo Administrativo',
    ];

    /** Tipo de benefício municipal (`infNFSe/valores/tpBM`). */
    private const BENEFICIO_MUNICIPAL = [
        '1' => 'Isenção',
        '2' => 'Redução da BC em percentual',
        '3' => 'Redução da BC em valor',
        '4' => 'Alíquota Diferenciada',
    ];

    /** Tipo de retenção de PIS/COFINS (`tribFed/piscofins/tpRetPisCofins`). */
    private const RETENCAO_PIS_COFINS = [
        '1' => 'PIS/COFINS/CSLL Retido',
        '2' => 'PIS/COFINS/CSLL Não Retido',
    ];

    public static function situacao(?string $codigo): ?string
    {
        return self::descrever(self::SITUACAO, $codigo);
    }

    public static function emitente(?string $codigo): ?string
    {
        return self::descrever(self::EMITENTE, $codigo);
    }

    public static function simplesNacional(?string $codigo): ?string
    {
        return self::descrever(self::SIMPLES_NACIONAL, $codigo);
    }

    public static function regimeApuracaoSn(?string $codigo): ?string
    {
        return self::descrever(self::REGIME_APURACAO_SN, $codigo);
    }

    public static function regimeEspecial(?string $codigo): ?string
    {
        return self::descrever(self::REGIME_ESPECIAL, $codigo);
    }

    public static function tributacaoIssqn(?string $codigo): ?string
    {
        return self::descrever(self::TRIBUTACAO_ISSQN, $codigo);
    }

    public static function retencaoIssqn(?string $codigo): ?string
    {
        return self::descrever(self::RETENCAO_ISSQN, $codigo);
    }

    public static function imunidade(?string $codigo): ?string
    {
        return self::descrever(self::IMUNIDADE, $codigo);
    }

    public static function suspensao(?string $codigo): ?string
    {
        return self::descrever(self::SUSPENSAO, $codigo);
    }

    public static function beneficioMunicipal(?string $codigo): ?string
    {
        return self::descrever(self::BENEFICIO_MUNICIPAL, $codigo);
    }

    public static function retencaoPisCofins(?string $codigo): ?string
    {
        return self::descrever(self::RETENCAO_PIS_COFINS, $codigo);
    }

    /**
     * @param  array<string, string>  $tabela
     */
    private static function descrever(array $tabela, ?string $codigo): ?string
    {
        $codigo = trim((string) $codigo);

        if ($codigo === '') {
            return null;
        }

        return $tabela[$codigo] ?? $codigo;
    }
}
