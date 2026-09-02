<?php

namespace App\Services\Fiscal;

use App\Models\DocumentoFiscal;
use App\Services\Company\CompanyProfileService;
use App\Support\Documento;
use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Monta e assina a DPS (Declaração de Prestação de Serviços) do padrão
 * nacional da NFS-e — specs/041-emissao-fiscal-nfse, fase 043.
 *
 * A DPS substituiu o antigo RPS: é o documento que o contribuinte envia, e o
 * Ambiente Nacional devolve a NFS-e a partir dela.
 *
 * O layout é validado contra o XSD oficial v1.01 por `DpsXmlBuilderTest` —
 * os esquemas estão versionados em `tests/Fixtures/nfse-schemas`.
 *
 * ⚠️ Uma exceção conhecida: o `pattern` de `serie` no pacote oficial é
 * insatisfazível (em XSD 1.0 `^` e `$` são literais e, com `maxLength=5`, só a
 * string "^1$" passa). É defeito do schema publicado, não do XML — o validador
 * do ADN necessariamente é mais permissivo. Ver `tests/Fixtures/nfse-schemas/ORIGEM.md`.
 *
 * A assinatura é XMLDSig envelopada, no mesmo desenho da NF-e: canonicaliza o
 * elemento assinado, faz o digest, monta o SignedInfo, canonicaliza de novo e
 * assina com a chave privada do A1.
 */
class DpsXmlBuilder
{
    private const NS_DPS = 'http://www.sped.fazenda.gov.br/nfse';

    private const NS_SIG = 'http://www.w3.org/2000/09/xmldsig#';

    /**
     * Algoritmos da assinatura, numa fonte unica.
     *
     * O `#WithComments` no fim do C14N nao e' decorativo: ele muda os bytes
     * canonicalizados. Declarar um algoritmo e executar outro produz assinatura
     * que o gerador considera valida e o verificador rejeita.
     */
    private const ALGO_C14N = AssinaturaXml::C14N_PADRAO;

    private const ALGO_ASSINATURA = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

    private const ALGO_DIGEST = 'http://www.w3.org/2001/04/xmlenc#sha256';

    private const HASH_PHP = 'sha256';

    private const HASH_OPENSSL = OPENSSL_ALGO_SHA256;

    public function __construct(
        private readonly CertificadoA1 $certificado,
        private readonly CompanyProfileService $empresa
    ) {}

    /**
     * XML da DPS já assinado, pronto para transmissão.
     */
    public function gerarAssinado(DocumentoFiscal $documento, int $numeroDps): string
    {
        $xml = $this->montar($documento, $numeroDps);

        return $this->assinar($xml, 'infDPS');
    }

    /**
     * XML sem assinatura — útil para conferir o layout contra o XSD antes de
     * ter certificado.
     */
    public function montar(DocumentoFiscal $documento, int $numeroDps): string
    {
        $config = (array) config('fiscal.nfse');
        $settings = (array) ($this->empresa->payload()['settings'] ?? []);

        $cnpjPrestador = Documento::normalizar((string) ($settings['empresa_cnpj'] ?? ''));
        $codigoMunicipio = trim((string) ($settings['empresa_codigo_ibge'] ?? ''));

        if ($cnpjPrestador === null) {
            throw new RuntimeException('CNPJ da empresa não cadastrado — sem ele a DPS não é aceita.');
        }

        if ($codigoMunicipio === '') {
            throw new RuntimeException('Código IBGE do município da empresa não cadastrado.');
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $dps = $dom->createElementNS(self::NS_DPS, 'DPS');
        $dps->setAttribute('versao', '1.00');
        $dom->appendChild($dps);

        // O Id e' o ancoradouro da assinatura: a Reference aponta para ele.
        $idDps = $this->montarId($cnpjPrestador, (string) $config['serie'], $numeroDps);

        $inf = $dom->createElement('infDPS');
        $inf->setAttribute('Id', $idDps);
        $dps->appendChild($inf);

        $this->texto($dom, $inf, 'tpAmb', (string) $config['ambiente']);
        $this->texto($dom, $inf, 'dhEmi', now()->toIso8601String());
        $this->texto($dom, $inf, 'verAplic', (string) $config['versao_aplicativo']);
        $this->texto($dom, $inf, 'serie', (string) $config['serie']);
        $this->texto($dom, $inf, 'nDPS', (string) $numeroDps);
        $this->texto($dom, $inf, 'dCompet', now()->format('Y-m-d'));
        // 1 = emitido pelo proprio prestador.
        $this->texto($dom, $inf, 'tpEmit', '1');
        $this->texto($dom, $inf, 'cLocEmi', $codigoMunicipio);

        // ---- prestador ----
        $prest = $dom->createElement('prest');
        $inf->appendChild($prest);
        $this->texto($dom, $prest, strlen($cnpjPrestador) === 14 ? 'CNPJ' : 'CPF', $cnpjPrestador);

        $inscricaoMunicipal = trim((string) ($settings['empresa_inscricao_municipal'] ?? ''));
        if ($inscricaoMunicipal !== '') {
            $this->texto($dom, $prest, 'IM', $inscricaoMunicipal);
        }

        // regTrib: `opSimpNac` e `regEspTrib` sao AMBOS obrigatorios pelo XSD.
        // Faltava o segundo, e o schema so' acusou quando foi validado de
        // verdade — layout "quase certo" e' rejeitado igual a layout errado.
        $regime = $dom->createElement('regTrib');
        $prest->appendChild($regime);
        // 1..3. Confirmar com o contador qual e' o caso da assistencia.
        $this->texto($dom, $regime, 'opSimpNac', (string) $config['regime_tributario']);
        // 0 = nenhum regime especial.
        $this->texto($dom, $regime, 'regEspTrib', (string) $config['regime_especial']);

        // ---- tomador ----
        $documentoTomador = Documento::normalizar((string) $documento->tomador_documento);

        if ($documentoTomador === null) {
            // A NFS-e exige identificar o tomador. Falhar aqui, com mensagem
            // clara, e' melhor que ser rejeitado pelo ADN depois.
            throw new RuntimeException('Tomador sem CPF/CNPJ — a NFS-e exige identificar quem recebeu o serviço.');
        }

        $toma = $dom->createElement('toma');
        $inf->appendChild($toma);
        $this->texto($dom, $toma, strlen($documentoTomador) === 14 ? 'CNPJ' : 'CPF', $documentoTomador);
        $this->texto($dom, $toma, 'xNome', (string) $documento->tomador_nome);

        // ---- serviço ----
        $serv = $dom->createElement('serv');
        $inf->appendChild($serv);

        // A ordem e' fixa: <locPrest> antes de <cServ>. Ambos obrigatorios.
        $locPrest = $dom->createElement('locPrest');
        $serv->appendChild($locPrest);
        $this->texto($dom, $locPrest, 'cLocPrestacao', $codigoMunicipio);

        $codigoTributacao = trim((string) ($settings['empresa_codigo_tributacao_nacional'] ?? ''));

        if ($codigoTributacao === '') {
            throw new RuntimeException(
                'Código de tributação nacional do serviço não cadastrado — confirme com o contador.'
            );
        }

        $cServ = $dom->createElement('cServ');
        $serv->appendChild($cServ);
        $this->texto($dom, $cServ, 'cTribNac', $codigoTributacao);
        $this->texto($dom, $cServ, 'xDescServ', (string) $documento->discriminacao);

        // <cNBS> e' opcional no XSD e vem DEPOIS de <xDescServ> na sequencia.
        $cnbs = trim((string) ($config['cnbs'] ?? ''));

        if ($cnbs !== '') {
            $this->texto($dom, $cServ, 'cNBS', $cnbs);
        }

        // ---- valores ----
        // Só o serviço entra: peça é mercadoria e sai por NF-e/NFC-e.
        $valores = $dom->createElement('valores');
        $inf->appendChild($valores);

        $servicoPrestado = $dom->createElement('vServPrest');
        $valores->appendChild($servicoPrestado);
        $this->texto($dom, $servicoPrestado, 'vServ', number_format((float) $documento->valor_servicos, 2, '.', ''));

        // <trib> e' obrigatorio, e dentro dele <tribMun> e <totTrib>.
        $trib = $dom->createElement('trib');
        $valores->appendChild($trib);

        $tribMun = $dom->createElement('tribMun');
        $trib->appendChild($tribMun);
        $this->texto($dom, $tribMun, 'tribISSQN', (string) $config['tributacao_issqn']);
        $this->texto($dom, $tribMun, 'tpRetISSQN', (string) $config['retencao_issqn']);

        $totTrib = $dom->createElement('totTrib');
        $trib->appendChild($totTrib);
        // `indTotTrib` = 0: nao informar o total de tributos. Alternativas do
        // schema (vTotTrib, pTotTrib, pTotTribSN) exigem numero que a
        // assistencia nao tem como apurar sozinha — declarar zero seria pior
        // que nao declarar.
        $this->texto($dom, $totTrib, 'indTotTrib', '0');

        return (string) $dom->saveXML();
    }

    /**
     * Assinatura XMLDSig envelopada sobre o elemento de `Id` informado.
     */
    public function assinar(string $xml, string $tagAssinada): string
    {
        $pem = $this->certificado->pem();

        if ($pem === null) {
            throw new RuntimeException('Certificado A1 indisponível: '.implode(' ', $this->certificado->problemas()));
        }

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        if (! $dom->loadXML($xml)) {
            throw new RuntimeException('XML da DPS inválido.');
        }

        $alvo = $dom->getElementsByTagName($tagAssinada)->item(0);

        if (! $alvo instanceof DOMElement) {
            throw new RuntimeException("Elemento <{$tagAssinada}> não encontrado para assinar.");
        }

        $id = $alvo->getAttribute('Id');

        if ($id === '') {
            throw new RuntimeException("Elemento <{$tagAssinada}> sem atributo Id — a Reference não teria âncora.");
        }

        // 1) digest do elemento canonicalizado
        //
        // SHA-256 e C14N EXCLUSIVA com comentarios — foi o que um XML real do
        // Ambiente Nacional mostrou (`xml-exc-c14n#WithComments` +
        // `rsa-sha256`). A versao anterior usava SHA-1 e, pior, DECLARAVA
        // c14n inclusiva enquanto canonicalizava exclusiva: um verificador
        // seguindo a declaracao recalcularia bytes diferentes e a assinatura
        // cairia. As constantes abaixo existem para declaracao e execucao
        // nunca mais se separarem.
        $digest = base64_encode(hash(self::HASH_PHP, $this->canonicalizar($alvo), true));

        // 2) SignedInfo
        $signature = $dom->createElementNS(self::NS_SIG, 'Signature');
        $signedInfo = $dom->createElementNS(self::NS_SIG, 'SignedInfo');
        $signature->appendChild($signedInfo);

        $c14n = $dom->createElementNS(self::NS_SIG, 'CanonicalizationMethod');
        $c14n->setAttribute('Algorithm', self::ALGO_C14N);
        $signedInfo->appendChild($c14n);

        $sigMethod = $dom->createElementNS(self::NS_SIG, 'SignatureMethod');
        $sigMethod->setAttribute('Algorithm', self::ALGO_ASSINATURA);
        $signedInfo->appendChild($sigMethod);

        $reference = $dom->createElementNS(self::NS_SIG, 'Reference');
        $reference->setAttribute('URI', '#'.$id);
        $signedInfo->appendChild($reference);

        $transforms = $dom->createElementNS(self::NS_SIG, 'Transforms');
        $reference->appendChild($transforms);
        foreach ([
            'http://www.w3.org/2000/09/xmldsig#enveloped-signature',
            self::ALGO_C14N,
        ] as $algoritmo) {
            $transform = $dom->createElementNS(self::NS_SIG, 'Transform');
            $transform->setAttribute('Algorithm', $algoritmo);
            $transforms->appendChild($transform);
        }

        $digestMethod = $dom->createElementNS(self::NS_SIG, 'DigestMethod');
        $digestMethod->setAttribute('Algorithm', self::ALGO_DIGEST);
        $reference->appendChild($digestMethod);
        $reference->appendChild($dom->createElementNS(self::NS_SIG, 'DigestValue', $digest));

        // 3) ANEXA antes de assinar.
        //
        // O C14N de um elemento depende do contexto de namespace: solto, o
        // SignedInfo canonicaliza com o proprio `xmlns` do XMLDSig; depois de
        // pendurado sob <Signature>, que declara o mesmo namespace, a
        // declaracao vira redundante e o C14N a descarta. Assinar antes de
        // anexar produz bytes diferentes dos que o verificador vai recalcular,
        // e a assinatura falha — sem erro nenhum na geracao.
        $alvo->parentNode?->appendChild($signature);

        // 4) assina o SignedInfo canonicalizado, ja' no lugar definitivo
        $assinatura = '';
        if (! openssl_sign($this->canonicalizar($signedInfo), $assinatura, $pem['pkey'], self::HASH_OPENSSL)) {
            throw new RuntimeException('Falha ao assinar a DPS com a chave privada do certificado.');
        }

        $signature->appendChild(
            $dom->createElementNS(self::NS_SIG, 'SignatureValue', base64_encode($assinatura))
        );

        $keyInfo = $dom->createElementNS(self::NS_SIG, 'KeyInfo');
        $signature->appendChild($keyInfo);
        $x509Data = $dom->createElementNS(self::NS_SIG, 'X509Data');
        $keyInfo->appendChild($x509Data);
        $x509Data->appendChild(
            $dom->createElementNS(self::NS_SIG, 'X509Certificate', $this->certificadoBase64($pem['cert']))
        );

        return (string) $dom->saveXML();
    }

    /**
     * Id da DPS no formato do padrão nacional: "DPS" + código do município +
     * tipo de inscrição + inscrição + série + número.
     */
    private function montarId(string $documentoPrestador, string $serie, int $numero): string
    {
        $settings = (array) ($this->empresa->payload()['settings'] ?? []);
        $municipio = str_pad(trim((string) ($settings['empresa_codigo_ibge'] ?? '')), 7, '0', STR_PAD_LEFT);
        $tipoInscricao = strlen($documentoPrestador) === 14 ? '2' : '1';

        return 'DPS'
            .$municipio
            .$tipoInscricao
            .str_pad($documentoPrestador, 14, '0', STR_PAD_LEFT)
            .str_pad($serie, 5, '0', STR_PAD_LEFT)
            .str_pad((string) $numero, 15, '0', STR_PAD_LEFT);
    }

    /**
     * Canonicaliza segundo `self::ALGO_C14N`. Existe para que a declaracao no
     * XML e o calculo real venham do MESMO lugar — agora compartilhado com o
     * `NfseXmlImporter`, que faz a conta inversa ao conferir a assinatura de um
     * XML que chega de fora.
     */
    private function canonicalizar(DOMElement $elemento): string
    {
        return AssinaturaXml::canonicalizar($elemento, self::ALGO_C14N);
    }

    private function certificadoBase64(string $pem): string
    {
        return trim((string) preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s/', '', $pem));
    }

    private function texto(DOMDocument $dom, DOMElement $pai, string $nome, string $valor): void
    {
        $pai->appendChild($dom->createElement($nome, htmlspecialchars($valor, ENT_XML1 | ENT_QUOTES, 'UTF-8')));
    }
}
