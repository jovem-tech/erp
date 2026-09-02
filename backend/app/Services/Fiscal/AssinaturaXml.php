<?php

namespace App\Services\Fiscal;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Canonicalização e conferência de assinatura XMLDSig.
 *
 * Nasceu de duas necessidades que se encontraram: o `DpsXmlBuilder` precisa
 * canonicalizar para ASSINAR, e o `NfseXmlImporter` precisa canonicalizar para
 * CONFERIR. Eram a mesma conta feita em dois lugares — e a segunda não existia,
 * que é o buraco que isto fecha: até aqui um XML montado à mão, com o CNPJ do
 * prestador e o CPF do tomador certos, era aceito como nota emitida e virava a
 * prova guardada por cinco anos.
 *
 * **A conferência não repete a chamada de quem assinou.** Ela lê os algoritmos
 * DECLARADOS no próprio documento e executa o que está escrito lá. É o único
 * jeito de pegar o defeito clássico desta área — declarar c14n inclusiva e
 * executar exclusiva —, que já apareceu neste repositório e que um verificador
 * "consistente consigo mesmo" nunca acusaria.
 */
final class AssinaturaXml
{
    public const NS_SIG = 'http://www.w3.org/2000/09/xmldsig#';

    /**
     * C14N exclusiva com comentários: foi o que um XML real do Ambiente
     * Nacional mostrou, e é o que o `DpsXmlBuilder` declara e executa.
     */
    public const C14N_PADRAO = 'http://www.w3.org/2001/10/xml-exc-c14n#WithComments';

    /**
     * `DOMNode::C14N($exclusiva, $comComentarios)` a partir da URI do algoritmo.
     *
     * O `#WithComments` no fim não é decorativo: muda os bytes canonicalizados.
     * Derivar os dois booleanos da URI — em vez de escrevê-los à mão — é o que
     * mantém declaração e execução impossíveis de divergir.
     */
    public static function canonicalizar(DOMElement $elemento, string $algoritmo = self::C14N_PADRAO): string
    {
        return $elemento->C14N(
            str_starts_with($algoritmo, 'http://www.w3.org/2001/10/xml-exc-c14n'),
            str_ends_with($algoritmo, '#WithComments')
        );
    }

    /**
     * Confere todas as assinaturas do documento.
     *
     * Devolve o veredito em vez de lançar: quem chama decide se XML sem
     * assinatura bloqueia (produção) ou só é registrado. "Não assinado" e
     * "assinado e adulterado" são coisas diferentes e pedem reações diferentes.
     *
     * @return array{assinado: bool, conferida: bool, motivo: ?string}
     */
    public static function conferir(DOMDocument $dom): array
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ds', self::NS_SIG);

        $assinaturas = $xpath->query('//ds:Signature');

        if ($assinaturas === false || $assinaturas->length === 0) {
            return [
                'assinado' => false,
                'conferida' => false,
                'motivo' => 'O XML não tem assinatura digital — não há como provar que veio do Ambiente Nacional.',
            ];
        }

        foreach ($assinaturas as $assinatura) {
            if (! $assinatura instanceof DOMElement) {
                continue;
            }

            $motivo = self::conferirUma($dom, $xpath, $assinatura);

            if ($motivo !== null) {
                return ['assinado' => true, 'conferida' => false, 'motivo' => $motivo];
            }
        }

        return ['assinado' => true, 'conferida' => true, 'motivo' => null];
    }

    /**
     * @return string|null motivo da falha, ou null quando confere
     */
    private static function conferirUma(DOMDocument $dom, DOMXPath $xpath, DOMElement $assinatura): ?string
    {
        $signedInfo = $xpath->query('./ds:SignedInfo', $assinatura)?->item(0);

        if (! $signedInfo instanceof DOMElement) {
            return 'Assinatura sem <SignedInfo>.';
        }

        $algoC14n = self::atributo($xpath, './ds:CanonicalizationMethod/@Algorithm', $signedInfo)
            ?? self::C14N_PADRAO;
        $algoAssinatura = self::atributo($xpath, './ds:SignatureMethod/@Algorithm', $signedInfo);
        $algoDigest = self::atributo($xpath, './ds:Reference/ds:DigestMethod/@Algorithm', $signedInfo);
        $uri = self::atributo($xpath, './ds:Reference/@URI', $signedInfo) ?? '';
        $digestDeclarado = self::texto($xpath, './ds:Reference/ds:DigestValue', $signedInfo);
        $valorAssinatura = self::texto($xpath, './ds:SignatureValue', $assinatura);
        $certificado = self::texto($xpath, './/ds:X509Certificate', $assinatura);

        if ($digestDeclarado === null || $valorAssinatura === null || $certificado === null) {
            return 'Assinatura incompleta (falta DigestValue, SignatureValue ou X509Certificate).';
        }

        $hashDigest = self::hashDeDigest((string) $algoDigest);
        $hashAssinatura = self::hashDeAssinatura((string) $algoAssinatura);

        if ($hashDigest === null || $hashAssinatura === null) {
            return 'Assinatura usa algoritmo não suportado ('.$algoDigest.' / '.$algoAssinatura.').';
        }

        // ---- 1) o digest corresponde ao conteudo assinado?
        //
        // A transform `enveloped-signature` manda tirar a propria Signature
        // antes de canonicalizar. Feito sobre uma CLONE: mexer no documento
        // original mudaria o que o chamador vai ler depois.
        $clone = new DOMDocument();
        $clone->preserveWhiteSpace = $dom->preserveWhiteSpace;
        $clone->appendChild($clone->importNode($dom->documentElement, true));

        $xpathClone = new DOMXPath($clone);
        $xpathClone->registerNamespace('ds', self::NS_SIG);

        foreach (iterator_to_array($xpathClone->query('//ds:Signature') ?: []) as $sig) {
            $sig->parentNode?->removeChild($sig);
        }

        $alvo = self::resolverReferencia($clone, $xpathClone, $uri);

        if (! $alvo instanceof DOMElement) {
            return 'A assinatura aponta para "'.$uri.'", que não existe no documento.';
        }

        // A c14n da Reference e' a declarada nas Transforms dela, que pode
        // diferir da usada no SignedInfo.
        $algoTransform = self::algoritmoDaTransform($xpath, $signedInfo) ?? $algoC14n;

        $digestCalculado = base64_encode(hash($hashDigest, self::canonicalizar($alvo, $algoTransform), true));

        if (! hash_equals($digestDeclarado, $digestCalculado)) {
            return 'O conteúdo do XML não confere com a assinatura — o arquivo foi alterado depois de assinado.';
        }

        // ---- 2) a assinatura confere com a chave publica do certificado?
        $chave = @openssl_pkey_get_public(self::pem($certificado));

        if ($chave === false) {
            return 'Não foi possível ler o certificado da assinatura.';
        }

        $confere = @openssl_verify(
            self::canonicalizar($signedInfo, $algoC14n),
            (string) base64_decode($valorAssinatura, true),
            $chave,
            $hashAssinatura
        );

        return $confere === 1
            ? null
            : 'A assinatura digital não confere com o certificado declarado no XML.';
    }

    /**
     * A Reference aponta para `#Id`. `getElementById` não serve: sem DTD nem
     * schema carregado o DOM não sabe qual atributo é do tipo ID.
     */
    private static function resolverReferencia(DOMDocument $dom, DOMXPath $xpath, string $uri): ?DOMElement
    {
        if ($uri === '') {
            return $dom->documentElement;
        }

        $id = ltrim($uri, '#');
        $no = $xpath->query(sprintf('//*[@Id=%s or @id=%s]', self::literal($id), self::literal($id)))?->item(0);

        return $no instanceof DOMElement ? $no : null;
    }

    private static function algoritmoDaTransform(DOMXPath $xpath, DOMElement $signedInfo): ?string
    {
        $transforms = $xpath->query('./ds:Reference/ds:Transforms/ds:Transform/@Algorithm', $signedInfo);

        foreach ($transforms ?: [] as $transform) {
            $algoritmo = (string) $transform->nodeValue;

            // A `enveloped-signature` nao e' c14n: ela so' manda remover a
            // Signature, o que ja' foi feito.
            if (! str_contains($algoritmo, 'enveloped-signature')) {
                return $algoritmo;
            }
        }

        return null;
    }

    private static function hashDeDigest(string $algoritmo): ?string
    {
        return match (true) {
            str_ends_with($algoritmo, '#sha256') => 'sha256',
            str_ends_with($algoritmo, '#sha384') => 'sha384',
            str_ends_with($algoritmo, '#sha512') => 'sha512',
            str_ends_with($algoritmo, '#sha1') => 'sha1',
            default => null,
        };
    }

    private static function hashDeAssinatura(string $algoritmo): ?int
    {
        return match (true) {
            str_ends_with($algoritmo, 'rsa-sha256') => OPENSSL_ALGO_SHA256,
            str_ends_with($algoritmo, 'rsa-sha384') => OPENSSL_ALGO_SHA384,
            str_ends_with($algoritmo, 'rsa-sha512') => OPENSSL_ALGO_SHA512,
            str_ends_with($algoritmo, 'rsa-sha1') => OPENSSL_ALGO_SHA1,
            default => null,
        };
    }

    private static function pem(string $base64): string
    {
        $limpo = (string) preg_replace('/\s+/', '', $base64);

        return "-----BEGIN CERTIFICATE-----\n"
            .chunk_split($limpo, 64, "\n")
            ."-----END CERTIFICATE-----\n";
    }

    private static function atributo(DOMXPath $xpath, string $consulta, DOMElement $contexto): ?string
    {
        $no = $xpath->query($consulta, $contexto)?->item(0);
        $valor = $no === null ? '' : trim((string) $no->nodeValue);

        return $valor === '' ? null : $valor;
    }

    private static function texto(DOMXPath $xpath, string $consulta, DOMElement $contexto): ?string
    {
        $no = $xpath->query($consulta, $contexto)?->item(0);
        $valor = $no === null ? '' : preg_replace('/\s+/', '', (string) $no->nodeValue);

        return ($valor ?? '') === '' ? null : (string) $valor;
    }

    /**
     * Literal de XPath 1.0 seguro para um Id vindo do arquivo — que é entrada
     * de fora, e XPath 1.0 não tem parâmetro ligado.
     */
    private static function literal(string $valor): string
    {
        if (! str_contains($valor, "'")) {
            return "'".$valor."'";
        }

        return 'concat("'.str_replace('"', '", \'"\', "', $valor).'")';
    }
}
