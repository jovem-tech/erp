<?php

namespace Tests\Feature\Fiscal;

use App\Services\Fiscal\AssinaturaXml;
use DOMDocument;
use DOMXPath;
use Tests\TestCase;

/**
 * Conferencia de assinatura numa NFS-e REAL do Ambiente Nacional.
 *
 * A nota que o ADN devolve tem DUAS assinaturas aninhadas: a do contribuinte,
 * sobre a `infDPS` enviada, e a do proprio ADN, sobre a `infNFSe` — que contem
 * a DPS assinada dentro dela.
 *
 * Esse arranjo quebrava a conferencia por dois defeitos independentes, e os
 * dois davam a MESMA mensagem: "o arquivo foi alterado depois de assinado" —
 * a acusacao mais grave que este codigo sabe fazer, contra um documento
 * legitimo do governo.
 *
 * Nenhum deles aparecia nos testes porque a unica fixture assinada do repo
 * tinha a assinatura removida.
 */
class AssinaturaAninhadaTest extends TestCase
{
    public function test_confere_a_assinatura_de_uma_nfse_real_do_ambiente_nacional(): void
    {
        $dom = new DOMDocument();
        $dom->load(base_path('tests/Fixtures/nfse/nfse-real-adn-assinada.xml'));

        $resultado = AssinaturaXml::conferir($dom);

        $this->assertTrue($resultado['assinado']);
        $this->assertTrue($resultado['conferida'], (string) $resultado['motivo']);
        $this->assertNull($resultado['motivo']);
    }

    public function test_a_fixture_tem_mesmo_as_duas_assinaturas_aninhadas(): void
    {
        // Se alguem "simplificar" a fixture removendo uma assinatura, o teste
        // acima passa a nao provar nada. Este aqui protege a prova.
        // Contado pelo DOM: `substr_count('<Signature')` tambem casaria
        // `<SignatureValue>` e `<SignatureMethod>`.
        $dom = new DOMDocument();
        $dom->load(base_path('tests/Fixtures/nfse/nfse-real-adn-assinada.xml'));
        $xpath = new DOMXPath($dom);

        $this->assertSame(2, $xpath->query('//*[local-name()="Signature"]')->length);
        // A DPS assinada tem de estar DENTRO da infNFSe assinada — e' esse
        // aninhamento que os dois defeitos corrigidos nao sabiam tratar.
        $this->assertSame(1, $xpath->query('//*[local-name()="infNFSe"]//*[local-name()="infDPS"]')->length);
    }

    public function test_adulteracao_continua_sendo_recusada(): void
    {
        // A correcao nao pode ter afrouxado a guarda: mexer no conteudo tem de
        // continuar reprovando.
        $conteudo = (string) file_get_contents(base_path('tests/Fixtures/nfse/nfse-real-adn-assinada.xml'));
        $adulterado = str_replace('<vLiq>', '<vLiq>9', $conteudo);
        $this->assertNotSame($conteudo, $adulterado);

        $dom = new DOMDocument();
        $dom->loadXML($adulterado);

        $resultado = AssinaturaXml::conferir($dom);

        $this->assertTrue($resultado['assinado']);
        $this->assertFalse($resultado['conferida']);
    }
}
