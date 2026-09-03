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
 *
 * **A assinatura é conferida de verdade.** Sem isto, um XML montado à mão —
 * com o CNPJ do prestador certo, tomador certo, tudo bem-formado — entrava
 * como nota emitida e virava a prova guardada por cinco anos: nada aqui
 * provava que o arquivo tinha vindo do Ambiente Nacional. `AssinaturaXml`
 * (que o `DpsXmlBuilder` já usa para ASSINAR a DPS) faz a conta de conferir;
 * este importador só decide o que fazer com o veredito, via
 * `fiscal.nfse.exigir_assinatura_xml` — desligado, é para trabalhar com
 * amostra sem assinatura; ligado (o padrão), XML sem assinatura ou adulterado
 * é recusado antes de qualquer dado dele virar registro.
 */
class NfseXmlImporter
{
    private const NS = 'http://www.sped.fazenda.gov.br/nfse';

    /**
     * Tamanho máximo aceito, em bytes. Uma NFS-e real fica na casa de
     * dezenas de KB; um arquivo de megabytes não é nota fiscal, é ataque de
     * negação de serviço via upload.
     */
    private const TAMANHO_MAXIMO = 10 * 1024 * 1024;

    public function __construct(private readonly CompanyProfileService $empresa) {}

    /**
     * @return array<string, mixed>
     */
    public function ler(string $conteudo): array
    {
        if (strlen($conteudo) > self::TAMANHO_MAXIMO) {
            throw ValidationException::withMessages([
                'arquivo' => 'Arquivo grande demais para ser uma NFS-e (máximo 10 MB).',
            ]);
        }

        // A assinatura foi feita sobre os bytes ORIGINAIS. Normalizar a
        // acentuação antes de conferir invalidaria a assinatura de uma nota
        // legítima que veio com o defeito de dupla-codificação do portal —
        // por isso a conferência usa `$original`, e só a extração de dados
        // usa o texto normalizado.
        $original = $conteudo;
        $conteudo = $this->normalizarAcentuacao($conteudo);

        $dom = $this->carregar($conteudo);

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

        $this->conferirChave($chave);

        $assinatura = $this->conferirAssinatura($original);

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

            'assinatura_conferida' => $assinatura['conferida'],
            'assinatura_motivo' => $assinatura['motivo'],

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
    /**
     * Carrega o XML sem dar ao arquivo poderes que ele não precisa ter.
     *
     * `LIBXML_NONET` corta qualquer tentativa de buscar recurso externo
     * (DTD, entidade); DOCTYPE é recusado antes mesmo de chegar ao parser — é
     * por ele que passam tanto a leitura de arquivo do servidor (XXE) quanto
     * a expansão de entidade que trava o processo, e uma NFS-e nacional não
     * tem DTD nenhum legítimo para declarar.
     */
    private function carregar(string $conteudo): DOMDocument
    {
        if (preg_match('/<!DOCTYPE/i', $conteudo) === 1) {
            throw ValidationException::withMessages([
                'arquivo' => 'O XML tem DOCTYPE, que uma NFS-e não usa. Baixe o arquivo de novo no Emissor Nacional.',
            ]);
        }

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;

        $anterior = libxml_use_internal_errors(true);

        try {
            $carregado = @$dom->loadXML($conteudo, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }

        if (! $carregado) {
            throw ValidationException::withMessages([
                'arquivo' => 'O arquivo não é um XML válido.',
            ]);
        }

        return $dom;
    }

    /**
     * A chave de acesso tem 50 dígitos (NT-008, item 2.1.1) — nada além disso
     * é chave de NFS-e nacional. Não decodifica o conteúdo dela: o layout
     * exato de cada faixa de dígitos não está documentado o bastante para
     * apostar numa checagem campo a campo, e recusar uma nota real por um
     * decodificador errado é pior do que não ter o decodificador.
     */
    private function conferirChave(string $chave): void
    {
        if (preg_match('/^\d{50}$/', $chave) !== 1) {
            throw ValidationException::withMessages([
                'arquivo' => sprintf(
                    'A chave de acesso deveria ter 50 dígitos e tem %d ("%s"). '
                        .'O arquivo pode estar corrompido ou não ser uma NFS-e.',
                    strlen($chave),
                    $chave
                ),
            ]);
        }
    }

    /**
     * Confere a assinatura digital contra o que o próprio XML declarou —
     * `AssinaturaXml` faz a conta (canonicaliza, confere digest, confere RSA
     * contra o certificado embutido); aqui só se decide o que fazer com o
     * veredito.
     *
     * `exigir_assinatura_xml` desligado é para trabalhar com amostra sem
     * assinatura (documentação, XML de exemplo). Ligado — o padrão — é a
     * postura de produção: sem assinatura conferida, o arquivo não vira
     * registro.
     *
     * @return array{conferida: bool, motivo: ?string}
     */
    private function conferirAssinatura(string $original): array
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;

        $anterior = libxml_use_internal_errors(true);

        try {
            $carregado = @$dom->loadXML($original, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }

        $veredito = $carregado
            ? AssinaturaXml::conferir($dom)
            : ['assinado' => false, 'conferida' => false, 'motivo' => 'Não foi possível reler o arquivo original para conferir a assinatura.'];

        if ((bool) config('fiscal.nfse.exigir_assinatura_xml', true) && ! $veredito['conferida']) {
            throw ValidationException::withMessages([
                'arquivo' => (string) ($veredito['motivo'] ?? 'A assinatura do XML não pôde ser conferida.'),
            ]);
        }

        return ['conferida' => $veredito['conferida'], 'motivo' => $veredito['motivo']];
    }

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
