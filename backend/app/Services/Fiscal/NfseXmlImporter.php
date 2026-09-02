<?php

namespace App\Services\Fiscal;

use App\Services\Company\CompanyProfileService;
use App\Support\Documento;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Validation\ValidationException;

/**
 * Lê o XML da NFS-e baixado do Emissor Nacional.
 *
 * O arquivo tem dois níveis: `infNFSe` é o que o Ambiente Nacional devolveu
 * (número, chave, situação) e, dentro dele, `DPS/infDPS` é o que o contribuinte
 * enviou (série, tomador, serviço, valores). Os campos que interessam estão
 * repartidos entre os dois.
 *
 * Existe para acabar com a redigitação: o operador emite no portal, baixa o
 * XML, e o sistema tira dali número, série e chave em vez de pedir que ele copie
 * do PDF — que é onde o erro acontece.
 */
class NfseXmlImporter
{
    private const NS = 'http://www.sped.fazenda.gov.br/nfse';

    public function __construct(private readonly CompanyProfileService $empresa) {}

    /**
     * @return array<string, mixed>
     */
    public function ler(string $conteudo): array
    {
        $conteudo = $this->normalizarAcentuacao($conteudo);

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;

        if (! @$dom->loadXML($conteudo)) {
            throw ValidationException::withMessages([
                'arquivo' => 'O arquivo não é um XML válido.',
            ]);
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', self::NS);

        $inf = $xpath->query('//n:infNFSe')->item(0);

        if (! $inf instanceof DOMElement) {
            throw ValidationException::withMessages([
                'arquivo' => 'Este XML não é uma NFS-e do padrão nacional '
                    .'(não tem <infNFSe>). Baixe o XML da nota no Emissor Nacional.',
            ]);
        }

        // A chave vem do atributo Id, prefixado com "NFS".
        $chave = preg_replace('/^NFS/', '', (string) $inf->getAttribute('Id'));

        $prestador = Documento::normalizar($this->texto($xpath, '//n:infNFSe/n:emit/n:CNPJ')
            ?: $this->texto($xpath, '//n:infNFSe/n:emit/n:CPF'));

        $this->conferirPrestador($prestador);

        $tomador = Documento::normalizar($this->texto($xpath, '//n:infDPS/n:toma/n:CNPJ')
            ?: $this->texto($xpath, '//n:infDPS/n:toma/n:CPF'));

        // `vLiq` (o que o ADN calculou) tem precedência sobre `vServ` (o que
        // foi declarado): é o valor líquido da nota que existe de verdade.
        $valor = $this->texto($xpath, '//n:infNFSe/n:valores/n:vLiq')
            ?: $this->texto($xpath, '//n:infDPS/n:valores/n:vServPrest/n:vServ');

        return [
            'chave' => $chave,
            'numero' => $this->texto($xpath, '//n:infNFSe/n:nNFSe'),
            'serie' => $this->texto($xpath, '//n:infDPS/n:serie'),
            'numero_dps' => $this->texto($xpath, '//n:infDPS/n:nDPS'),
            'emitido_em' => $this->texto($xpath, '//n:infDPS/n:dhEmi')
                ?: $this->texto($xpath, '//n:infNFSe/n:dhProc'),
            'competencia' => $this->texto($xpath, '//n:infDPS/n:dCompet'),
            'ambiente' => $this->texto($xpath, '//n:infDPS/n:tpAmb'),
            'situacao_codigo' => $this->texto($xpath, '//n:infNFSe/n:cStat'),
            'prestador_documento' => $prestador,
            'prestador_nome' => $this->texto($xpath, '//n:infNFSe/n:emit/n:xNome'),
            'tomador_documento' => $tomador,
            'tomador_nome' => $this->texto($xpath, '//n:infDPS/n:toma/n:xNome'),
            'descricao_servico' => $this->texto($xpath, '//n:infDPS/n:serv/n:cServ/n:xDescServ'),
            'codigo_tributacao' => $this->texto($xpath, '//n:infDPS/n:serv/n:cServ/n:cTribNac'),
            'codigo_nbs' => $this->texto($xpath, '//n:infDPS/n:serv/n:cServ/n:cNBS'),
            'descricao_tributacao' => $this->texto($xpath, '//n:infNFSe/n:xTribNac'),
            'municipio' => $this->texto($xpath, '//n:infNFSe/n:xLocEmi'),
            'valor' => $valor !== null ? (float) $valor : 0.0,

            // ---- Nota Tecnica no 008 (DANFSe) ----
            // O DANFSe nao pode conter nada que nao esteja no XML (item 2.1),
            // entao o que nao for extraido aqui e' campo que o documento
            // imprime como traco. Dai a leitura ir bem alem do que o registro
            // da emissao precisa.
            'processado_em' => $this->texto($xpath, '//n:infNFSe/n:dhProc'),
            'ambiente_gerador' => $this->texto($xpath, '//n:infNFSe/n:ambGer'),
            'emitente_tipo' => $this->texto($xpath, '//n:infDPS/n:tpEmit'),
            'finalidade' => $this->texto($xpath, '//n:infDPS/n:IBSCBS/n:finNFSe'),
            'codigo_tributacao_municipal' => $this->texto($xpath, '//n:infNFSe/n:cTribMun'),
            'descricao_tributacao_municipal' => $this->texto($xpath, '//n:infNFSe/n:xTribMun'),
            'local_prestacao' => $this->texto($xpath, '//n:infNFSe/n:xLocPrestacao'),
            'codigo_local_prestacao' => $this->texto($xpath, '//n:infDPS/n:serv/n:locPrest/n:cLocPrestacao'),
            'pais_prestacao' => $this->texto($xpath, '//n:infDPS/n:serv/n:locPrest/n:cPaisPrestacao'),
            'prestador' => $this->prestador($xpath),
            'tomador' => $this->pessoa($xpath, '//n:infDPS/n:toma'),
            'destinatario' => $this->pessoa($xpath, '//n:infDPS/n:IBSCBS/n:dest'),
            'intermediario' => $this->pessoa($xpath, '//n:infDPS/n:interm'),
            'issqn' => $this->issqn($xpath),
            'federal' => $this->federal($xpath),
            'ibscbs' => $this->ibscbs($xpath),
            'totais' => $this->totais($xpath),
            'complementares' => $this->complementares($xpath),
        ];
    }

    /**
     * Prestador: `infDPS/prest` primeiro, `infNFSe/emit` como reserva.
     *
     * A NT-008 aponta todos os campos do bloco para `infDPS/prest`, mas na
     * pratica o Emissor Nacional devolve ali so' documento, telefone, e-mail e
     * regime — nome e endereco ficam em `infNFSe/emit`, que e' o cadastro que o
     * proprio ADN resolveu. O DANFSe do portal usa exatamente essa combinacao.
     *
     * @return array<string, mixed>
     */
    private function prestador(DOMXPath $xpath): array
    {
        $pessoa = $this->pessoa($xpath, '//n:infDPS/n:prest');
        $emit = $this->pessoa($xpath, '//n:infNFSe/n:emit', '//n:infNFSe/n:emit/n:enderNac');

        foreach ($pessoa as $campo => $valor) {
            $pessoa[$campo] = $valor ?? ($emit[$campo] ?? null);
        }

        $pessoa['uf'] = $this->texto($xpath, '//n:infNFSe/n:emit/n:enderNac/n:UF');
        $pessoa['simples_nacional'] = $this->texto($xpath, '//n:infDPS/n:prest/n:regTrib/n:opSimpNac');
        $pessoa['regime_apuracao_sn'] = $this->texto($xpath, '//n:infDPS/n:prest/n:regTrib/n:regApTribSN');
        $pessoa['regime_especial'] = $this->texto($xpath, '//n:infDPS/n:prest/n:regTrib/n:regEspTrib');

        return $pessoa;
    }

    /**
     * Bloco de pessoa (prestador, tomador, destinatario, intermediario).
     *
     * Devolve sempre a mesma forma, com `null` no que faltar: o DANFSe imprime
     * traco em campo vazio (NT-008, item 2.4.5, nota 12), e para isso precisa
     * saber que o campo existe e esta' vazio.
     *
     * @return array<string, mixed>
     */
    private function pessoa(DOMXPath $xpath, string $base, ?string $endereco = null): array
    {
        $endereco ??= $base.'/n:end';

        return [
            'documento_tipo' => match (true) {
                $this->texto($xpath, $base.'/n:CNPJ') !== null => 'CNPJ',
                $this->texto($xpath, $base.'/n:CPF') !== null => 'CPF',
                $this->texto($xpath, $base.'/n:NIF') !== null => 'NIF',
                default => null,
            },
            'documento' => $this->texto($xpath, $base.'/n:CNPJ')
                ?? $this->texto($xpath, $base.'/n:CPF')
                ?? $this->texto($xpath, $base.'/n:NIF'),
            'im' => $this->texto($xpath, $base.'/n:IM'),
            'fone' => $this->texto($xpath, $base.'/n:fone'),
            'nome' => $this->texto($xpath, $base.'/n:xNome'),
            'email' => $this->texto($xpath, $base.'/n:email'),
            'logradouro' => $this->texto($xpath, $endereco.'/n:xLgr'),
            'numero' => $this->texto($xpath, $endereco.'/n:nro'),
            'complemento' => $this->texto($xpath, $endereco.'/n:xCpl'),
            'bairro' => $this->texto($xpath, $endereco.'/n:xBairro'),
            'cmun' => $this->texto($xpath, $endereco.'/n:endNac/n:cMun')
                ?? $this->texto($xpath, $endereco.'/n:cMun'),
            'cep' => $this->texto($xpath, $endereco.'/n:endNac/n:CEP')
                ?? $this->texto($xpath, $endereco.'/n:CEP'),
            'uf' => $this->texto($xpath, $endereco.'/n:UF'),
            // Endereco no exterior: o leiaute troca cMun/CEP por cidade, pais e
            // codigo postal livres.
            'cidade_exterior' => $this->texto($xpath, $endereco.'/n:endExt/n:xCidade'),
            'estado_exterior' => $this->texto($xpath, $endereco.'/n:endExt/n:xEstProvReg'),
            'pais' => $this->texto($xpath, $endereco.'/n:endExt/n:cPais'),
            'codigo_postal_exterior' => $this->texto($xpath, $endereco.'/n:endExt/n:cEndPost'),
        ];
    }

    /**
     * Tributacao municipal do DANFSe (NT-008, item 2.1.8).
     *
     * @return array<string, mixed>
     */
    private function issqn(DOMXPath $xpath): array
    {
        $mun = '//n:infDPS/n:valores/n:trib/n:tribMun';

        return [
            'tributacao' => $this->texto($xpath, $mun.'/n:tribISSQN'),
            'municipio_incidencia' => $this->texto($xpath, '//n:infNFSe/n:xLocIncid'),
            'codigo_municipio_incidencia' => $this->texto($xpath, '//n:infNFSe/n:cLocIncid'),
            'pais_resultado' => $this->texto($xpath, $mun.'/n:cPaisResult'),
            'tipo_imunidade' => $this->texto($xpath, $mun.'/n:tpImunidade'),
            'suspensao' => $this->texto($xpath, $mun.'/n:exigSusp/n:tpSusp'),
            'processo_suspensao' => $this->texto($xpath, $mun.'/n:exigSusp/n:nProcesso'),
            'beneficio_municipal' => $this->texto($xpath, '//n:infNFSe/n:valores/n:tpBM'),
            'calculo_bm' => $this->texto($xpath, '//n:infNFSe/n:valores/n:vCalcBM')
                ?? $this->texto($xpath, $mun.'/n:BM/n:vRedBCBM'),
            'total_deducoes' => $this->texto($xpath, '//n:infDPS/n:valores/n:vDedRed/n:vDR')
                ?? $this->texto($xpath, '//n:infNFSe/n:valores/n:vCalcDR'),
            'desconto_incondicionado' => $this->texto($xpath, '//n:infDPS/n:valores/n:vDescCondIncond/n:vDescIncond'),
            'base_calculo' => $this->texto($xpath, '//n:infNFSe/n:valores/n:vBC'),
            'aliquota' => $this->texto($xpath, '//n:infNFSe/n:valores/n:pAliqAplic'),
            'retencao' => $this->texto($xpath, $mun.'/n:tpRetISSQN'),
            'apurado' => $this->texto($xpath, '//n:infNFSe/n:valores/n:vISSQN'),
        ];
    }

    /**
     * Tributacao federal exceto CBS (NT-008, item 2.1.9).
     *
     * @return array<string, mixed>
     */
    private function federal(DOMXPath $xpath): array
    {
        $fed = '//n:infDPS/n:valores/n:trib/n:tribFed';

        return [
            'irrf' => $this->texto($xpath, $fed.'/n:vRetIRRF'),
            'previdenciaria' => $this->texto($xpath, $fed.'/n:vRetCP'),
            'sociais' => $this->texto($xpath, $fed.'/n:vRetCSLL'),
            'pis' => $this->texto($xpath, $fed.'/n:piscofins/n:vPis'),
            'cofins' => $this->texto($xpath, $fed.'/n:piscofins/n:vCofins'),
            'retencao_pis_cofins' => $this->texto($xpath, $fed.'/n:piscofins/n:tpRetPisCofins'),
        ];
    }

    /**
     * Tributacao IBS/CBS da Reforma Tributaria (NT-008, item 2.1.10).
     *
     * Ainda nao vem preenchida nos XMLs que o Emissor Nacional devolve hoje; o
     * bloco existe no DANFSe desde ja' porque a NT-008 o exige, e imprime traco
     * enquanto o grupo nao chega.
     *
     * @return array<string, mixed>
     */
    private function ibscbs(DOMXPath $xpath): array
    {
        $val = '//n:infNFSe/n:IBSCBS/n:valores';
        $tot = '//n:infNFSe/n:IBSCBS/n:totCIBS';

        return [
            'cst' => $this->texto($xpath, '//n:infDPS/n:IBSCBS/n:valores/n:trib/n:gIBSCBS/n:CST'),
            'classificacao' => $this->texto($xpath, '//n:infDPS/n:IBSCBS/n:valores/n:trib/n:gIBSCBS/n:cClassTrib'),
            'indicador_operacao' => $this->texto($xpath, '//n:infDPS/n:IBSCBS/n:cIndOp'),
            'codigo_municipio_incidencia' => $this->texto($xpath, '//n:infNFSe/n:IBSCBS/n:cLocalidadeIncid'),
            'municipio_incidencia' => $this->texto($xpath, '//n:infNFSe/n:IBSCBS/n:xLocalidadeIncid'),
            'reembolsos' => $this->texto($xpath, $val.'/n:vCalcReeRepRes'),
            'base_calculo' => $this->texto($xpath, $val.'/n:vBC'),
            'reducao_aliquota_uf' => $this->texto($xpath, $val.'/n:uf/n:pRedAliqUF'),
            'reducao_aliquota_mun' => $this->texto($xpath, $val.'/n:mun/n:pRedAliqMun'),
            'reducao_aliquota_cbs' => $this->texto($xpath, $val.'/n:fed/n:pRedAliqCBS'),
            'aliquota_ibs_uf' => $this->texto($xpath, $val.'/n:uf/n:pIBSUF'),
            'aliquota_ibs_mun' => $this->texto($xpath, $val.'/n:mun/n:pIBSMun'),
            'aliquota_efetiva_mun' => $this->texto($xpath, $val.'/n:mun/n:pAliqEfetMun'),
            'aliquota_efetiva_uf' => $this->texto($xpath, $val.'/n:uf/n:pAliqEfetUF'),
            'aliquota_cbs' => $this->texto($xpath, $val.'/n:fed/n:pCBS'),
            'aliquota_efetiva_cbs' => $this->texto($xpath, $val.'/n:fed/n:pAliqEfetCBS'),
            'valor_ibs_mun' => $this->texto($xpath, $tot.'/n:gIBS/n:gIBSMunTot/n:vIBSMun'),
            'valor_ibs_uf' => $this->texto($xpath, $tot.'/n:gIBS/n:gIBSUFTot/n:vIBSUF'),
            'valor_ibs_total' => $this->texto($xpath, $tot.'/n:gIBS/n:vIBSTot'),
            'valor_cbs' => $this->texto($xpath, $tot.'/n:gCBS/n:vCBS'),
            'valor_total_nf' => $this->texto($xpath, $tot.'/n:vTotNF'),
        ];
    }

    /**
     * Valor total da NFS-e (NT-008, item 2.1.11).
     *
     * @return array<string, mixed>
     */
    private function totais(DOMXPath $xpath): array
    {
        return [
            'servico' => $this->texto($xpath, '//n:infDPS/n:valores/n:vServPrest/n:vServ'),
            'desconto_incondicionado' => $this->texto($xpath, '//n:infDPS/n:valores/n:vDescCondIncond/n:vDescIncond'),
            'desconto_condicionado' => $this->texto($xpath, '//n:infDPS/n:valores/n:vDescCondIncond/n:vDescCond'),
            'retencoes' => $this->texto($xpath, '//n:infNFSe/n:valores/n:vTotalRet'),
            'liquido' => $this->texto($xpath, '//n:infNFSe/n:valores/n:vLiq'),
        ];
    }

    /**
     * Campos que a NT-008 (item 2.4.5) junta no bloco de informacoes
     * complementares, na ordem em que ela manda imprimi-los.
     *
     * @return array<string, mixed>
     */
    private function complementares(DOMXPath $xpath): array
    {
        $info = '//n:infDPS/n:serv/n:infoCompl';
        $totTrib = '//n:infDPS/n:valores/n:trib/n:totTrib';

        return [
            'informacao' => $this->texto($xpath, $info.'/n:xInfComp'),
            'chave_substituida' => $this->texto($xpath, '//n:infDPS/n:subst/n:chSubstda'),
            'documento_referencia' => $this->texto($xpath, $info.'/n:docRef'),
            'codigo_obra' => $this->texto($xpath, '//n:infDPS/n:serv/n:obra/n:cObra'),
            'inscricao_imobiliaria' => $this->texto($xpath, '//n:infDPS/n:IBSCBS/n:imovel/n:inscImobFisc'),
            'codigo_evento' => $this->texto($xpath, '//n:infDPS/n:serv/n:atvEvento/n:idAtvEvt'),
            'documento_tecnico' => $this->texto($xpath, $info.'/n:idDocTec'),
            'numero_pedido' => $this->texto($xpath, $info.'/n:gItemPed/n:xPed'),
            'item_pedido' => $this->texto($xpath, $info.'/n:gItemPed/n:xItemPed'),
            'informacao_municipio' => $this->texto($xpath, '//n:infNFSe/n:xOutInf'),
            'tributos_federais' => $this->texto($xpath, $totTrib.'/n:vTotTrib/n:vTotTribFed'),
            'tributos_estaduais' => $this->texto($xpath, $totTrib.'/n:vTotTrib/n:vTotTribEst'),
            'tributos_municipais' => $this->texto($xpath, $totTrib.'/n:vTotTrib/n:vTotTribMu'),
            'tributos_federais_percentual' => $this->texto($xpath, $totTrib.'/n:pTotTrib/n:pTotTribFed'),
            'tributos_estaduais_percentual' => $this->texto($xpath, $totTrib.'/n:pTotTrib/n:pTotTribEst'),
            'tributos_municipais_percentual' => $this->texto($xpath, $totTrib.'/n:pTotTrib/n:pTotTribMu'),
        ];
    }

    /**
     * O XML tem de ser da NOSSA empresa.
     *
     * Sem esta conferência dá para anexar a nota de outro emitente a uma OS e
     * ninguém percebe — o número e a chave parecem legítimos porque são. Num
     * sistema que vai ser vendido e operado por terceiros, isso acontece.
     */
    private function conferirPrestador(?string $prestador): void
    {
        $cadastrado = Documento::normalizar(
            (string) (($this->empresa->payload()['settings'] ?? [])['empresa_cnpj'] ?? '')
        );

        if ($cadastrado === null) {
            // Sem CNPJ cadastrado não há contra o que conferir. Recusar seria
            // pior: bloquearia quem ainda não terminou o cadastro da empresa.
            return;
        }

        if ($prestador !== null && $prestador !== $cadastrado) {
            throw ValidationException::withMessages([
                'arquivo' => sprintf(
                    'Esta nota foi emitida por outro CNPJ (%s). O cadastro da empresa é %s.',
                    Documento::formatar($prestador),
                    Documento::formatar($cadastrado)
                ),
            ]);
        }
    }

    /**
     * O portal entrega o XML com acentuação duplamente codificada
     * ("SÃ£o Pedro" em vez de "São Pedro"): o conteúdo já era UTF-8 e foi
     * encodado outra vez. Reverter é seguro — se o texto NÃO estiver duplicado,
     * a conversão de volta falha e devolvemos o original.
     */
    private function normalizarAcentuacao(string $conteudo): string
    {
        if (! str_contains($conteudo, 'Ã')) {
            return $conteudo;
        }

        $revertido = @mb_convert_encoding($conteudo, 'ISO-8859-1', 'UTF-8');

        return is_string($revertido) && $revertido !== '' && mb_check_encoding($revertido, 'UTF-8')
            ? $revertido
            : $conteudo;
    }

    private function texto(DOMXPath $xpath, string $consulta): ?string
    {
        $no = $xpath->query($consulta)->item(0);
        $valor = $no === null ? '' : trim((string) $no->nodeValue);

        return $valor === '' ? null : $valor;
    }
}
