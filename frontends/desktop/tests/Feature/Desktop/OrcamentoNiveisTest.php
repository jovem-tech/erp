<?php

namespace Tests\Feature\Desktop;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Orçamento em níveis de manutenção (Básica / Avançada / Completa) no
 * desktop: o técnico liga "Oferecer opções de manutenção" e monta as opções
 * no quadro de composição (linhas = itens, colunas = opções); os checkboxes
 * de nível continuam ocultos em cada item e são o que vai no POST. A tela
 * de detalhe mostra a mesma matriz e a opção aprovada, e a aprovação "por
 * outros meios" informa qual opção o cliente escolheu.
 */
class OrcamentoNiveisTest extends TestCase
{
    public function test_create_form_renders_the_options_switch_the_board_and_hidden_level_inputs(): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge($this->notificationsFixture(), $this->companyFixture(), [
            'http://127.0.0.1:8000/api/v1/orcamentos/form-data*' => Http::response([
                'status' => 'success',
                'data' => [
                    'form' => [
                        'clients' => [],
                        'equipments' => [],
                        'orders' => [],
                        'services' => [],
                        'parts' => [],
                        'status_options' => [['value' => 'rascunho', 'label' => 'Rascunho']],
                        'niveis' => [
                            ['value' => 1, 'label' => 'Manutenção Básica', 'subtitle' => 'Volta a funcionar'],
                            ['value' => 2, 'label' => 'Manutenção Avançada', 'subtitle' => 'Corrige e previne'],
                            ['value' => 3, 'label' => 'Manutenção Completa', 'subtitle' => 'Como novo'],
                        ],
                        'default_validity_days' => 10,
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $response = $this
            ->withSession(array_merge($this->desktopSession(['orcamentos' => ['visualizar', 'criar']]), ['desktop_theme' => 'default']))
            ->get('/orcamentos/novo');

        $response
            ->assertOk()
            // Wire compatível: os checkboxes de nível continuam na linha do
            // item, só que ocultos — quem os liga/desliga é o quadro.
            ->assertSee('name="itens[0][niveis][]"', false)
            ->assertSee('data-budget-item-level-checkbox', false)
            ->assertDontSee('data-budget-item-level-all', false)
            ->assertDontSee('budget-item-field-level', false)
            // Interruptor no cabeçalho dos itens, desligado em orçamento novo.
            ->assertSee('id="orcamentoOfereceOpcoes"', false)
            ->assertSee('data-budget-tiers-toggle', false)
            ->assertSee('Oferecer opções de manutenção')
            // Quadro de composição com as três colunas, atalhos e "item só nesta opção".
            ->assertSee('data-budget-tiers-board', false)
            ->assertSee('Composição das opções')
            ->assertSee('data-budget-tiers-col="3"', false)
            ->assertSee('data-budget-tiers-col-none="1"', false)
            ->assertSee('data-budget-tiers-add="2"', false)
            ->assertSee('data-budget-tiers-alerts', false)
            ->assertSee('data-budget-item-levels-badge', false)
            // Recomendação mora no quadro; o recap do resumo continua.
            ->assertSee('name="nivel_recomendado"', false)
            ->assertSee('data-budget-levels-summary', false)
            ->assertSee('data-budget-level-card="3"', false)
            ->assertSee('Manutenção Completa')
            ->assertSee('Opções de manutenção')
            ->assertDontSee('inclui tudo da anterior');

        $content = (string) $response->getContent();
        $this->assertDoesNotMatchRegularExpression('/id="orcamentoOfereceOpcoes"[^>]*checked/', $content);
        $this->assertMatchesRegularExpression('/id="orcamentoItemLevel-0-1"[^>]*hidden/', $content);
        // Linha nova (template) e primeira linha nascem só na Básica; o JS
        // passa para as três quando o interruptor está ligado.
        $this->assertMatchesRegularExpression('/id="orcamentoItemLevel-0-1"[^>]*checked/', $content);
        $this->assertDoesNotMatchRegularExpression('/id="orcamentoItemLevel-0-2"[^>]*checked/', $content);
    }

    public function test_edit_form_with_options_renders_the_switch_on_and_marks_each_item_levels(): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge($this->notificationsFixture(), $this->companyFixture(), $this->editFixture([
            'has_tiers' => true,
            'nivel_maximo' => 3,
            'nivel_recomendado' => 3,
            'itens' => [
                $this->itemPayload(['id' => 1, 'descricao' => 'Diagnóstico', 'niveis' => [1, 2, 3]]),
                $this->itemPayload(['id' => 2, 'descricao' => 'RAM 8GB', 'niveis' => [3]]),
            ],
        ])));

        $response = $this
            ->withSession(array_merge($this->desktopSession(['orcamentos' => ['visualizar', 'criar', 'editar']]), ['desktop_theme' => 'default']))
            ->get('/orcamentos/993/editar');

        $response->assertOk()->assertSee('<option value="3" selected>Manutenção Completa</option>', false);

        $content = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/id="orcamentoOfereceOpcoes"[^>]*checked/', $content);
        $this->assertMatchesRegularExpression('/id="orcamentoItemLevel-0-1"[^>]*checked/', $content);
        $this->assertMatchesRegularExpression('/id="orcamentoItemLevel-0-2"[^>]*checked/', $content);
        $this->assertMatchesRegularExpression('/id="orcamentoItemLevel-0-3"[^>]*checked/', $content);
        $this->assertDoesNotMatchRegularExpression('/id="orcamentoItemLevel-1-1"[^>]*checked/', $content);
        $this->assertDoesNotMatchRegularExpression('/id="orcamentoItemLevel-1-2"[^>]*checked/', $content);
        $this->assertMatchesRegularExpression('/id="orcamentoItemLevel-1-3"[^>]*checked/', $content);
    }

    public function test_edit_form_without_options_renders_the_switch_off(): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge($this->notificationsFixture(), $this->companyFixture(), $this->editFixture([
            'itens' => [$this->itemPayload(['id' => 1, 'descricao' => 'Fusível', 'niveis' => [1]])],
        ])));

        $response = $this
            ->withSession(array_merge($this->desktopSession(['orcamentos' => ['visualizar', 'criar', 'editar']]), ['desktop_theme' => 'default']))
            ->get('/orcamentos/993/editar');

        $response->assertOk()->assertSee('data-budget-tiers-toggle', false);
        $this->assertDoesNotMatchRegularExpression('/id="orcamentoOfereceOpcoes"[^>]*checked/', (string) $response->getContent());
    }

    public function test_edit_form_hides_the_switch_once_an_option_was_approved(): void
    {
        // Depois da aprovação a lista já é o escopo contratado: nada a
        // oferecer, e o JS não pode forçar níveis (mudaria a fingerprint dos
        // itens e reabriria a decisão do cliente).
        Http::preventStrayRequests();
        Http::fake(array_merge($this->notificationsFixture(), $this->companyFixture(), $this->editFixture([
            'status' => 'pendente_abertura_os',
            'status_label' => 'Aprovado',
            'nivel_aprovado' => 2,
            'nivel_aprovado_label' => 'Manutenção Avançada',
            'itens' => [$this->itemPayload(['id' => 1, 'descricao' => 'Bateria', 'niveis' => [2, 3]])],
        ])));

        $response = $this
            ->withSession(array_merge($this->desktopSession(['orcamentos' => ['visualizar', 'criar', 'editar']]), ['desktop_theme' => 'default']))
            ->get('/orcamentos/993/editar');

        $response
            ->assertOk()
            ->assertDontSee('data-budget-tiers-toggle', false)
            ->assertDontSee('name="oferece_opcoes"', false)
            ->assertSee('data-budget-tiers-board data-budget-levels-locked="1"', false);
    }

    /**
     * Itens das opções não escolhidas (preservados na aprovação) aparecem
     * na edição como painel de reaproveitamento — fora da lista de itens,
     * com o botão que os traz de volta. Sem itens descartados, nada.
     */
    public function test_edit_form_offers_the_discarded_items_of_the_other_options(): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge($this->notificationsFixture(), $this->companyFixture(), $this->editFixture([
            'status' => 'aprovado',
            'status_label' => 'Aprovado',
            'nivel_aprovado' => 1,
            'nivel_aprovado_label' => 'Manutenção Básica',
            'itens' => [$this->itemPayload(['id' => 1, 'descricao' => 'Fusível', 'niveis' => [1]])],
            'itens_descartados' => [
                [
                    'id' => 41,
                    'aprovacao_id' => 7,
                    'nivel_aprovado' => 1,
                    'nivel_aprovado_label' => 'Manutenção Básica',
                    'niveis' => [2, 3],
                    'niveis_labels' => ['Manutenção Avançada', 'Manutenção Completa'],
                    'descartado_em' => '22/09/2026 00:59',
                    'tipo_item' => 'peca',
                    'referencia_id' => 12,
                    'descricao' => 'Bateria "premium"',
                    'quantidade' => 1.0,
                    'valor_unitario' => 200.0,
                    'desconto' => 0.0,
                    'desconto_tipo' => 'valor',
                    'desconto_percentual' => null,
                    'acrescimo' => 0.0,
                    'acrescimo_tipo' => 'valor',
                    'acrescimo_percentual' => null,
                    'total' => 200.0,
                    'observacoes' => '',
                    'modo_precificacao' => 'manual',
                ],
            ],
        ])));

        $response = $this
            ->withSession(array_merge($this->desktopSession(['orcamentos' => ['visualizar', 'criar', 'editar']]), ['desktop_theme' => 'default']))
            ->get('/orcamentos/993/editar');

        $response
            ->assertOk()
            ->assertSee('Itens das outras opções (não contratados)')
            ->assertSee('Ofertado na Avançada e Completa')
            ->assertSee('Cliente aprovou a Básica em 22/09/2026 00:59')
            ->assertSee('data-budget-discarded-add', false)
            ->assertSee('Adicionar ao orçamento');

        $html = $response->getContent();
        // O payload do botão é o que createRow() consome — sem `niveis`.
        $this->assertMatchesRegularExpression('/data-item="[^"]*referencia_id[^"]*"/', $html);
        $this->assertStringContainsString('&quot;descricao&quot;:&quot;Bateria \\&quot;premium\\&quot;&quot;', $html);
        $this->assertStringNotContainsString('&quot;niveis&quot;', substr($html, (int) strpos($html, 'data-budget-discarded-add')));
        // O item descartado NÃO virou linha do formulário (só o Fusível, mais
        // o <template> da linha nova).
        $this->assertStringContainsString('itens[0][descricao]', $html);
        $this->assertStringNotContainsString('itens[1][descricao]', $html);
        $this->assertStringNotContainsString('value="Bateria &quot;premium&quot;"', $html);
    }

    public function test_edit_form_without_discarded_items_has_no_reuse_panel(): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge($this->notificationsFixture(), $this->companyFixture(), $this->editFixture([
            'itens' => [$this->itemPayload(['id' => 1, 'descricao' => 'Fusível'])],
            'itens_descartados' => [],
        ])));

        $this
            ->withSession(array_merge($this->desktopSession(['orcamentos' => ['visualizar', 'criar', 'editar']]), ['desktop_theme' => 'default']))
            ->get('/orcamentos/993/editar')
            ->assertOk()
            ->assertDontSee('data-budget-discarded-panel', false);
    }

    public function test_store_forwards_item_levels_and_recommended_level_to_backend(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orcamentos' => Http::response([
                'status' => 'success',
                'data' => ['budget' => ['id' => 951]],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $response = $this
            ->withSession(array_merge($this->desktopSession(['orcamentos' => ['visualizar', 'criar']]), ['desktop_theme' => 'default']))
            ->post('/orcamentos', [
                'tipo_orcamento' => 'previo',
                'status' => 'rascunho',
                'origem' => 'manual',
                'cliente_nome_avulso' => 'Cliente Níveis',
                'relato_cliente' => 'Não liga',
                'prazo_execucao' => '3 dias',
                'telefone_contato' => '(11) 99999-9999',
                'validade_dias' => 10,
                'oferece_opcoes' => 1,
                'nivel_recomendado' => 2,
                'subtotal' => 'R$ 300,00',
                'desconto' => 'R$ 0,00',
                'acrescimo' => 'R$ 0,00',
                'total' => 'R$ 300,00',
                'itens' => [
                    ['tipo_item' => 'servico', 'descricao' => 'Fusível', 'quantidade' => 1, 'valor_unitario' => 'R$ 100,00', 'niveis' => [1]],
                    ['tipo_item' => 'servico', 'descricao' => 'Bateria', 'quantidade' => 1, 'valor_unitario' => 'R$ 200,00', 'niveis' => [2]],
                ],
            ]);

        $response->assertRedirect(route('orcamentos.show', 951));

        Http::assertSent(static function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/api/v1/orcamentos'
                && (int) ($request['nivel_recomendado'] ?? 0) === 2
                && ($request['itens'][0]['niveis'] ?? []) === [1]
                && ($request['itens'][1]['niveis'] ?? []) === [2]
                // O interruptor é do formulário; o backend infere pelos itens.
                && ! array_key_exists('oferece_opcoes', $request->data());
        });
    }

    public function test_store_with_options_off_sends_every_item_in_the_basic_level_only(): void
    {
        // Garantia no servidor do que o JS já faz: interruptor desligado =
        // orçamento comum, mesmo que os checkboxes ocultos tenham vindo
        // marcados (rascunho antigo, DOM manipulado) — e sem recomendação.
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orcamentos' => Http::response([
                'status' => 'success',
                'data' => ['budget' => ['id' => 952]],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $response = $this
            ->withSession(array_merge($this->desktopSession(['orcamentos' => ['visualizar', 'criar']]), ['desktop_theme' => 'default']))
            ->post('/orcamentos', [
                'tipo_orcamento' => 'previo',
                'status' => 'rascunho',
                'origem' => 'manual',
                'cliente_nome_avulso' => 'Cliente Níveis',
                'relato_cliente' => 'Não liga',
                'prazo_execucao' => '3 dias',
                'telefone_contato' => '(11) 99999-9999',
                'validade_dias' => 10,
                'oferece_opcoes' => 0,
                'nivel_recomendado' => 2,
                'subtotal' => 'R$ 300,00',
                'desconto' => 'R$ 0,00',
                'acrescimo' => 'R$ 0,00',
                'total' => 'R$ 300,00',
                'itens' => [
                    ['tipo_item' => 'servico', 'descricao' => 'Fusível', 'quantidade' => 1, 'valor_unitario' => 'R$ 100,00', 'niveis' => [2, 3]],
                    ['tipo_item' => 'servico', 'descricao' => 'Bateria', 'quantidade' => 1, 'valor_unitario' => 'R$ 200,00'],
                ],
            ]);

        $response->assertRedirect(route('orcamentos.show', 952));

        Http::assertSent(static function ($request): bool {
            $data = $request->data();

            return $request->url() === 'http://127.0.0.1:8000/api/v1/orcamentos'
                && ($data['itens'][0]['niveis'] ?? []) === [1]
                && ($data['itens'][1]['niveis'] ?? []) === [1]
                && array_key_exists('nivel_recomendado', $data)
                && $data['nivel_recomendado'] === null
                && ! array_key_exists('oferece_opcoes', $data);
        });
    }

    public function test_store_with_options_on_rejects_an_item_left_out_of_every_option(): void
    {
        // Sem isto o backend gravaria o item em [1] em silêncio — o técnico
        // veria o item aparecer na Básica sem ter decidido nada.
        Http::fake();

        $response = $this
            ->withSession(array_merge($this->desktopSession(['orcamentos' => ['visualizar', 'criar']]), ['desktop_theme' => 'default']))
            ->from('/orcamentos/novo')
            ->post('/orcamentos', [
                'tipo_orcamento' => 'previo',
                'status' => 'rascunho',
                'cliente_nome_avulso' => 'Cliente Níveis',
                'relato_cliente' => 'Não liga',
                'prazo_execucao' => '3 dias',
                'telefone_contato' => '(11) 99999-9999',
                'oferece_opcoes' => 1,
                'total' => 'R$ 300,00',
                'itens' => [
                    ['tipo_item' => 'servico', 'descricao' => 'Fusível', 'quantidade' => 1, 'valor_unitario' => 'R$ 100,00', 'niveis' => [1, 2, 3]],
                    ['tipo_item' => 'servico', 'descricao' => 'Memória RAM 8GB', 'quantidade' => 1, 'valor_unitario' => 'R$ 200,00'],
                ],
            ]);

        $response
            ->assertRedirect('/orcamentos/novo')
            ->assertSessionHasErrors(['itens.1.niveis' => 'O item "Memória RAM 8GB" não está em nenhuma opção de manutenção — inclua-o em uma opção ou exclua o item.']);
        Http::assertNothingSent();
    }

    public function test_store_rejects_an_unknown_level(): void
    {
        Http::fake();

        $response = $this
            ->withSession(array_merge($this->desktopSession(['orcamentos' => ['visualizar', 'criar']]), ['desktop_theme' => 'default']))
            ->from('/orcamentos/novo')
            ->post('/orcamentos', [
                'tipo_orcamento' => 'previo',
                'status' => 'rascunho',
                'cliente_nome_avulso' => 'Cliente Níveis',
                'relato_cliente' => 'Não liga',
                'prazo_execucao' => '3 dias',
                'telefone_contato' => '(11) 99999-9999',
                'total' => 'R$ 100,00',
                'itens' => [
                    ['tipo_item' => 'servico', 'descricao' => 'Fusível', 'quantidade' => 1, 'valor_unitario' => 'R$ 100,00', 'niveis' => [7]],
                ],
            ]);

        $response->assertRedirect('/orcamentos/novo')->assertSessionHasErrors('itens.0.niveis.0');
        Http::assertNothingSent();
    }

    public function test_show_page_presents_options_level_column_and_level_choice_on_staff_approval(): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/orcamentos/990' => Http::response([
                'status' => 'success',
                'data' => ['budget' => $this->budgetPayload([
                    'has_tiers' => true,
                    'nivel_maximo' => 3,
                    'nivel_recomendado' => 2,
                    'niveis' => [
                        ['nivel' => 1, 'label' => 'Manutenção Básica', 'subtitle' => 'Volta a funcionar', 'total' => 100.0, 'itens_count' => 1, 'recomendado' => false],
                        ['nivel' => 2, 'label' => 'Manutenção Avançada', 'subtitle' => 'Corrige e previne', 'total' => 300.0, 'itens_count' => 2, 'recomendado' => true],
                        ['nivel' => 3, 'label' => 'Manutenção Completa', 'subtitle' => 'Como novo', 'total' => 600.0, 'itens_count' => 3, 'recomendado' => false],
                    ],
                    'itens' => [
                        $this->itemPayload(['descricao' => 'Fusível', 'niveis' => [1]]),
                        $this->itemPayload(['descricao' => 'Bateria', 'niveis' => [2], 'valor_unitario' => 200.0, 'total' => 200.0]),
                    ],
                ])],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $response = $this
            ->withSession(array_merge($this->desktopSession(['orcamentos' => ['visualizar', 'editar']]), ['desktop_theme' => 'default']))
            ->get('/orcamentos/990');

        $response
            ->assertOk()
            ->assertSee('3 opções de manutenção')
            ->assertSee('Total (opção completa)')
            ->assertSee('Manutenção Avançada · Recomendada')
            ->assertSee('R$ 300,00')
            ->assertDontSee('inclui tudo da anterior')
            // A mesma matriz item × opção do formulário: uma coluna por opção.
            ->assertSee('data-budget-show-level-col="1"', false)
            ->assertSee('data-budget-show-level-col="3"', false)
            ->assertSee('data-budget-show-level-cell="1" data-included="1"', false)
            ->assertSee('data-budget-show-level-cell="2" data-included="1"', false)
            ->assertSee('data-budget-show-level-cell="3" data-included="0"', false)
            // Soma cega de toda linha não é o valor de nada com opções.
            ->assertDontSee('Totais dos itens')
            // Conferir a página do cliente sem sair do detalhe.
            ->assertSee('href="http://127.0.0.1:8000/orcamento/token-abc" class="dropdown-item" target="_blank" rel="noopener"', false)
            ->assertSee('Abrir página do cliente')
            ->assertSee('data-confirm-input-label="Opção escolhida pelo cliente"', false)
            ->assertSee('name="nivel" value="2" data-confirm-value', false);

        // Fusível só na Básica, Bateria só na Avançada: 2 células marcadas em 6.
        $content = (string) $response->getContent();
        $this->assertSame(2, substr_count($content, 'data-included="1"'));
        $this->assertSame(4, substr_count($content, 'data-included="0"'));
    }

    public function test_show_page_without_tiers_keeps_the_plain_layout(): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/orcamentos/990' => Http::response([
                'status' => 'success',
                'data' => ['budget' => $this->budgetPayload(['itens' => [$this->itemPayload()]])],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $response = $this
            ->withSession(array_merge($this->desktopSession(['orcamentos' => ['visualizar', 'editar']]), ['desktop_theme' => 'default']))
            ->get('/orcamentos/990');

        $response
            ->assertOk()
            ->assertDontSee('opções de manutenção')
            ->assertDontSee('data-budget-show-level-col', false)
            ->assertDontSee('data-budget-show-level-cell', false)
            ->assertSee('Totais dos itens')
            ->assertDontSee('data-confirm-input-label="Opção escolhida pelo cliente"', false);
    }

    public function test_show_page_shows_the_approved_option(): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/orcamentos/990' => Http::response([
                'status' => 'success',
                'data' => ['budget' => $this->budgetPayload([
                    'status' => 'aprovado',
                    'status_label' => 'Aprovado',
                    'can_approve' => false,
                    'nivel_aprovado' => 2,
                    'nivel_aprovado_label' => 'Manutenção Avançada',
                    'itens' => [$this->itemPayload()],
                ])],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $this
            ->withSession(array_merge($this->desktopSession(['orcamentos' => ['visualizar']]), ['desktop_theme' => 'default']))
            ->get('/orcamentos/990')
            ->assertOk()
            ->assertSee('Opção aprovada: Manutenção Avançada')
            ->assertSee('Escopo contratado — Manutenção Avançada')
            // Sem snapshot (aprovação antiga sem registro), nada de opções.
            ->assertDontSee('data-budget-offered-options', false);
    }

    /**
     * Depois da aprovação o bloco de opções vem do snapshot: cartões com a
     * escolhida marcada, o comparativo item × opção (inclusive os itens que
     * saíram do escopo) e o resumo por aprovação.
     */
    public function test_show_page_presents_the_offered_options_history_after_approval(): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/orcamentos/990' => Http::response([
                'status' => 'success',
                'data' => ['budget' => $this->budgetPayload([
                    'status' => 'aprovado',
                    'status_label' => 'Aprovado',
                    'can_approve' => false,
                    'nivel_aprovado' => 2,
                    'nivel_aprovado_label' => 'Manutenção Avançada',
                    'total' => 300.0,
                    'itens' => [
                        $this->itemPayload(['id' => 1, 'descricao' => 'Fusível', 'niveis' => [1]]),
                        $this->itemPayload(['id' => 2, 'descricao' => 'Bateria', 'valor_unitario' => 200.0, 'total' => 200.0, 'niveis' => [1]]),
                    ],
                    'niveis_ofertados' => $this->offeredOptionsPayload(),
                    'aprovacoes' => [[
                        'id' => 7,
                        'acao' => 'aprovado',
                        'origem' => 'link_publico',
                        'usuario_nome' => 'Cliente',
                        'resposta_cliente' => 'Aprovado. Opção escolhida: Manutenção Avançada.',
                        'observacao' => '',
                        'nivel' => 2,
                        'nivel_label' => 'Manutenção Avançada',
                        'niveis_resumo' => [
                            ['nivel' => 1, 'label' => 'Manutenção Básica', 'total' => 100.0, 'itens_count' => 1, 'recomendado' => false],
                            ['nivel' => 2, 'label' => 'Manutenção Avançada', 'total' => 300.0, 'itens_count' => 2, 'recomendado' => true],
                            ['nivel' => 3, 'label' => 'Manutenção Completa', 'total' => 600.0, 'itens_count' => 3, 'recomendado' => false],
                        ],
                        'created_at' => '22/09/2026 00:59',
                    ]],
                ])],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $response = $this
            ->withSession(array_merge($this->desktopSession(['orcamentos' => ['visualizar']]), ['desktop_theme' => 'default']))
            ->get('/orcamentos/990')
            ->assertOk()
            ->assertSee('data-budget-offered-origin="aprovacao"', false)
            ->assertSee('O cliente escolheu a Manutenção Avançada')
            ->assertSee('aprovada em 22/09/2026 00:59 pelo link público')
            ->assertSee('Aprovada pelo cliente')
            ->assertSee('Não escolhida')
            ->assertSee('Manutenção Avançada · Recomendada')
            ->assertSee('Comparativo do que foi oferecido')
            // Item que saiu do escopo continua no comparativo, marcado.
            ->assertSee('Película e limpeza')
            ->assertSee('fora do escopo contratado')
            ->assertSee('Total da opção')
            ->assertSee('Opções apresentadas:')
            ->assertSee('Básica R$ 100,00 · Avançada R$ 300,00 · Completa R$ 600,00')
            ->assertSee('Aprovado · Manutenção Avançada');

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'data-budget-offered-card="2" data-chosen="1"'));
        $this->assertSame(2, substr_count($html, 'data-chosen="0"'));
        // 3 linhas (união das opções): Completa inclui as 3, Básica só o Fusível.
        $this->assertSame(3, substr_count($html, 'data-budget-offered-cell="3" data-included="1"'));
        $this->assertSame(1, substr_count($html, 'data-budget-offered-cell="1" data-included="1"'));
        $this->assertSame(2, substr_count($html, 'data-budget-offered-cell="1" data-included="0"'));
        $this->assertStringContainsString('data-budget-offered-col="2"', $html);
    }

    /**
     * Snapshot antigo (só nomes de item) ainda rende o bloco — sem qtd e
     * unitário no comparativo.
     */
    public function test_show_page_handles_a_legacy_snapshot_without_item_details(): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/orcamentos/990' => Http::response([
                'status' => 'success',
                'data' => ['budget' => $this->budgetPayload([
                    'status' => 'aprovado',
                    'status_label' => 'Aprovado',
                    'nivel_aprovado' => 1,
                    'nivel_aprovado_label' => 'Manutenção Básica',
                    'itens' => [$this->itemPayload()],
                    'niveis_ofertados' => [
                        'origem' => 'aprovacao',
                        'layout' => ['garantia' => ['modo' => 'compartilhado', 'valor' => '90 dias'], 'formas_pagamento' => ['modo' => 'compartilhado', 'valor' => ''], 'parcelamento' => ['modo' => 'compartilhado', 'valor' => ''], 'entrega_domicilio' => ['modo' => 'compartilhado', 'valor' => '']],
                        'aprovacao' => ['id' => 3, 'created_at' => '16/09/2026 09:00', 'origem' => 'painel', 'origem_label' => 'pelo painel', 'usuario_nome' => 'Otávio', 'nivel' => 1, 'nivel_label' => 'Manutenção Básica', 'vigente' => true],
                        'niveis' => [
                            ['nivel' => 1, 'label' => 'Manutenção Básica', 'subtitle' => 'Volta a funcionar', 'subtotal' => 100.0, 'desconto' => 0.0, 'acrescimo' => 0.0, 'total' => 100.0, 'itens' => ['Fusível'], 'itens_count' => 1, 'recomendado' => false, 'itens_detalhe' => [], 'condicoes_comerciais' => null],
                            ['nivel' => 2, 'label' => 'Manutenção Avançada', 'subtitle' => 'Corrige e previne', 'subtotal' => 300.0, 'desconto' => 0.0, 'acrescimo' => 0.0, 'total' => 300.0, 'itens' => ['Fusível', 'Bateria'], 'itens_count' => 2, 'recomendado' => false, 'itens_detalhe' => [], 'condicoes_comerciais' => null],
                        ],
                    ],
                ])],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $this
            ->withSession(array_merge($this->desktopSession(['orcamentos' => ['visualizar']]), ['desktop_theme' => 'default']))
            ->get('/orcamentos/990')
            ->assertOk()
            ->assertSee('O cliente escolheu a Manutenção Básica')
            ->assertSee('aprovada em 16/09/2026 09:00 por Otávio pelo painel')
            ->assertSee('Bateria')
            ->assertSee('Mesmas condições comerciais em todas as opções')
            ->assertDontSee('Unitário');
    }

    public function test_staff_approval_forwards_the_chosen_level(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orcamentos/990/aprovar' => Http::response([
                'status' => 'success',
                'data' => ['budget' => ['status' => 'aprovado'], 'message' => 'Aprovação registrada com sucesso.'],
                'error' => null,
                'meta' => [],
            ]),
        ]);

        $this
            ->withSession(array_merge($this->desktopSession(['orcamentos' => ['visualizar', 'editar']]), ['desktop_theme' => 'default']))
            ->post('/orcamentos/990/aprovar', ['observacao' => 'Cliente escolheu por telefone.', 'nivel' => 2])
            ->assertRedirect(route('orcamentos.show', 990))
            ->assertSessionHas('success', 'Aprovação registrada com sucesso.');

        Http::assertSent(static function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/api/v1/orcamentos/990/aprovar'
                && ($request['observacao'] ?? null) === 'Cliente escolheu por telefone.'
                && (int) ($request['nivel'] ?? 0) === 2;
        });
    }

    // ------------------------------------------------------------------
    // Condições comerciais por opção de manutenção.
    // ------------------------------------------------------------------

    public function test_create_form_renders_delivery_checkbox_and_the_hidden_per_level_terms_section(): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge($this->notificationsFixture(), $this->companyFixture(), [
            'http://127.0.0.1:8000/api/v1/orcamentos/form-data*' => Http::response([
                'status' => 'success',
                'data' => ['form' => $this->formData()],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $response = $this
            ->withSession(array_merge($this->desktopSession(['orcamentos' => ['visualizar', 'criar']]), ['desktop_theme' => 'default']))
            ->get('/orcamentos/novo');

        $response
            ->assertOk()
            ->assertSee('name="entrega_domicilio" value="1"', false)
            ->assertSee('Inclui entrega do equipamento no endereço do cliente')
            // Seção por opção existe no DOM, mas nasce escondida — o JS a
            // revela só quando algum item está em nível 2 ou 3.
            ->assertSee('data-budget-terms-levels data-budget-levels-locked="0" hidden', false)
            ->assertSee('Personalizar condições por opção de manutenção')
            ->assertSee('name="niveis_condicoes[2][garantia_dias]"', false)
            ->assertSee('name="niveis_condicoes[3][entrega_domicilio]"', false)
            ->assertSee('name="niveis_condicoes[3][beneficios]"', false)
            ->assertSee('name="niveis_condicoes[2][formas_pagamento][]"', false)
            ->assertSee('Herdar do padrão acima');
    }

    public function test_edit_form_prefills_level_overrides_including_explicit_no_delivery(): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge($this->notificationsFixture(), $this->companyFixture(), [
            'http://127.0.0.1:8000/api/v1/orcamentos/form-data*' => Http::response([
                'status' => 'success',
                'data' => ['form' => $this->formData()],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/orcamentos/993' => Http::response([
                'status' => 'success',
                'data' => [
                    'budget' => $this->budgetPayload([
                        'id' => 993,
                        'status' => 'rascunho',
                        'status_label' => 'Rascunho',
                        'has_tiers' => true,
                        'nivel_maximo' => 3,
                        'entrega_domicilio' => true,
                        'garantia_dias' => 90,
                        'condicoes_comerciais' => ['formas_pagamento' => [['codigo' => 'pix', 'nome' => 'Pix', 'is_cartao' => false]]],
                        'niveis_condicoes' => [
                            1 => ['garantia_dias' => null, 'garantia_label' => '', 'parcelas_sem_juros' => null, 'entrega_domicilio' => false, 'formas_pagamento' => [], 'beneficios' => []],
                            3 => ['garantia_dias' => 365, 'garantia_label' => '1 ano', 'parcelas_sem_juros' => 12, 'entrega_domicilio' => null, 'formas_pagamento' => ['pix', 'cartao_credito'], 'beneficios' => ['Instalação expressa', 'Película de brinde']],
                        ],
                        'itens' => [
                            $this->itemPayload(['id' => 1, 'descricao' => 'Fusível', 'niveis' => [1]]),
                            $this->itemPayload(['id' => 2, 'descricao' => 'Bateria', 'niveis' => [3]]),
                        ],
                    ]),
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $response = $this
            ->withSession(array_merge($this->desktopSession(['orcamentos' => ['visualizar', 'criar', 'editar']]), ['desktop_theme' => 'default']))
            ->get('/orcamentos/993/editar');

        $response
            ->assertOk()
            // Padrão: entrega ligada.
            ->assertSee('id="orcamentoEntregaDomicilio" checked', false)
            // Básica: override explícito "não" (não pode virar "herdar").
            ->assertSee('<option value="0" selected>Não incluir entrega</option>', false)
            // Completa: garantia, parcelas, formas e diferenciais personalizados; entrega herdada.
            ->assertSee('name="niveis_condicoes[3][garantia_dias]"', false)
            ->assertSee('<option value="365" selected>', false)
            ->assertSee('<option value="12" selected>', false)
            ->assertSee('id="orcamentoNivel3Forma3"', false)
            ->assertSee("Instalação expressa\nPelícula de brinde")
            // Avançada: nada personalizado → tudo em "herdar".
            ->assertDontSee('name="niveis_condicoes[2][garantia_dias]" class="form-select"><option value="">Herdar do padrão acima</option><option value="90" selected>', false);

        // Uma opção "Sim" selecionada só onde foi gravada: em nenhum nível aqui.
        $content = (string) $response->getContent();
        $this->assertSame(0, substr_count($content, '<option value="1" selected>Sim, incluir entrega</option>'));
        $this->assertSame(1, substr_count($content, '<option value="0" selected>Não incluir entrega</option>'));

        // Checkboxes de nível refletem `niveis` de cada item — sem cascata:
        // Fusível (niveis=[1]) só marca Básica, Bateria (niveis=[3]) só Completa.
        $this->assertMatchesRegularExpression('/id="orcamentoItemLevel-0-1"[^>]*checked/', $content);
        $this->assertDoesNotMatchRegularExpression('/id="orcamentoItemLevel-0-2"[^>]*checked/', $content);
        $this->assertDoesNotMatchRegularExpression('/id="orcamentoItemLevel-0-3"[^>]*checked/', $content);
        $this->assertDoesNotMatchRegularExpression('/id="orcamentoItemLevel-1-1"[^>]*checked/', $content);
        $this->assertDoesNotMatchRegularExpression('/id="orcamentoItemLevel-1-2"[^>]*checked/', $content);
        $this->assertMatchesRegularExpression('/id="orcamentoItemLevel-1-3"[^>]*checked/', $content);
    }

    public function test_store_forwards_delivery_and_per_level_terms_with_split_benefits(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orcamentos' => Http::response([
                'status' => 'success',
                'data' => ['budget' => ['id' => 952]],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $response = $this
            ->withSession(array_merge($this->desktopSession(['orcamentos' => ['visualizar', 'criar']]), ['desktop_theme' => 'default']))
            ->post('/orcamentos', [
                'tipo_orcamento' => 'previo',
                'status' => 'rascunho',
                'origem' => 'manual',
                'cliente_nome_avulso' => 'Cliente Condições',
                'relato_cliente' => 'Não liga',
                'prazo_execucao' => '3 dias',
                'telefone_contato' => '(11) 99999-9999',
                'validade_dias' => 10,
                'entrega_domicilio' => '1',
                'formas_pagamento' => ['', 'pix'],
                'subtotal' => 'R$ 300,00',
                'desconto' => 'R$ 0,00',
                'acrescimo' => 'R$ 0,00',
                'total' => 'R$ 300,00',
                'itens' => [
                    ['tipo_item' => 'servico', 'descricao' => 'Fusível', 'quantidade' => 1, 'valor_unitario' => 'R$ 100,00', 'niveis' => [1]],
                    ['tipo_item' => 'servico', 'descricao' => 'Bateria', 'quantidade' => 1, 'valor_unitario' => 'R$ 200,00', 'niveis' => [3]],
                ],
                'niveis_condicoes' => [
                    // Tudo em "herdar": chega vazio e o backend não grava nada.
                    1 => ['garantia_dias' => '', 'parcelas_sem_juros' => '', 'entrega_domicilio' => '0', 'formas_pagamento' => [''], 'beneficios' => ''],
                    2 => ['garantia_dias' => '', 'parcelas_sem_juros' => '', 'entrega_domicilio' => '', 'formas_pagamento' => [''], 'beneficios' => "  \n"],
                    3 => ['garantia_dias' => '365', 'parcelas_sem_juros' => '12', 'entrega_domicilio' => '1', 'formas_pagamento' => ['', 'pix', 'cartao_credito'], 'beneficios' => "Instalação expressa\r\n\r\n  Película de brinde  \n"],
                ],
            ]);

        $response->assertRedirect(route('orcamentos.show', 952));

        Http::assertSent(static function ($request): bool {
            $niveis = $request['niveis_condicoes'] ?? [];

            return $request->url() === 'http://127.0.0.1:8000/api/v1/orcamentos'
                && $request['entrega_domicilio'] === true
                && ($request['formas_pagamento'] ?? null) === ['pix']
                && ($niveis[1]['entrega_domicilio'] ?? null) === false
                && ($niveis[1]['formas_pagamento'] ?? null) === []
                && ($niveis[1]['beneficios'] ?? null) === []
                && array_key_exists('entrega_domicilio', $niveis[2] ?? []) && $niveis[2]['entrega_domicilio'] === null
                && ($niveis[2]['beneficios'] ?? null) === []
                && (int) ($niveis[3]['garantia_dias'] ?? 0) === 365
                && (int) ($niveis[3]['parcelas_sem_juros'] ?? 0) === 12
                && ($niveis[3]['entrega_domicilio'] ?? null) === true
                && ($niveis[3]['formas_pagamento'] ?? null) === ['pix', 'cartao_credito']
                && ($niveis[3]['beneficios'] ?? null) === ['Instalação expressa', 'Película de brinde'];
        });
    }

    public function test_store_without_the_delivery_checkbox_sends_false_and_rejects_bad_level_terms(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orcamentos' => Http::response([
                'status' => 'success',
                'data' => ['budget' => ['id' => 953]],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $base = [
            'tipo_orcamento' => 'previo',
            'status' => 'rascunho',
            'origem' => 'manual',
            'cliente_nome_avulso' => 'Cliente Sem Entrega',
            'relato_cliente' => 'Não liga',
            'prazo_execucao' => '3 dias',
            'telefone_contato' => '(11) 99999-9999',
            'validade_dias' => 10,
            // Marcador oculto do formulário: desmarcado chega como "0".
            'entrega_domicilio' => '0',
            'subtotal' => 'R$ 100,00',
            'desconto' => 'R$ 0,00',
            'acrescimo' => 'R$ 0,00',
            'total' => 'R$ 100,00',
            'itens' => [
                ['tipo_item' => 'servico', 'descricao' => 'Fusível', 'quantidade' => 1, 'valor_unitario' => 'R$ 100,00', 'niveis' => [1]],
            ],
        ];

        $this
            ->withSession(array_merge($this->desktopSession(['orcamentos' => ['visualizar', 'criar']]), ['desktop_theme' => 'default']))
            ->post('/orcamentos', $base)
            ->assertRedirect(route('orcamentos.show', 953));

        Http::assertSent(static fn ($request): bool => $request->url() === 'http://127.0.0.1:8000/api/v1/orcamentos'
            && $request['entrega_domicilio'] === false);

        $this
            ->withSession(array_merge($this->desktopSession(['orcamentos' => ['visualizar', 'criar']]), ['desktop_theme' => 'default']))
            ->from('/orcamentos/novo')
            ->post('/orcamentos', array_merge($base, [
                'niveis_condicoes' => [2 => ['garantia_dias' => '45', 'entrega_domicilio' => 'talvez']],
            ]))
            ->assertRedirect('/orcamentos/novo')
            ->assertSessionHasErrors(['niveis_condicoes.2.garantia_dias', 'niveis_condicoes.2.entrega_domicilio']);
    }

    public function test_show_page_lists_delivery_benefits_and_per_level_customizations(): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge($this->notificationsFixture(), $this->companyFixture(), [
            'http://127.0.0.1:8000/api/v1/orcamentos/994' => Http::response([
                'status' => 'success',
                'data' => [
                    'budget' => $this->budgetPayload([
                        'id' => 994,
                        'has_tiers' => true,
                        'nivel_maximo' => 3,
                        'niveis' => [
                            ['nivel' => 1, 'label' => 'Manutenção Básica', 'subtitle' => 'Volta a funcionar', 'total' => 100.0, 'itens' => ['Fusível'], 'itens_novos' => ['Fusível'], 'itens_count' => 1, 'recomendado' => false],
                            ['nivel' => 2, 'label' => 'Manutenção Avançada', 'subtitle' => 'Corrige e previne', 'total' => 300.0, 'itens' => ['Fusível', 'Bateria'], 'itens_novos' => ['Bateria'], 'itens_count' => 2, 'recomendado' => false],
                            ['nivel' => 3, 'label' => 'Manutenção Completa', 'subtitle' => 'Como novo', 'total' => 600.0, 'itens' => ['Fusível', 'Bateria', 'Película'], 'itens_novos' => ['Película'], 'itens_count' => 3, 'recomendado' => false],
                        ],
                        'condicoes_comerciais' => [
                            'formas_pagamento' => [['codigo' => 'pix', 'nome' => 'Pix', 'is_cartao' => false]],
                            'formas_pagamento_texto' => 'Pix',
                            'parcelamento_texto' => '',
                            'chaves_pix' => [],
                            'garantia_label' => '90 dias',
                            'entrega_domicilio' => false,
                            'beneficios' => [],
                            'complemento' => '',
                            'tem_conteudo' => true,
                        ],
                        'niveis_condicoes' => [
                            3 => ['garantia_dias' => 365, 'garantia_label' => '1 ano', 'parcelas_sem_juros' => null, 'entrega_domicilio' => true, 'formas_pagamento' => [], 'beneficios' => ['Instalação expressa']],
                        ],
                        'itens' => [
                            $this->itemPayload(['id' => 1, 'descricao' => 'Fusível', 'niveis' => [1]]),
                            $this->itemPayload(['id' => 2, 'descricao' => 'Bateria', 'niveis' => [2]]),
                            $this->itemPayload(['id' => 3, 'descricao' => 'Película', 'niveis' => [3]]),
                        ],
                    ]),
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $this
            ->withSession(array_merge($this->desktopSession(['orcamentos' => ['visualizar']]), ['desktop_theme' => 'default']))
            ->get('/orcamentos/994')
            ->assertOk()
            ->assertSee('Retirada na assistência')
            ->assertSee('Personalizado por opção de manutenção')
            ->assertSee('Manutenção Completa')
            ->assertSee('Garantia: 1 ano')
            ->assertSee('Com entrega no endereço')
            ->assertSee('Diferenciais: Instalação expressa');
    }

    /**
     * Catálogo de formas/garantia como o backend devolve em form-data.
     *
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'clients' => [],
            'equipments' => [],
            'orders' => [],
            'services' => [],
            'parts' => [],
            'status_options' => [['value' => 'rascunho', 'label' => 'Rascunho']],
            'niveis' => [
                ['value' => 1, 'label' => 'Manutenção Básica', 'subtitle' => 'Volta a funcionar'],
                ['value' => 2, 'label' => 'Manutenção Avançada', 'subtitle' => 'Corrige e previne'],
                ['value' => 3, 'label' => 'Manutenção Completa', 'subtitle' => 'Como novo'],
            ],
            'condicoes_comerciais_catalogo' => [
                'formas_pagamento' => [
                    ['id' => 1, 'codigo' => 'dinheiro', 'nome' => 'Dinheiro', 'is_cartao' => false, 'aceita_parcelamento' => false, 'is_pix' => false],
                    ['id' => 2, 'codigo' => 'pix', 'nome' => 'Pix', 'is_cartao' => false, 'aceita_parcelamento' => false, 'is_pix' => true],
                    ['id' => 3, 'codigo' => 'cartao_credito', 'nome' => 'Cartão de crédito', 'is_cartao' => true, 'aceita_parcelamento' => true, 'is_pix' => false],
                ],
                'chaves_pix' => [],
                'garantia_options' => [
                    ['value' => 90, 'label' => '90 dias'],
                    ['value' => 180, 'label' => '180 dias'],
                    ['value' => 365, 'label' => '1 ano'],
                    ['value' => 730, 'label' => '2 anos'],
                ],
                'max_parcelas_sem_juros' => 24,
            ],
            'default_validity_days' => 10,
        ];
    }

    /**
     * Fakes de form-data + orçamento 993 para a tela de edição.
     *
     * @param  array<string, mixed>  $budgetOverrides
     * @return array<string, mixed>
     */
    private function editFixture(array $budgetOverrides = []): array
    {
        return [
            'http://127.0.0.1:8000/api/v1/orcamentos/form-data*' => Http::response([
                'status' => 'success',
                'data' => ['form' => $this->formData()],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/orcamentos/993' => Http::response([
                'status' => 'success',
                'data' => [
                    'budget' => $this->budgetPayload(array_replace([
                        'id' => 993,
                        'status' => 'rascunho',
                        'status_label' => 'Rascunho',
                    ], $budgetOverrides)),
                ],
                'error' => null,
                'meta' => [],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    /**
     * Snapshot de 3 opções (Fusível em todas, Bateria a partir da Avançada,
     * Película só na Completa), com a Avançada recomendada.
     *
     * @return array<string, mixed>
     */
    private function offeredOptionsPayload(): array
    {
        $item = static fn (int $id, string $descricao, float $valor, array $niveis): array => [
            'id' => $id,
            'tipo_item' => 'servico',
            'referencia_id' => null,
            'descricao' => $descricao,
            'quantidade' => 1.0,
            'valor_unitario' => $valor,
            'desconto' => 0.0,
            'acrescimo' => 0.0,
            'total' => $valor,
            'niveis' => $niveis,
            'observacoes' => '',
        ];
        $terms = static fn (string $garantia): array => [
            'garantia_label' => $garantia,
            'parcelamento_texto' => '',
            'formas_pagamento_texto' => 'Pix',
            'entrega_domicilio' => false,
            'entrega_domicilio_label' => '',
            'beneficios' => [],
        ];
        $level = static fn (int $nivel, string $label, string $subtitle, float $total, array $itens, bool $recomendado, string $garantia) => [
            'nivel' => $nivel,
            'label' => $label,
            'subtitle' => $subtitle,
            'subtotal' => $total,
            'desconto' => 0.0,
            'acrescimo' => 0.0,
            'total' => $total,
            'itens' => array_column($itens, 'descricao'),
            'itens_count' => count($itens),
            'recomendado' => $recomendado,
            'itens_detalhe' => $itens,
            'condicoes_comerciais' => $terms($garantia),
        ];
        $fusivel = $item(1, 'Fusível', 100.0, [1, 2, 3]);
        $bateria = $item(2, 'Bateria', 200.0, [2, 3]);
        $pelicula = $item(3, 'Película e limpeza', 300.0, [3]);

        return [
            'origem' => 'aprovacao',
            'layout' => [
                'garantia' => ['modo' => 'por_opcao', 'valor' => ''],
                'formas_pagamento' => ['modo' => 'compartilhado', 'valor' => 'Pix'],
                'parcelamento' => ['modo' => 'compartilhado', 'valor' => ''],
                'entrega_domicilio' => ['modo' => 'compartilhado', 'valor' => ''],
            ],
            'aprovacao' => [
                'id' => 7,
                'created_at' => '22/09/2026 00:59',
                'origem' => 'link_publico',
                'origem_label' => 'pelo link público',
                'usuario_nome' => 'Cliente',
                'nivel' => 2,
                'nivel_label' => 'Manutenção Avançada',
                'vigente' => true,
            ],
            'niveis' => [
                $level(1, 'Manutenção Básica', 'Volta a funcionar', 100.0, [$fusivel], false, '90 dias'),
                $level(2, 'Manutenção Avançada', 'Corrige e previne', 300.0, [$fusivel, $bateria], true, '1 ano'),
                $level(3, 'Manutenção Completa', 'Como novo', 600.0, [$fusivel, $bateria, $pelicula], false, '2 anos'),
            ],
        ];
    }

    private function budgetPayload(array $overrides = []): array
    {
        return array_replace([
            'id' => 990,
            'numero' => 'ORC-2609-000001',
            'versao' => 1,
            'tipo_orcamento' => 'previo',
            'tipo_label' => 'Orçamento prévio',
            'status' => 'aguardando_resposta',
            'status_label' => 'Aguardando resposta',
            'status_color' => '#2563eb',
            'origem' => 'manual',
            'origem_label' => 'Manual',
            'titulo' => '',
            'cliente_nome_avulso' => '',
            'telefone_contato' => '22992741003',
            'email_contato' => '',
            'validade_dias' => 10,
            'validade_data' => '25/09/2026',
            'numero_os' => '',
            'prazo_execucao' => '3 dias',
            'observacoes' => '',
            'condicoes' => '',
            'subtotal' => 600.0,
            'desconto' => 0.0,
            'acrescimo' => 0.0,
            'total' => 600.0,
            'total_formatado' => '600,00',
            'has_tiers' => false,
            'nivel_maximo' => 1,
            'niveis' => [],
            'nivel_recomendado' => null,
            'nivel_aprovado' => null,
            'nivel_aprovado_label' => '',
            'cliente' => ['id' => 5, 'nome_razao' => 'Cliente Níveis', 'cpf_cnpj' => ''],
            'equipamento' => null,
            'os' => null,
            'responsavel' => ['id' => 1, 'nome' => 'Assistência Técnica'],
            'itens' => [],
            'historico' => [],
            'envios' => [],
            'aprovacoes' => [],
            'can_edit' => true,
            'can_delete' => false,
            'can_send_approval' => true,
            'can_approve' => true,
            'can_reject' => true,
            'can_cancel' => true,
            'link_publico' => 'http://127.0.0.1:8000/orcamento/token-abc',
            'created_at' => '15/09/2026 10:00',
            'updated_at' => '15/09/2026 10:00',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function itemPayload(array $overrides = []): array
    {
        return array_replace([
            'id' => 1,
            'tipo_item' => 'servico',
            'referencia_id' => null,
            'descricao' => 'Fusível',
            'quantidade' => 1.0,
            'valor_unitario' => 100.0,
            'desconto' => 0.0,
            'desconto_tipo' => 'valor',
            'desconto_percentual' => null,
            'acrescimo' => 0.0,
            'acrescimo_tipo' => 'valor',
            'acrescimo_percentual' => null,
            'total' => 100.0,
            'niveis' => [1],
            'observacoes' => '',
            'valor_recomendado' => 0.0,
            'modo_precificacao' => 'manual',
            'disponibilidade' => null,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function companyFixture(): array
    {
        return [
            'http://127.0.0.1:8000/api/v1/configuracoes/empresa*' => Http::response([
                'status' => 'success',
                'data' => ['settings' => ['empresa_nome_fantasia' => 'Sistema ERP'], 'logo' => ['exists' => false]],
                'error' => null,
                'meta' => [],
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationsFixture(): array
    {
        return [
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
                'status' => 'success',
                'data' => ['items' => [], 'unread_count' => 0],
                'error' => null,
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 6, 'total' => 0, 'last_page' => 1, 'from' => 0, 'to' => 0]],
            ]),
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
                'user' => [
                    'id' => 99,
                    'nome' => 'Usuário de Teste',
                    'email' => 'usuario@teste.local',
                    'perfil' => 'admin',
                    'group' => ['id' => 1, 'nome' => 'Administrador', 'descricao' => 'Grupo completo', 'sistema' => true],
                    'modules' => array_keys($permissions),
                    'permissions' => $permissions,
                    'foto' => '',
                    'ativo' => true,
                ],
            ],
        ];
    }
}
