<?php

namespace Tests\Feature\Fiscal;

use App\Models\DocumentoFiscal;
use App\Services\Fiscal\AnexoXLayout;
use App\Services\Fiscal\AnexoXService;
use App\Services\Pdf\AnexoXRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * PDFs do Anexo X: o formulário oficial e a relação de documentos anexa.
 */
class AnexoXPdfTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    private string $competencia;

    private int $clienteId;

    private int $equipamentoId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->seedOrderCatalog();

        $this->clienteId = $this->createClientRecord();
        $this->equipamentoId = $this->createEquipmentRecord($this->clienteId);
        $this->competencia = now()->format('Y-m');

        foreach ([
            'empresa_razao_social' => 'Jovem Tech Assistência ME',
            'empresa_cnpj' => '11222333000181',
            'empresa_cidade' => 'Barra Mansa',
            'empresa_uf' => 'RJ',
        ] as $chave => $valor) {
            DB::table('configuracoes')->updateOrInsert(
                ['chave' => $chave],
                ['valor' => $valor, 'tipo' => 'texto', 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    private function osEntregue(float $pecas, float $maoObra): int
    {
        return $this->createOrderRecord([
            'cliente_id' => $this->clienteId,
            'equipamento_id' => $this->equipamentoId,
            'status' => 'entregue_reparado_pago',
            'data_conclusao' => now()->startOfMonth()->addDays(5),
            'data_entrega' => now()->startOfMonth()->addDays(6),
            'valor_pecas' => $pecas,
            'valor_mao_obra' => $maoObra,
            'valor_total' => $pecas + $maoObra,
            'desconto' => 0,
            'valor_final' => $pecas + $maoObra,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function layoutDoFormulario(): array
    {
        return app(AnexoXLayout::class)->montar(
            app(AnexoXService::class)->apurar($this->competencia)
        );
    }

    public function test_pdf_do_anexo_x_gera_bytes_de_pdf(): void
    {
        $this->osEntregue(200.00, 300.00);

        $pdf = app(AnexoXRenderer::class)->renderFormulario(
            app(AnexoXService::class)->apurar($this->competencia)
        );

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    /**
     * Tradução literal do requisito: o Anexo X é um padrão da Receita Federal e
     * não se modifica. Acumulado do ano, limite do MEI, lista de receita sem
     * documento e relação de notas são extras de TELA — nenhum deles pode
     * vazar para o formulário.
     */
    public function test_pdf_do_anexo_x_nao_contem_nenhum_dos_extras(): void
    {
        $this->osEntregue(200.00, 300.00);

        $html = view('pdf.anexo-x', ['anexo' => $this->layoutDoFormulario()])->render();

        foreach ([
            '81.000',
            'Limite',
            'limite',
            'Acumulado',
            'acumulado',
            'sem documento fiscal',
            'Relação de documentos',
            'Desenquadramento',
            'desenquadramento',
        ] as $proibido) {
            $this->assertStringNotContainsString(
                $proibido,
                $html,
                "O formulário do Anexo X não pode conter \"{$proibido}\" — é um padrão da Receita e não se modifica."
            );
        }
    }

    public function test_pdf_do_anexo_x_imprime_as_dez_linhas_do_formulario(): void
    {
        $this->osEntregue(200.00, 300.00);

        $html = view('pdf.anexo-x', ['anexo' => $this->layoutDoFormulario()])->render();

        foreach (['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'] as $numeral) {
            $this->assertStringContainsString('>'.$numeral.'</td>', $html, "Falta a linha {$numeral}.");
        }

        $this->assertStringContainsString('RELATÓRIO MENSAL DAS RECEITAS BRUTAS', $html);
        $this->assertStringContainsString('ASSINATURA DO EMPRESÁRIO', $html);
        $this->assertStringContainsString('ENCONTRAM-SE ANEXADOS A ESTE RELATÓRIO', $html);
        $this->assertStringContainsString('documentos fiscais comprobatórios das entradas', $html);
        $this->assertStringContainsString('notas fiscais relativas às operações', $html);
    }

    /**
     * A indústria não existe nesta base, mas é linha do formulário oficial.
     */
    public function test_pdf_do_anexo_x_imprime_as_linhas_de_industria_zeradas(): void
    {
        $this->osEntregue(200.00, 300.00);

        $anexo = $this->layoutDoFormulario();
        $industria = $anexo['blocos'][1];

        $this->assertStringContainsString('INDÚSTRIA', $industria['titulo']);

        foreach ($industria['itens'] as $item) {
            $this->assertSame('R$ 0,00', $item['valor']);
        }
    }

    /**
     * O regime muda o número impresso. Um relatório assinado sem dizer por qual
     * critério foi apurado não dá para conferir depois.
     */
    public function test_pdf_do_anexo_x_imprime_o_regime_usado_no_rodape(): void
    {
        $this->osEntregue(200.00, 300.00);
        $servico = app(AnexoXService::class);
        $layout = app(AnexoXLayout::class);

        $competencia = $layout->montar($servico->apurar($this->competencia, AnexoXService::REGIME_COMPETENCIA));
        $caixa = $layout->montar($servico->apurar($this->competencia, AnexoXService::REGIME_CAIXA));

        $this->assertStringContainsString('COMPETÊNCIA', $competencia['rodape'][0]);
        $this->assertStringContainsString('CAIXA', $caixa['rodape'][0]);
    }

    public function test_pdf_do_anexo_x_identifica_a_empresa_e_o_periodo(): void
    {
        $this->osEntregue(200.00, 300.00);

        $anexo = $this->layoutDoFormulario();

        $this->assertSame('Jovem Tech Assistência ME', $anexo['empreendedor']);
        $this->assertSame('11.222.333/0001-81', $anexo['cnpj']);
        $this->assertSame(now()->format('m/Y'), $anexo['periodo']);
        $this->assertStringContainsString('Barra Mansa/RJ', $anexo['local_e_data']);
    }

    // -------------------------------------------------------------- ano inteiro

    public function test_pdf_anual_gera_uma_folha_para_cada_mes(): void
    {
        $this->osEntregue(200.00, 300.00);

        $relatorios = app(AnexoXService::class)->relatorioAnual((int) now()->format('Y'));

        $this->assertCount(12, $relatorios, 'O bloco anual tem uma folha por mês do ano-calendário.');

        foreach ($relatorios as $indice => $relatorio) {
            $this->assertSame(
                sprintf('%04d-%02d', (int) now()->format('Y'), $indice + 1),
                $relatorio['competencia'],
                'Os meses saem em ordem, de janeiro a dezembro.'
            );
        }

        $pdf = app(AnexoXRenderer::class)->renderFormularioAnual($relatorios);
        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    /**
     * As duas folhas vêm do MESMO partial. Se alguém duplicar o Blade, a
     * primeira correção feita só de um lado produz uma folha divergente que
     * continua sendo entregue ao fisco como se fosse o formulário.
     */
    public function test_folha_do_bloco_anual_e_o_mesmo_formulario_do_pdf_mensal(): void
    {
        $this->osEntregue(200.00, 300.00);

        $relatorio = app(AnexoXService::class)->apurar($this->competencia);
        $anexo = app(AnexoXLayout::class)->montar($relatorio);

        $mensal = view('pdf.anexo-x', ['anexo' => $anexo])->render();
        $anual = view('pdf.anexo-x-anual', ['anexos' => [$anexo]])->render();

        $corpo = view('pdf.partials.anexo-x-formulario', ['anexo' => $anexo])->render();

        $this->assertStringContainsString(trim($corpo), $mensal);
        $this->assertStringContainsString(trim($corpo), $anual);
    }

    public function test_pdf_anual_nao_contem_nenhum_dos_extras(): void
    {
        $this->osEntregue(200.00, 300.00);

        $anexos = array_map(
            fn (array $r): array => app(AnexoXLayout::class)->montar($r),
            app(AnexoXService::class)->relatorioAnual((int) now()->format('Y'))
        );

        $html = view('pdf.anexo-x-anual', ['anexos' => $anexos])->render();

        foreach (['81.000', 'Limite', 'Acumulado', 'sem documento fiscal', 'Relação de documentos'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $html, "O bloco anual também é o formulário oficial.");
        }
    }

    /**
     * Declarar R$ 0,00 para um mês que ainda não aconteceu seria declaração
     * falsa. A folha é impressa — o bloco anual existe para ser conferido —,
     * mas avisada, para não ser assinada por engano.
     */
    public function test_folha_de_competencia_futura_avisa_para_nao_ser_assinada(): void
    {
        $futuro = now()->addMonthNoOverflow()->format('Y-m');

        $anexo = app(AnexoXLayout::class)->montar(
            app(AnexoXService::class)->apurar($futuro)
        );

        $this->assertNotEmpty(array_filter(
            $anexo['rodape'],
            fn (string $linha): bool => str_contains($linha, 'competência futura')
        ));

        $html = view('pdf.anexo-x', ['anexo' => $anexo])->render();
        $this->assertStringContainsString('não assine esta folha', $html);
    }

    public function test_folha_do_mes_corrente_avisa_que_o_mes_nao_terminou(): void
    {
        $anexo = app(AnexoXLayout::class)->montar(
            app(AnexoXService::class)->apurar(now()->format('Y-m'))
        );

        $this->assertNotEmpty(array_filter(
            $anexo['rodape'],
            fn (string $linha): bool => str_contains($linha, 'competência em curso')
        ));
    }

    /**
     * Mês passado e encerrado não recebe aviso nenhum: é a folha que se assina.
     */
    public function test_folha_de_mes_encerrado_nao_recebe_aviso(): void
    {
        $anexo = app(AnexoXLayout::class)->montar(
            app(AnexoXService::class)->apurar(now()->subMonthNoOverflow()->format('Y-m'))
        );

        foreach ($anexo['rodape'] as $linha) {
            $this->assertStringNotContainsString('ATENÇÃO', $linha);
        }
    }

    // -------------------------------------------------------------- ajuste

    /**
     * O formulário entregue ao fisco tem que trazer TODA a receita bruta — ou
     * seja, o declarado, não o apurado.
     */
    public function test_pdf_imprime_o_valor_declarado_e_nao_o_calculado(): void
    {
        $this->osEntregue(0.0, 210.00);

        app(\App\Services\Fiscal\AnexoXAjusteService::class)->lancar(
            $this->competencia,
            AnexoXService::REGIME_COMPETENCIA,
            'vii',
            90.00,
            'Serviço cobrado em dinheiro, fora do sistema',
            (int) $this->createUserRecord(['grupo_id' => 1])->id
        );

        $html = view('pdf.anexo-x', ['anexo' => $this->layoutDoFormulario()])->render();

        $this->assertStringContainsString('R$ 300,00', $html, 'Imprime o declarado.');
        $this->assertStringNotContainsString('R$ 210,00', $html, 'O apurado sozinho não vai ao papel.');
    }

    public function test_rodape_declara_a_existencia_do_ajuste_manual(): void
    {
        $this->osEntregue(0.0, 210.00);

        app(\App\Services\Fiscal\AnexoXAjusteService::class)->lancar(
            $this->competencia,
            AnexoXService::REGIME_COMPETENCIA,
            'vii',
            90.00,
            'Serviço cobrado em dinheiro, fora do sistema',
            (int) $this->createUserRecord(['grupo_id' => 1])->id
        );

        $anexo = $this->layoutDoFormulario();

        $this->assertNotEmpty(array_filter(
            $anexo['rodape'],
            fn (string $linha): bool => str_contains($linha, 'ajuste manual declarado')
        ));

        $html = view('pdf.anexo-x', ['anexo' => $anexo])->render();
        $this->assertStringContainsString('R$ 90,00 em ajuste manual declarado (1 lançamento)', $html);
    }

    public function test_rodape_nao_menciona_ajuste_quando_nao_ha_nenhum(): void
    {
        $this->osEntregue(0.0, 210.00);

        foreach ($this->layoutDoFormulario()['rodape'] as $linha) {
            $this->assertStringNotContainsString('ajuste manual', $linha);
        }
    }

    // -------------------------------------------------- relação de documentos

    public function test_pdf_da_relacao_de_documentos_lista_canceladas_identificadas(): void
    {
        $osId = $this->osEntregue(0.0, 300.00);

        DocumentoFiscal::query()->create([
            'tipo' => DocumentoFiscal::TIPO_NFSE,
            'status' => DocumentoFiscal::STATUS_EMITIDO,
            'os_id' => $osId,
            'valor_servicos' => 300.00,
            'valor_total' => 300.00,
            'numero' => '35',
            'serie' => '1',
            'tomador_nome' => 'Fulano de Tal',
            'emitido_em' => now()->startOfMonth()->addDays(6),
        ]);

        DocumentoFiscal::query()->create([
            'tipo' => DocumentoFiscal::TIPO_NFSE,
            'status' => DocumentoFiscal::STATUS_CANCELADO,
            'os_id' => $osId,
            'valor_servicos' => 80.00,
            'valor_total' => 80.00,
            'numero' => '36',
            'serie' => '1',
            'tomador_nome' => 'Ciclano',
            'emitido_em' => now()->startOfMonth()->addDays(7),
            'cancelado_em' => now()->startOfMonth()->addDays(8),
        ]);

        $dados = app(AnexoXService::class)->documentosEmitidosNoMes($this->competencia);
        $relacao = app(AnexoXLayout::class)->montarRelacaoDeDocumentos($dados);

        $this->assertCount(2, $relacao['documentos'], 'A cancelada continua na relação: buraco na numeração chama atenção do fisco.');
        $this->assertSame('CANCELADA', $relacao['documentos'][1]['situacao']);
        $this->assertTrue($relacao['documentos'][1]['cancelado']);

        $this->assertSame('R$ 300,00', $relacao['totais']['geral'], 'A cancelada não soma no total emitido.');
        $this->assertSame(1, $relacao['totais']['quantidade_canceladas']);

        $html = view('pdf.anexo-x-documentos', ['relacao' => $relacao])->render();
        $this->assertStringContainsString('CANCELADA', $html);
        $this->assertStringContainsString('Fulano de Tal', $html);
    }

    public function test_pdf_da_relacao_e_um_arquivo_separado_do_anexo_x(): void
    {
        $this->osEntregue(0.0, 300.00);

        $renderer = app(AnexoXRenderer::class);
        $servico = app(AnexoXService::class);

        $formulario = $renderer->renderFormulario($servico->apurar($this->competencia));
        $relacao = $renderer->renderRelacaoDocumentos($servico->documentosEmitidosNoMes($this->competencia));

        $this->assertStringStartsWith('%PDF-', $formulario);
        $this->assertStringStartsWith('%PDF-', $relacao);
        $this->assertNotSame($formulario, $relacao, 'São dois documentos, não um.');

        $htmlFormulario = view('pdf.anexo-x', ['anexo' => $this->layoutDoFormulario()])->render();
        $this->assertStringNotContainsString(
            'RELAÇÃO DE DOCUMENTOS FISCAIS EMITIDOS',
            $htmlFormulario,
            'A relação é anexa ao formulário, nunca embutida nele.'
        );
    }

    public function test_relacao_vazia_diz_que_nao_houve_emissao(): void
    {
        $relacao = app(AnexoXLayout::class)->montarRelacaoDeDocumentos(
            app(AnexoXService::class)->documentosEmitidosNoMes($this->competencia)
        );

        $this->assertTrue($relacao['vazio']);

        $html = view('pdf.anexo-x-documentos', ['relacao' => $relacao])->render();
        $this->assertStringContainsString('Nenhum documento fiscal emitido no período', $html);
    }
}
