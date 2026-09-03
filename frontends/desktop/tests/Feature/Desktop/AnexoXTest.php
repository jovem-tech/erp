<?php

namespace Tests\Feature\Desktop;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tela do Anexo X — a grade do ano, o gráfico e os modais da linha.
 */
class AnexoXTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function linhas(float $comercioSem = 180.0, float $comercioCom = 120.0, float $servicoSem = 450.0, float $servicoCom = 250.0): array
    {
        $monta = static fn (float $valor, bool $calculada = false): array => [
            'valor' => $valor, 'calculado' => $valor, 'ajuste' => 0.0,
            'ajustavel' => ! $calculada, 'calculada' => $calculada,
        ];

        return [
            'i' => $monta($comercioSem), 'ii' => $monta($comercioCom),
            'iii' => $monta($comercioSem + $comercioCom, true),
            'iv' => $monta(0.0), 'v' => $monta(0.0), 'vi' => $monta(0.0, true),
            'vii' => $monta($servicoSem), 'viii' => $monta($servicoCom),
            'ix' => $monta($servicoSem + $servicoCom, true),
            'x' => $monta($comercioSem + $comercioCom + $servicoSem + $servicoCom, true),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function regimeVazio(array $overrides = []): array
    {
        return array_merge([
            'linhas' => $this->linhas(0, 0, 0, 0),
            'total' => 0.0, 'comercio' => 0.0, 'industria' => 0.0, 'servicos' => 0.0,
            'com_documento' => 0.0, 'sem_documento' => 0.0,
            'ajuste_total' => 0.0, 'ajuste_quantidade' => 0,
            'deducoes' => ['descontos' => 0.0, 'devolucoes' => 0.0],
            'origem_dos_valores' => 'ao_vivo', 'fechamento' => null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fakeResumo(array $overrides = []): array
    {
        $meses = [];

        for ($mes = 1; $mes <= 12; $mes++) {
            $comMovimento = $mes === 9;

            $competencia = $comMovimento
                ? $this->regimeVazio([
                    'linhas' => $this->linhas(),
                    'total' => 1000.00, 'comercio' => 300.00, 'servicos' => 700.00,
                    'com_documento' => 370.00, 'sem_documento' => 630.00,
                ])
                : $this->regimeVazio();

            $meses[] = [
                'competencia' => sprintf('2026-%02d', $mes),
                'mes' => $mes,
                'mes_label' => ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'][$mes - 1],
                'periodo_label' => sprintf('%02d/2026', $mes),
                'futuro' => $mes > 9,
                'em_curso' => $mes === 9,
                'regimes' => ['competencia' => $competencia, 'caixa' => $this->regimeVazio()],
            ];
        }

        $serie = array_fill(0, 12, 0.0);
        $serie[8] = 1000.00;

        return array_merge([
            'ano' => 2026,
            'regime_tributario' => 'mei',
            'aviso_regime_tributario' => null,
            'regime_que_conta_para_o_limite' => 'competencia',
            'empresa' => ['razao_social' => 'Jovem Tech ME', 'cnpj' => '11.222.333/0001-81', 'cidade' => 'Barra Mansa', 'uf' => 'RJ', 'data_abertura' => ''],
            'rotulos' => [
                'i' => 'Revenda de mercadorias com dispensa de emissão de documento fiscal',
                'ii' => 'Revenda de mercadorias com documento fiscal emitido',
                'iii' => 'Total das receitas com revenda de mercadorias (I + II)',
                'iv' => 'Venda de produtos industrializados com dispensa de emissão de documento fiscal',
                'v' => 'Venda de produtos industrializados com documento fiscal emitido',
                'vi' => 'Total das receitas com venda de produtos industrializados (IV + V)',
                'vii' => 'Receita com prestação de serviços com dispensa de emissão de documento fiscal',
                'viii' => 'Receita com prestação de serviços com documento fiscal emitido',
                'ix' => 'Total das receitas com prestação de serviços (VII + VIII)',
                'x' => 'Total geral das receitas brutas no mês (III + VI + IX)',
            ],
            'mostrar_industria' => false,
            'meses' => $meses,
            'acumulado' => [
                'competencia' => [
                    'ano' => 2026, 'meses_considerados' => 9, 'acumulado' => 45000.00, 'por_mes' => [],
                    'meses_fechados' => [], 'limite' => 81000.00, 'limite_proporcional' => false,
                    'meses_de_atividade' => 12, 'limite_excesso_20' => 97200.00,
                    'percentual_do_limite' => 55.6, 'restante' => 36000.00, 'faixa' => 'dentro', 'mensagem' => null,
                ],
                'caixa' => null,
            ],
            'totais' => [
                'competencia' => ['total' => 1000.00, 'comercio' => 300.00, 'industria' => 0.0, 'servicos' => 700.00, 'com_documento' => 370.00, 'sem_documento' => 630.00, 'ajuste_total' => 0.0],
                'caixa' => ['total' => 0.0, 'comercio' => 0.0, 'industria' => 0.0, 'servicos' => 0.0, 'com_documento' => 0.0, 'sem_documento' => 0.0, 'ajuste_total' => 0.0],
            ],
            'grafico' => [
                'year' => 2026,
                'labels' => ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'],
                'mes_atual' => 9, 'ano_corrente' => 2026,
                'regimes' => [
                    'competencia' => ['bruto' => $serie, 'com_documento' => $serie, 'sem_documento' => $serie, 'ajuste' => array_fill(0, 12, 0.0)],
                    'caixa' => ['bruto' => array_fill(0, 12, 0.0), 'com_documento' => array_fill(0, 12, 0.0), 'sem_documento' => array_fill(0, 12, 0.0), 'ajuste' => array_fill(0, 12, 0.0)],
                ],
                'limite' => ['anual' => 81000.00, 'aplicado' => 81000.00, 'mensal_medio' => 6750.00, 'proporcional' => false, 'meses_de_atividade' => 12],
                'legend' => [
                    ['key' => 'competencia', 'label' => 'Competência', 'color' => '#6f5afc', 'type' => 'bar'],
                    ['key' => 'caixa', 'label' => 'Caixa', 'color' => '#0ea5e9', 'type' => 'bar'],
                    ['key' => 'limite_mensal', 'label' => 'Média mensal do limite', 'color' => '#f59e0b', 'type' => 'dashed'],
                ],
            ],
            'gerado_em' => '2026-09-03T10:00:00-03:00',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function fakeApi(array $overrides = []): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/fiscal/anexo-x/resumo*' => Http::response([
                'status' => 'success',
                'data' => ['resumo' => $this->fakeResumo($overrides)],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);
    }

    // ------------------------------------------------------------- a tabela

    public function test_tabela_do_ano_renderiza_os_doze_meses(): void
    {
        $this->fakeApi();

        $resposta = $this
            ->withSession($this->desktopSession(['fiscal' => ['visualizar']]))
            ->get('/fiscal/anexo-x?ano=2026');

        $resposta->assertOk()->assertSee('Anexo X');

        foreach (['Janeiro', 'Junho', 'Setembro', 'Dezembro'] as $mes) {
            $resposta->assertSee($mes);
        }

        $resposta->assertSee('data-anexo-x-linha="2026-12"', false);
    }

    public function test_tabela_mostra_as_colunas_de_atividade_e_documento(): void
    {
        $this->fakeApi();

        $this->withSession($this->desktopSession(['fiscal' => ['visualizar']]))
            ->get('/fiscal/anexo-x?ano=2026')
            ->assertOk()
            ->assertSee('Total (X)')
            ->assertSee('Comércio (III)')
            ->assertSee('Serviços (IX)')
            ->assertSee('Com documento')
            ->assertSee('Sem documento')
            ->assertSee('R$ 1.000,00')
            ->assertSee('R$ 370,00')
            ->assertSee('R$ 630,00');
    }

    /**
     * A coluna existe no formulário, mas só polui a tabela quando é sempre
     * zero — que é o caso de uma assistência técnica.
     */
    public function test_coluna_de_industria_nao_aparece_quando_todo_vi_e_zero(): void
    {
        $this->fakeApi();

        $this->withSession($this->desktopSession(['fiscal' => ['visualizar']]))
            ->get('/fiscal/anexo-x?ano=2026')
            ->assertOk()
            ->assertDontSee('Indústria (VI)');
    }

    public function test_coluna_de_industria_aparece_quando_algum_mes_tem_valor(): void
    {
        $this->fakeApi(['mostrar_industria' => true]);

        $this->withSession($this->desktopSession(['fiscal' => ['visualizar']]))
            ->get('/fiscal/anexo-x?ano=2026')
            ->assertOk()
            ->assertSee('Indústria (VI)');
    }

    public function test_tabela_tem_totais_do_ano_no_rodape(): void
    {
        $this->fakeApi();

        $this->withSession($this->desktopSession(['fiscal' => ['visualizar']]))
            ->get('/fiscal/anexo-x?ano=2026')
            ->assertOk()
            ->assertSee('Total do ano')
            ->assertSee('data-anexo-x-total="total"', false);
    }

    public function test_meses_futuros_ficam_marcados(): void
    {
        $this->fakeApi();

        $this->withSession($this->desktopSession(['fiscal' => ['visualizar']]))
            ->get('/fiscal/anexo-x?ano=2026')
            ->assertOk()
            ->assertSee('anexo-x-linha-futura', false)
            ->assertSee('Futuro');
    }

    // ------------------------------------------------------------ o regime

    public function test_alternador_de_regime_diz_que_competencia_conta_para_o_limite(): void
    {
        $this->fakeApi();

        $this->withSession($this->desktopSession(['fiscal' => ['visualizar']]))
            ->get('/fiscal/anexo-x?ano=2026')
            ->assertOk()
            ->assertSee('data-anexo-x-regime="competencia"', false)
            ->assertSee('data-anexo-x-regime="caixa"', false)
            ->assertSee('conta para o limite do MEI', false)
            ->assertSee('receita bruta auferida no ano-calendário', false);
    }

    public function test_card_do_acumulado_fica_acima_da_tabela(): void
    {
        $this->fakeApi();

        $resposta = $this
            ->withSession($this->desktopSession(['fiscal' => ['visualizar']]))
            ->get('/fiscal/anexo-x?ano=2026');

        $resposta->assertOk()->assertSee('Acumulado de 2026')->assertSee('R$ 81.000,00');

        $html = $resposta->getContent();
        $this->assertLessThan(
            strpos($html, 'data-anexo-x-tabela'),
            strpos($html, 'Acumulado de 2026'),
            'O acumulado vem antes da tabela.'
        );
    }

    public function test_acumulado_some_quando_o_regime_nao_e_mei(): void
    {
        $this->fakeApi([
            'regime_tributario' => 'simples',
            'aviso_regime_tributario' => 'Sua empresa está configurada como Simples Nacional.',
            'acumulado' => ['competencia' => null, 'caixa' => null],
        ]);

        $this->withSession($this->desktopSession(['fiscal' => ['visualizar']]))
            ->get('/fiscal/anexo-x?ano=2026')
            ->assertOk()
            ->assertSee('Sua empresa está configurada como Simples Nacional.')
            ->assertSee('exclusivo do MEI e não se aplica', false)
            ->assertDontSee('Acumulado de 2026');
    }

    // ------------------------------------------------------------ o gráfico

    public function test_grafico_recebe_os_dois_regimes_no_bootstrap(): void
    {
        $this->fakeApi();

        $resposta = $this
            ->withSession($this->desktopSession(['fiscal' => ['visualizar']]))
            ->get('/fiscal/anexo-x?ano=2026');

        $resposta->assertOk()
            ->assertSee('data-anexo-x-chart', false)
            ->assertSee('__DESKTOP_ANEXO_X', false)
            ->assertSee('assets/js/anexo-x-chart.js', false)
            ->assertSee('chartjs/chart.umd.min.js', false);
    }

    // -------------------------------------------------------------- ações

    public function test_dropdown_da_linha_tem_as_cinco_acoes(): void
    {
        $this->fakeApi();

        $this->withSession($this->desktopSession(['fiscal' => ['visualizar', 'editar', 'encerrar']]))
            ->get('/fiscal/anexo-x?ano=2026')
            ->assertOk()
            ->assertSee('Receitas brutas do mês')
            ->assertSee('Ver no padrão da Receita Federal')
            ->assertSee('Editar o relatório')
            ->assertSee('Imprimir o PDF do mês')
            ->assertSee('Todas as operações do mês')
            ->assertSee('Encerrar competência');
    }

    public function test_editar_o_relatorio_some_sem_permissao_de_editar(): void
    {
        $this->fakeApi();

        $this->withSession($this->desktopSession(['fiscal' => ['visualizar']]))
            ->get('/fiscal/anexo-x?ano=2026')
            ->assertOk()
            ->assertSee('Receitas brutas do mês')
            ->assertDontSee('Editar o relatório');
    }

    public function test_encerrar_some_sem_permissao_de_encerrar(): void
    {
        $this->fakeApi();

        $this->withSession($this->desktopSession(['fiscal' => ['visualizar']]))
            ->get('/fiscal/anexo-x?ano=2026')
            ->assertOk()
            ->assertDontSee('Encerrar competência')
            ->assertDontSee('Reabrir competência');
    }

    public function test_acoes_globais_ficam_num_menu_mais_acoes(): void
    {
        $this->fakeApi();

        $this->withSession($this->desktopSession(['fiscal' => ['visualizar']]))
            ->get('/fiscal/anexo-x?ano=2026')
            ->assertOk()
            ->assertSee('Mais ações')
            ->assertSee('Baixar Anexo X (PDF)')
            ->assertSee('Relação de documentos emitidos')
            ->assertSee('Ajuda');
    }

    // -------------------------------------------------------------- modais

    public function test_modais_sao_renderizados_uma_vez_so(): void
    {
        $this->fakeApi();

        $html = $this
            ->withSession($this->desktopSession(['fiscal' => ['visualizar', 'editar', 'encerrar']]))
            ->get('/fiscal/anexo-x?ano=2026')
            ->assertOk()
            ->getContent();

        foreach (['modalReceitasDoMes', 'modalFormularioReceita', 'modalEditarRelatorio', 'modalOperacoesDoMes', 'modalReabrirAnexoX'] as $modal) {
            $this->assertSame(
                1,
                substr_count($html, 'id="'.$modal.'"'),
                "O modal {$modal} não pode ser renderizado uma vez por mês."
            );
        }
    }

    /**
     * O `src` do iframe fica vazio na carga: doze PDFs renderizando na abertura
     * da página seria inaceitável.
     */
    public function test_modal_do_padrao_da_receita_nasce_sem_iframe_carregado(): void
    {
        $this->fakeApi();

        $this->withSession($this->desktopSession(['fiscal' => ['visualizar']]))
            ->get('/fiscal/anexo-x?ano=2026')
            ->assertOk()
            ->assertSee('data-formulario-iframe', false)
            ->assertDontSee('<iframe data-formulario-iframe src=', false);
    }

    public function test_modal_de_operacoes_explica_que_os_filtros_nao_sao_particao(): void
    {
        $this->fakeApi();

        $this->withSession($this->desktopSession(['fiscal' => ['visualizar']]))
            ->get('/fiscal/anexo-x?ano=2026')
            ->assertOk()
            ->assertSee('Com documento fiscal')
            ->assertSee('Sem documento fiscal')
            ->assertSee('não uma divisão do total', false);
    }

    public function test_modal_de_download_oferece_um_mes_ou_o_ano_inteiro(): void
    {
        $this->fakeApi();

        $resposta = $this
            ->withSession($this->desktopSession(['fiscal' => ['visualizar']]))
            ->get('/fiscal/anexo-x?ano=2026');

        $resposta->assertOk()
            ->assertSee('modalBaixarAnexoX')
            ->assertSee('Uma folha para cada mês, de janeiro a dezembro.', false)
            ->assertSee('data-anexo-x-campo="ano"', false);

        $html = $resposta->getContent();
        $campoAno = substr($html, strpos($html, 'data-anexo-x-campo="ano"') - 260, 300);
        $this->assertStringContainsString('disabled', $campoAno, 'O campo do ano nasce desabilitado.');
    }

    // --------------------------------------------------------- rotas JSON

    public function test_rota_json_de_operacoes_devolve_o_drill_down(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/fiscal/anexo-x?*' => Http::response([
                'status' => 'success',
                'data' => ['anexo_x' => [
                    'periodo_label' => '09/2026',
                    'linhas' => $this->linhas(),
                    'deducoes' => ['descontos' => 0.0, 'devolucoes' => 0.0],
                    'origens' => [],
                    'drill_down' => [[
                        'tipo' => 'os', 'id' => 1, 'referencia' => 'OS-2026-000481', 'data' => '2026-09-14',
                        'cliente_nome' => 'Empresa Cliente LTDA', 'tomador_pj' => true,
                        'liquido' => 340.00,
                        'comercio' => ['total' => 0.0, 'com_documento' => 0.0, 'sem_documento' => 0.0],
                        'industria' => ['total' => 0.0, 'com_documento' => 0.0, 'sem_documento' => 0.0],
                        'servicos' => ['total' => 340.00, 'com_documento' => 0.0, 'sem_documento' => 340.00],
                        'sem_documento_total' => 340.00, 'documentos' => [], 'alertas' => ['tomador_pj_sem_documento'],
                    ]],
                    'sem_documento' => ['total' => 340.00, 'quantidade' => 1],
                    'ajustes' => ['total' => 0.0, 'quantidade' => 0, 'bloqueado' => false, 'por_linha' => []],
                    'fechamento' => null,
                ]],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $this->withSession($this->desktopSession(['fiscal' => ['visualizar']]))
            ->getJson('/fiscal/anexo-x/operacoes?competencia=2026-09&regime=competencia')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('competencia', '2026-09')
            ->assertJsonCount(1, 'drill_down')
            ->assertJsonPath('drill_down.0.referencia', 'OS-2026-000481');
    }

    public function test_rota_json_de_ajustes_devolve_as_linhas_ajustaveis(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/fiscal/anexo-x/ajustes*' => Http::response([
                'status' => 'success',
                'data' => [
                    'ajustes' => ['total' => 0.0, 'quantidade' => 0, 'bloqueado' => false, 'por_linha' => []],
                    'linhas_ajustaveis' => ['i', 'ii', 'iv', 'v', 'vii', 'viii'],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $this->withSession($this->desktopSession(['fiscal' => ['visualizar', 'editar']]))
            ->getJson('/fiscal/anexo-x/ajustes?competencia=2026-09&regime=competencia')
            ->assertOk()
            ->assertJsonPath('pode_editar', true)
            ->assertJsonCount(6, 'linhas_ajustaveis')
            ->assertJsonPath('ajustes.bloqueado', false);
    }

    public function test_lancar_ajuste_repassa_para_a_api(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/fiscal/anexo-x/ajustes' => Http::response([
                'status' => 'success',
                'data' => ['ajustes' => ['total' => 90.0, 'quantidade' => 1, 'bloqueado' => false, 'por_linha' => []], 'linhas' => []],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $this->withSession($this->desktopSession(['fiscal' => ['visualizar', 'editar']]))
            ->postJson('/fiscal/anexo-x/ajustes', [
                'competencia' => '2026-09',
                'regime' => 'competencia',
                'linha' => 'vii',
                'valor' => 90.00,
                'motivo' => 'Serviço cobrado em dinheiro, fora do sistema',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/fiscal/anexo-x/ajustes')
            && $request->method() === 'POST'
            && $request['linha'] === 'vii');
    }

    /**
     * Corrigir o relatório e apenas vê-lo são poderes diferentes.
     */
    public function test_rota_de_ajuste_bloqueada_sem_permissao_de_editar(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
        ]);

        $this->withSession($this->desktopSession(['fiscal' => ['visualizar']]))
            ->postJson('/fiscal/anexo-x/ajustes', [
                'competencia' => '2026-09',
                'regime' => 'competencia',
                'linha' => 'vii',
                'valor' => 90.00,
                'motivo' => 'Tentativa sem permissão de editar',
            ])
            ->assertRedirect();
    }

    // ------------------------------------------------------------ PDFs e ajuda

    public function test_download_do_pdf_do_mes_repassa_os_bytes(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/fiscal/anexo-x/pdf*' => Http::response('%PDF-1.4 formulario', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $resposta = $this
            ->withSession($this->desktopSession(['fiscal' => ['visualizar']]))
            ->get('/fiscal/anexo-x/pdf?competencia=2026-09');

        $resposta->assertOk();
        $this->assertStringContainsString('anexo-x-2026-09.pdf', (string) $resposta->headers->get('content-disposition'));
        $this->assertSame('%PDF-1.4 formulario', $resposta->getContent());
    }

    public function test_download_do_ano_inteiro_pede_o_bloco_anual(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/fiscal/anexo-x/pdf*' => Http::response('%PDF-1.4 anual', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $resposta = $this
            ->withSession($this->desktopSession(['fiscal' => ['visualizar']]))
            ->get('/fiscal/anexo-x/pdf?ano=2026&regime=competencia');

        $resposta->assertOk();
        $this->assertStringContainsString('anexo-x-2026.pdf', (string) $resposta->headers->get('content-disposition'));

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'ano=2026')
            && ! str_contains($request->url(), 'competencia='));
    }

    public function test_download_da_relacao_de_documentos_e_uma_rota_propria(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/fiscal/anexo-x/documentos/pdf*' => Http::response('%PDF-1.4 relacao', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $resposta = $this
            ->withSession($this->desktopSession(['fiscal' => ['visualizar']]))
            ->get('/fiscal/anexo-x/documentos/pdf?competencia=2026-09');

        $resposta->assertOk();
        $this->assertStringContainsString('anexo-x-documentos-2026-09.pdf', (string) $resposta->headers->get('content-disposition'));
    }

    public function test_pagina_de_ajuda_explica_o_ajuste_e_o_regime_do_limite(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
        ]);

        $this->withSession($this->desktopSession(['fiscal' => ['visualizar']]))
            ->get('/fiscal/anexo-x/ajuda')
            ->assertOk()
            ->assertSee('modelo padronizado pela Receita Federal', false);
    }

    public function test_usuario_sem_permissao_fiscal_nao_acessa_a_tela(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
        ]);

        $this->withSession($this->desktopSession(['clientes' => ['visualizar']]))
            ->get('/fiscal/anexo-x')
            ->assertRedirect();
    }

    // ------------------------------------------------------------ auxiliares

    /**
     * @return array<string, mixed>
     */
    private function fakeNotificationsPayload(): array
    {
        return [
            'status' => 'success',
            'data' => ['items' => [], 'unread_count' => 0],
            'error' => null,
            'meta' => [
                'pagination' => ['current_page' => 1, 'per_page' => 6, 'total' => 0, 'last_page' => 1, 'from' => 0, 'to' => 0],
            ],
        ];
    }

    /**
     * @param  array<string, array<int, string>>  $permissions
     * @return array<string, mixed>
     */
    private function desktopSession(array $permissions): array
    {
        return [
            'desktop_auth' => [
                'token' => 'desktop-session-token',
                'synced_at' => time(),
                'user' => $this->fakeUser([
                    'permissions' => $permissions,
                    'modules' => array_keys($permissions),
                ]),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fakeUser(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 99,
            'nome' => 'Usuário de Teste',
            'email' => 'usuario@teste.local',
            'perfil' => 'admin',
            'group' => ['id' => 1, 'nome' => 'Administrador', 'descricao' => 'Grupo completo', 'sistema' => true],
            'modules' => [],
            'permissions' => [],
            'foto' => '',
            'ativo' => true,
        ], $overrides);
    }
}
