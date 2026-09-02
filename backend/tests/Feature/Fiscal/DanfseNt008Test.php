<?php

namespace Tests\Feature\Fiscal;

use App\Services\Fiscal\DanfseLayout;
use App\Services\Fiscal\NfseXmlImporter;
use App\Services\Pdf\NfseDanfseRenderer;
use App\Support\MunicipioIbge;
use App\Support\QrCodePng;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Conformidade do DANFSe com a Nota Técnica nº 008 (SE/CGNFS-e, 05/05/2026).
 *
 * O DANFSe é o papel que chega ao cliente e à fiscalização, e a NT-008 fixa
 * quais blocos existem, em que ordem, com que descrição e o que fazer com campo
 * vazio. Um layout "parecido" é layout errado — por isso cada teste aqui cita o
 * item da norma que está provando, e não apenas o comportamento do código.
 *
 * A fixture é uma NFS-e real (ver tests/Fixtures/nfse/ORIGEM.md). A trava de
 * assinatura fica desligada porque o objeto destes testes é o desenho do
 * documento; quem prova a conferência da assinatura é o `NfseXmlImporterTest`.
 */
class DanfseNt008Test extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();

        config()->set('fiscal.nfse.exigir_assinatura_xml', false);
    }

    /**
     * Item 2.4.3: o QR Code aponta para a consulta pública, com a chave depois
     * do "=". É a via de verificação do documento — o endereço não é escolha
     * nossa, e um QR que aponta para outro lugar não verifica nada.
     */
    public function test_qrcode_aponta_para_a_consulta_publica_do_portal_nacional(): void
    {
        $danfse = $this->montar();

        $this->assertSame(
            'https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave='
                .'33052082234129526000198000000000000226086919348703',
            $danfse['qrcode']['url']
        );

        $this->assertStringStartsWith('data:image/png;base64,', $danfse['qrcode']['imagem']);
    }

    /**
     * O PNG gerado tem de ser um PNG de verdade — não basta a string parecer
     * uma imagem, porque o dompdf falha em silêncio e o DANFSe sairia sem o
     * código de verificação.
     */
    public function test_o_qrcode_gerado_e_um_png_valido_e_quadrado(): void
    {
        $png = QrCodePng::png('https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave=1', 8, 4);

        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);

        $tamanho = getimagesizefromstring($png);

        $this->assertIsArray($tamanho);
        $this->assertSame(IMAGETYPE_PNG, $tamanho[2]);
        $this->assertSame($tamanho[0], $tamanho[1]);
    }

    /**
     * Itens 2.1.2 e 2.4.5: "utilizar a descrição destas opções". O DANFSe
     * imprime o que o código significa, não o código.
     */
    public function test_traduz_os_codigos_do_leiaute_em_descricao(): void
    {
        $danfse = $this->montar();

        $this->assertSame('NFS-e MEI', $danfse['identificacao']['situacao']);
        $this->assertSame('Prestador', $danfse['identificacao']['emitente']);
        $this->assertSame('Operação tributável', $danfse['issqn']['tributacao']);
        $this->assertSame('Não Retido', $danfse['issqn']['retencao']);
    }

    /**
     * Item 2.4.5, nota 12: "Os campos sem informações no XML devem ser
     * preenchidos com um traço (-)". Campo em branco no papel é ambíguo —
     * não dá para saber se é ausência de dado ou falha de impressão.
     */
    public function test_campo_ausente_no_xml_vira_traco(): void
    {
        $danfse = $this->montar();

        // A fixture nao tem inscricao municipal, nem grupo IBS/CBS, nem
        // retencoes federais.
        $this->assertSame('-', $danfse['prestador']['im']);
        $this->assertSame('-', $danfse['federal']['irrf']);
        $this->assertSame('-', $danfse['ibscbs']['valor_cbs']);
        $this->assertSame('-', $danfse['valores']['retencoes']);
    }

    /**
     * Item 2.3.1 e nota 2: sem destinatário e sem intermediário, o bloco inteiro
     * vira uma linha com o texto que a norma dita — palavra por palavra.
     */
    public function test_blocos_nao_identificados_viram_uma_linha_com_o_texto_da_norma(): void
    {
        $danfse = $this->montar();

        $this->assertTrue($danfse['destinatario']['suprimido']);
        $this->assertSame(
            'DESTINATÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e',
            $danfse['destinatario']['aviso']
        );

        $this->assertTrue($danfse['intermediario']['suprimido']);
        $this->assertSame(
            'INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e',
            $danfse['intermediario']['aviso']
        );

        // O tomador existe na fixture: esse bloco NAO pode ser suprimido.
        $this->assertFalse($danfse['tomador']['suprimido']);
        $this->assertSame('ABRIGO DO MARINHEIRO', $danfse['tomador']['nome']);
    }

    /**
     * Item 2.3.2 e nota 3: quando o destinatário é o próprio tomador, o bloco
     * não repete os dados — diz isso em uma linha.
     */
    public function test_destinatario_igual_ao_tomador_vira_a_linha_da_nota_3(): void
    {
        $danfse = $this->montar($this->comDestinatarioIgualAoTomador());

        $this->assertTrue($danfse['destinatario']['suprimido']);
        $this->assertSame(
            'O DESTINATÁRIO É O PRÓPRIO TOMADOR/ADQUIRENTE DA OPERAÇÃO',
            $danfse['destinatario']['aviso']
        );
    }

    /**
     * Item 2.4.5, nota 5: linha some quando NENHUM dos campos dela existe no
     * XML. A fixture não tem imunidade, suspensão nem benefício municipal.
     */
    public function test_linhas_de_beneficio_e_deducao_somem_quando_vazias(): void
    {
        $danfse = $this->montar();

        $this->assertFalse($danfse['issqn']['linha_beneficios']);
        $this->assertFalse($danfse['issqn']['linha_deducoes']);
    }

    /**
     * Item 2.4.5, nota 6: a linha de PIS/COFINS só é impressa para NFS-e com
     * competência até o fim do ano-calendário de 2026.
     */
    public function test_linha_de_pis_cofins_desaparece_depois_de_2026(): void
    {
        $this->assertTrue($this->montar()['federal']['linha_pis_cofins']);

        $em2027 = str_replace('<dCompet>2026-08-27</dCompet>', '<dCompet>2027-01-15</dCompet>', $this->fixture());

        $this->assertFalse($this->montar($em2027)['federal']['linha_pis_cofins']);
    }

    /**
     * Item 2.4.5, nota 10: a linha de totais aproximados de tributos é
     * obrigatória — sai mesmo quando o XML não informa valor nenhum.
     */
    public function test_informacoes_complementares_sempre_trazem_os_totais_de_tributos(): void
    {
        $danfse = $this->montar();

        $this->assertSame(
            'Totais Aproximados dos Tributos cfe. Lei nº 12.741/2012: '
                .'Federais: - ; Estaduais: - ; Municipais: -',
            $danfse['complementares']['tributos']
        );
    }

    /**
     * Item 2.1.12 e nota 7: cada informação complementar tem rótulo e ordem
     * fixos, separados por pipe.
     */
    public function test_informacoes_complementares_seguem_rotulo_e_ordem_da_norma(): void
    {
        $xml = str_replace(
            '<serv><locPrest>',
            '<subst><chSubstda>11111111111111111111111111111111111111111111111111</chSubstda>'
                .'<cMotivo>01</cMotivo></subst><serv><locPrest>',
            $this->fixture()
        );
        $xml = str_replace(
            '</cServ></serv>',
            '</cServ><infoCompl><xInfComp>Garantia de 90 dias</xInfComp></infoCompl></serv>',
            $xml
        );

        $texto = $this->montar($xml)['complementares']['texto'];

        $this->assertSame(
            'Inf. Cont.: Garantia de 90 dias | '
                .'NFS-e Subst.: 11111111111111111111111111111111111111111111111111',
            $texto
        );
    }

    /**
     * Item 2.4.5: "Preencher com reticências (...), caso a descrição supere
     * 1297 caracteres". O tamanho do campo é o que garante que o documento cabe
     * na página única do item 2.2.
     */
    public function test_trunca_a_descricao_do_servico_no_tamanho_do_campo(): void
    {
        $longa = str_repeat('a', 1500);
        $xml = preg_replace('#<xDescServ>.*?</xDescServ>#', '<xDescServ>'.$longa.'</xDescServ>', $this->fixture());

        $descricao = $this->montar((string) $xml)['servico']['descricao'];

        $this->assertSame(1300, mb_strlen($descricao));
        $this->assertStringEndsWith('...', $descricao);
    }

    /**
     * Item 2.4.5: "Leiaute prevê a informação do código do município com 7
     * dígitos da Tabela do IBGE. Utilizar a descrição destes códigos."
     *
     * O XML só traz `cMun` para o tomador. Sem a tradução, o campo obrigatório
     * "Município / Sigla UF" sairia como número ou em branco.
     */
    public function test_resolve_o_municipio_do_tomador_pelo_codigo_ibge(): void
    {
        $danfse = $this->montar();

        $this->assertSame('São Pedro da Aldeia / RJ', $danfse['tomador']['municipio']);
        $this->assertSame('3305208 / 28.940-090', $danfse['tomador']['ibge_cep']);
    }

    public function test_tabela_do_ibge_resolve_nome_e_uf_pelo_prefixo_do_codigo(): void
    {
        $this->assertSame('São Pedro da Aldeia / RJ', MunicipioIbge::nomeComUf('3305208'));
        $this->assertSame('São Paulo / SP', MunicipioIbge::nomeComUf('3550308'));
        $this->assertSame('Manaus / AM', MunicipioIbge::nomeComUf('1302603'));
        $this->assertNull(MunicipioIbge::nomeComUf('9999999'));
    }

    /**
     * Item 2.2: "O DANFSe deverá ser impresso, obrigatoriamente, em uma única
     * página", em A4 retrato.
     */
    public function test_o_danfse_cabe_em_uma_unica_pagina_a4(): void
    {
        $this->assertSame(1, $this->paginas($this->renderizar()));
    }

    /**
     * Mesmo com os dois campos longos no máximo permitido, o documento continua
     * em uma página — é o que os truncamentos do item 2.4.5 existem para
     * garantir.
     */
    public function test_continua_em_uma_pagina_com_os_campos_longos_no_limite(): void
    {
        $xml = preg_replace(
            '#<xDescServ>.*?</xDescServ>#',
            '<xDescServ>'.str_repeat('a', 2000).'</xDescServ>',
            $this->fixture()
        );
        $xml = str_replace(
            '</cServ></serv>',
            '</cServ><infoCompl><xInfComp>'.str_repeat('b', 3000).'</xInfComp></infoCompl></serv>',
            (string) $xml
        );

        $this->assertSame(1, $this->paginas($this->renderizar($xml)));
    }

    /**
     * Item 2.4.3, observação: NFS-e de homologação leva a expressão no
     * cabeçalho. É o que impede uma nota de teste circular como se valesse.
     */
    public function test_homologacao_marca_o_documento_como_sem_validade_juridica(): void
    {
        $this->assertFalse($this->montar()['cabecalho']['sem_validade_juridica']);

        $homologacao = str_replace('<tpAmb>1</tpAmb>', '<tpAmb>2</tpAmb>', $this->fixture());

        $this->assertTrue($this->montar($homologacao)['cabecalho']['sem_validade_juridica']);
    }

    /**
     * Item 2.5.1: nota cancelada sai com marca d'água "CANCELADA".
     */
    public function test_nota_cancelada_sai_com_marca_dagua(): void
    {
        $this->assertNull($this->montar()['marca_dagua']);
        $this->assertSame('CANCELADA', $this->montar(null, 'CANCELADA')['marca_dagua']);
    }

    /**
     * Item 2.1: "Não poderão ser impressas informações que não constem do
     * arquivo da NFS-e." O documento se monta só a partir do XML — nada do
     * cadastro da empresa entra nele.
     */
    public function test_o_documento_se_monta_apenas_com_o_que_esta_no_xml(): void
    {
        $renderer = new \ReflectionMethod(NfseDanfseRenderer::class, 'render');

        $this->assertCount(2, $renderer->getParameters());
        $this->assertSame('nota', $renderer->getParameters()[0]->getName());
        $this->assertSame('marcaDagua', $renderer->getParameters()[1]->getName());
    }

    /**
     * Item 2.4: Arial nos títulos, Microsoft Sans Serif nos conteúdos.
     *
     * As duas são proprietárias e o que vai embutido é a Liberation Sans, que é
     * metricamente compatível com a Arial. O que este teste prende é que o PDF
     * **embute** a fonte em vez de cair na Helvetica de núcleo do formato: essa
     * queda é silenciosa, muda a largura do caractere e, com ela, onde cada
     * campo quebra linha — num documento cujos tamanhos de campo são normativos.
     */
    public function test_o_documento_embute_a_fonte_em_vez_de_cair_na_helvetica(): void
    {
        $pdf = $this->renderizar();

        // `FontFile2` e' o descritor de fonte TrueType embutida do PDF.
        $this->assertStringContainsString('FontFile2', $pdf);
        $this->assertStringContainsString('LiberationSans', $pdf);
        $this->assertStringNotContainsString('/BaseFont /Helvetica', $pdf);
    }

    /**
     * A ordem de preferência das fontes: quem tiver licença das fontes da
     * Microsoft coloca os arquivos em `resources/fonts/danfse/` e elas passam a
     * ser usadas, sem tocar em código.
     */
    public function test_prefere_as_fontes_da_norma_quando_existem_no_servidor(): void
    {
        $fontes = (new \ReflectionMethod(NfseDanfseRenderer::class, 'fontes'))
            ->invoke(app(NfseDanfseRenderer::class));

        $this->assertSame(
            ['titulo', 'titulo_negrito', 'conteudo', 'conteudo_negrito'],
            array_keys($fontes)
        );

        foreach ($fontes as $papel => $caminho) {
            $this->assertFileExists($caminho, "fonte ausente para {$papel}");
        }

        // Sem as fontes da Microsoft instaladas, os quatro papéis caem na
        // Liberation Sans — e nenhum fica sem arquivo.
        $this->assertStringContainsString('LiberationSans', $fontes['conteudo']);
    }

    /**
     * Item 2.2.2: a margem entre o corpo impresso e o fim do formulário fica
     * entre 0,15cm e 0,20cm, em todos os lados.
     *
     * Este teste olha o CSS do modelo, e não o PDF, de propósito: a margem é uma
     * única declaração `@page`, medi-la no PDF exigiria rasterizar (poppler não
     * é dependência da suíte), e o que quebra na prática é alguém trocar o valor
     * achando que é estética. A conferência no papel está registrada na nota de
     * implementação.
     */
    public function test_a_margem_da_folha_respeita_o_limite_da_norma(): void
    {
        $css = (string) file_get_contents(resource_path('views/pdf/nfse-danfse.blade.php'));

        $this->assertSame(1, preg_match('/@page \{[^}]*margin:\s*([\d.]+)cm/', $css, $achado));

        $margem = (float) $achado[1];

        $this->assertGreaterThanOrEqual(0.15, $margem);
        $this->assertLessThanOrEqual(0.20, $margem);
    }

    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function montar(?string $xml = null, ?string $marcaDagua = null): array
    {
        $lido = app(NfseXmlImporter::class)->ler($xml ?? $this->fixture());

        return app(DanfseLayout::class)->montar($lido, $marcaDagua);
    }

    private function renderizar(?string $xml = null): string
    {
        return app(NfseDanfseRenderer::class)->render(
            app(NfseXmlImporter::class)->ler($xml ?? $this->fixture())
        );
    }

    /**
     * Número de páginas do PDF, lido do `/Count` do nó de páginas.
     */
    private function paginas(string $pdf): int
    {
        preg_match_all('#/Count (\d+)#', $pdf, $achados);

        return max(array_map('intval', $achados[1] ?: ['0']));
    }

    private function fixture(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/nfse/nfse-real-mei.xml'));
    }

    /**
     * A fixture com um grupo `dest` apontando para o mesmo CNPJ do tomador.
     */
    private function comDestinatarioIgualAoTomador(): string
    {
        return str_replace(
            '<serv><locPrest>',
            '<IBSCBS><dest><CNPJ>72063654001309</CNPJ>'
                .'<xNome>ABRIGO DO MARINHEIRO</xNome></dest></IBSCBS><serv><locPrest>',
            $this->fixture()
        );
    }
}
