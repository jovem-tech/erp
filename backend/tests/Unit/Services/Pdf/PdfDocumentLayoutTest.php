<?php

namespace Tests\Unit\Services\Pdf;

use App\Services\Pdf\PdfSchemaValidator;
use App\Services\Pdf\PdfTemplateRenderer;
use App\Services\Pdf\PdfVariableResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Integridade de leitura do documento: cabeçalho de seção nunca se separa do
 * conteúdo que ele anuncia, e o botão de ação só vira link quando o destino é
 * um endereço http(s) de verdade.
 *
 * São regras do MOTOR, não de um modelo específico: valem para os documentos
 * de fábrica e para qualquer modelo criado no editor.
 */
class PdfDocumentLayoutTest extends TestCase
{
    private function renderer(): PdfTemplateRenderer
    {
        return new PdfTemplateRenderer(new PdfVariableResolver);
    }

    /**
     * @param  array<int, array<string, mixed>>  $corpo
     * @param  array<string, mixed>  $context
     */
    private function renderBody(array $corpo, array $context = [], string $formato = 'a4'): string
    {
        $descriptor = [
            'variables' => [
                'orcamento.link_aprovacao' => 'string',
                'orcamento.validade_link' => 'data',
                'cliente.nome' => 'string',
            ],
            'collections' => ['itens' => ['descricao' => 'string', 'valor_total' => 'moeda']],
        ];

        $schema = ['pagina' => [], 'cabecalho' => [], 'corpo' => $corpo, 'rodape' => []];

        $html = $this->renderer()->render($schema, $context, $descriptor, $formato);

        // O <style> do documento cita as mesmas classes que estamos avaliando:
        // sem removê-lo, qualquer asserção sobre marcação contaria o CSS.
        return (string) preg_replace('/<style>.*?<\/style>/s', '', $html);
    }

    public function test_section_header_is_glued_to_the_block_that_follows_it(): void
    {
        $html = $this->renderBody([
            ['tipo' => 'cabecalho_secao', 'texto' => 'GARANTIA'],
            ['tipo' => 'paragrafo', 'texto' => 'Texto da garantia.'],
        ]);

        // Um único grupo indivisível contendo cabeçalho + corpo.
        $this->assertSame(1, substr_count($html, 'pdfe-keep'));
        $this->assertMatchesRegularExpression(
            '/<div class="pdfe-keep">.*GARANTIA.*Texto da garantia\..*<\/div>/s',
            $html
        );
    }

    public function test_consecutive_headers_travel_together_until_real_content(): void
    {
        $html = $this->renderBody([
            ['tipo' => 'titulo', 'texto' => 'TITULO'],
            ['tipo' => 'cabecalho_secao', 'texto' => 'SUBSECAO'],
            ['tipo' => 'paragrafo', 'texto' => 'Conteudo final.'],
        ]);

        // Título + seção + parágrafo num grupo só: nenhum cabeçalho sobra.
        $this->assertSame(1, substr_count($html, 'pdfe-keep'));
        $this->assertMatchesRegularExpression(
            '/<div class="pdfe-keep">.*TITULO.*SUBSECAO.*Conteudo final\..*<\/div>/s',
            $html
        );
    }

    public function test_header_skips_blocks_invisible_in_the_current_format(): void
    {
        // O parágrafo só existe no A4; no cupom o cabeçalho tem de se prender
        // ao bloco seguinte que realmente aparece.
        $html = $this->renderBody([
            ['tipo' => 'cabecalho_secao', 'texto' => 'SECAO'],
            ['tipo' => 'paragrafo', 'texto' => 'So no A4.', 'visivel_em' => ['a4']],
            ['tipo' => 'paragrafo', 'texto' => 'Aparece nos dois.'],
        ], [], '80mm');

        $this->assertStringNotContainsString('So no A4.', $html);
        $this->assertMatchesRegularExpression(
            '/<div class="pdfe-keep">.*SECAO.*Aparece nos dois\..*<\/div>/s',
            $html
        );
    }

    public function test_whole_section_stays_together_not_just_the_first_block(): void
    {
        // Caso real: "Condições de pagamento" saía com uma linha numa página e
        // o parcelamento + a tabela de chave Pix na seguinte.
        $html = $this->renderBody([
            ['tipo' => 'cabecalho_secao', 'texto' => 'CONDICOES'],
            ['tipo' => 'campo', 'rotulo' => 'Formas', 'valor' => 'Pix'],
            // Condicional SEM cabeçalho é conteúdo desta mesma seção.
            ['tipo' => 'condicional', 'se' => ['variavel' => 'cliente.nome', 'operador' => 'preenchido'], 'blocos' => [
                ['tipo' => 'paragrafo', 'texto' => 'Parcelamento em 7x.'],
            ]],
        ], ['cliente' => ['nome' => 'Fulano']]);

        $this->assertSame(1, substr_count($html, 'pdfe-keep'));
        $this->assertMatchesRegularExpression(
            '/<div class="pdfe-keep">.*CONDICOES.*Formas.*Parcelamento em 7x\..*<\/div>/s',
            $html
        );
    }

    public function test_a_block_carrying_its_own_heading_starts_a_new_section(): void
    {
        // Sem essa fronteira, "Itens do orçamento" absorvia as seções seguintes
        // (que são condicionais com cabeçalho dentro) e empurrava o documento
        // inteiro para a página seguinte.
        $html = $this->renderBody([
            ['tipo' => 'cabecalho_secao', 'texto' => 'ITENS'],
            ['tipo' => 'paragrafo', 'texto' => 'Conteudo dos itens.'],
            ['tipo' => 'condicional', 'se' => ['variavel' => 'cliente.nome', 'operador' => 'preenchido'], 'blocos' => [
                ['tipo' => 'cabecalho_secao', 'texto' => 'GARANTIA'],
                ['tipo' => 'paragrafo', 'texto' => 'Texto da garantia.'],
            ]],
        ], ['cliente' => ['nome' => 'Fulano']]);

        // Dois grupos independentes: um por seção.
        $this->assertSame(2, substr_count($html, 'pdfe-keep'));
        $this->assertMatchesRegularExpression('/<div class="pdfe-keep">(?:(?!pdfe-keep).)*ITENS.*?Conteudo dos itens\./s', $html);
        $this->assertMatchesRegularExpression('/GARANTIA.*Texto da garantia\./s', $html);
    }

    public function test_short_table_stays_whole_and_long_table_may_split(): void
    {
        $bloco = ['tipo' => 'tabela', 'fonte' => 'itens', 'colunas' => [
            ['campo' => 'descricao', 'rotulo' => 'Descricao'],
        ]];

        $curta = $this->renderBody([$bloco], ['itens' => array_fill(0, 3, ['descricao' => 'x'])]);
        $longa = $this->renderBody([$bloco], ['itens' => array_fill(0, 40, ['descricao' => 'x'])]);

        // Tabela curta cabe numa página: indivisível, para o cabeçalho da
        // tabela não ficar sozinho no pé.
        $this->assertStringContainsString('pdfe-tabela pdfe-keep', $curta);
        // Tabela longa precisa quebrar: forçá-la inteira só empurraria o
        // problema para a página seguinte.
        $this->assertStringNotContainsString('pdfe-keep', $longa);
    }

    public function test_link_button_renders_a_clickable_anchor_with_caption(): void
    {
        $html = $this->renderBody([
            [
                'tipo' => 'botao_link',
                'texto' => 'Aprovar orçamento',
                'variavel' => 'orcamento.link_aprovacao',
                'legenda' => 'Válido até {{ orcamento.validade_link | data }}.',
            ],
        ], [
            'orcamento' => [
                'link_aprovacao' => 'https://erp.example.com/orcamento/abc123',
                'validade_link' => '2026-08-21',
            ],
        ]);

        $this->assertStringContainsString('<a class="pdfe-botao" href="https://erp.example.com/orcamento/abc123">', $html);
        $this->assertStringContainsString('Aprovar orçamento', $html);
        $this->assertStringContainsString('Válido até 21/08/2026.', $html);
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function unsafeUrlProvider(): array
    {
        return [
            ['javascript:alert(1)'],
            ['file:///etc/passwd'],
            ['data:text/html;base64,PHNjcmlwdD4='],
            ['https://exemplo.com/a b'],
            ['nao-e-url'],
        ];
    }

    /**
     * Um modelo não pode transformar o botão em vetor de execução apontando a
     * variável para qualquer coisa: só http(s) vira clique.
     */
    #[DataProvider('unsafeUrlProvider')]
    public function test_link_button_refuses_destinations_that_are_not_http(string $url): void
    {
        $html = $this->renderBody([
            [
                'tipo' => 'botao_link',
                'texto' => 'Abrir',
                'variavel' => 'orcamento.link_aprovacao',
            ],
        ], ['orcamento' => ['link_aprovacao' => $url]]);

        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('pdfe-botao-inativo', $html);
    }

    public function test_link_button_prints_the_address_on_thermal_paper(): void
    {
        $bloco = [
            'tipo' => 'botao_link',
            'texto' => 'Aprovar',
            'variavel' => 'orcamento.link_aprovacao',
        ];
        $context = ['orcamento' => ['link_aprovacao' => 'https://erp.example.com/x']];

        // Papel térmico não clica: o endereço precisa sair legível.
        $this->assertStringContainsString(
            'https://erp.example.com/x</div>',
            $this->renderBody([$bloco], $context, '80mm')
        );
        // No A4 o link está no próprio botão, sem poluir o documento.
        $this->assertStringNotContainsString(
            'pdfe-botao-url',
            $this->renderBody([$bloco], $context)
        );
    }

    public function test_validator_rejects_a_button_without_a_known_destination(): void
    {
        $validator = new PdfSchemaValidator(new PdfVariableResolver);
        $descriptor = ['variables' => ['orcamento.link_aprovacao' => 'string'], 'collections' => []];

        $semVariavel = $validator->validate([
            'pagina' => [], 'cabecalho' => [], 'rodape' => [],
            'corpo' => [['tipo' => 'botao_link', 'texto' => 'Abrir']],
        ], $descriptor);

        $variavelDesconhecida = $validator->validate([
            'pagina' => [], 'cabecalho' => [], 'rodape' => [],
            'corpo' => [['tipo' => 'botao_link', 'texto' => 'Abrir', 'variavel' => 'nao.existe']],
        ], $descriptor);

        $semTexto = $validator->validate([
            'pagina' => [], 'cabecalho' => [], 'rodape' => [],
            'corpo' => [['tipo' => 'botao_link', 'variavel' => 'orcamento.link_aprovacao']],
        ], $descriptor);

        $valido = $validator->validate([
            'pagina' => [], 'cabecalho' => [], 'rodape' => [],
            'corpo' => [['tipo' => 'botao_link', 'texto' => 'Abrir', 'variavel' => 'orcamento.link_aprovacao']],
        ], $descriptor);

        $this->assertNotEmpty($semVariavel);
        $this->assertNotEmpty($variavelDesconhecida);
        $this->assertNotEmpty($semTexto);
        $this->assertSame([], $valido);
    }
}
