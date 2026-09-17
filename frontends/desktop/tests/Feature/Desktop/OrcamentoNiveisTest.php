<?php

namespace Tests\Feature\Desktop;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Orçamento em níveis de manutenção (Básica / Avançada / Completa) no
 * desktop: o técnico marca o nível em cada item, o resumo mostra as opções,
 * a tela de detalhe expõe a opção aprovada e a aprovação "por outros meios"
 * informa qual opção o cliente escolheu.
 */
class OrcamentoNiveisTest extends TestCase
{
    public function test_create_form_renders_level_select_per_item_and_levels_summary(): void
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
            ->assertSee('name="itens[0][nivel_minimo]"', false)
            ->assertSee('data-budget-item-level', false)
            ->assertSee('data-budget-levels-summary', false)
            ->assertSee('name="nivel_recomendado"', false)
            ->assertSee('data-budget-level-card="3"', false)
            ->assertSee('Manutenção Completa')
            ->assertSee('Opções de manutenção');
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
                'nivel_recomendado' => 2,
                'subtotal' => 'R$ 300,00',
                'desconto' => 'R$ 0,00',
                'acrescimo' => 'R$ 0,00',
                'total' => 'R$ 300,00',
                'itens' => [
                    ['tipo_item' => 'servico', 'descricao' => 'Fusível', 'quantidade' => 1, 'valor_unitario' => 'R$ 100,00', 'nivel_minimo' => 1],
                    ['tipo_item' => 'servico', 'descricao' => 'Bateria', 'quantidade' => 1, 'valor_unitario' => 'R$ 200,00', 'nivel_minimo' => 2],
                ],
            ]);

        $response->assertRedirect(route('orcamentos.show', 951));

        Http::assertSent(static function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/api/v1/orcamentos'
                && (int) ($request['nivel_recomendado'] ?? 0) === 2
                && (int) ($request['itens'][0]['nivel_minimo'] ?? 0) === 1
                && (int) ($request['itens'][1]['nivel_minimo'] ?? 0) === 2;
        });
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
                    ['tipo_item' => 'servico', 'descricao' => 'Fusível', 'quantidade' => 1, 'valor_unitario' => 'R$ 100,00', 'nivel_minimo' => 7],
                ],
            ]);

        $response->assertRedirect('/orcamentos/novo')->assertSessionHasErrors('itens.0.nivel_minimo');
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
                        $this->itemPayload(['descricao' => 'Fusível', 'nivel_minimo' => 1]),
                        $this->itemPayload(['descricao' => 'Bateria', 'nivel_minimo' => 2, 'valor_unitario' => 200.0, 'total' => 200.0]),
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
            ->assertSee('<th>Nível</th>', false)
            ->assertSee('data-confirm-input-label="Opção escolhida pelo cliente"', false)
            ->assertSee('name="nivel" value="2" data-confirm-value', false);
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
            ->assertDontSee('<th>Nível</th>', false)
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
            ->assertSee('Opção aprovada: Manutenção Avançada');
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
                            $this->itemPayload(['id' => 1, 'descricao' => 'Fusível', 'nivel_minimo' => 1]),
                            $this->itemPayload(['id' => 2, 'descricao' => 'Bateria', 'nivel_minimo' => 3]),
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
        $this->assertSame(0, substr_count((string) $response->getContent(), '<option value="1" selected>Sim, incluir entrega</option>'));
        $this->assertSame(1, substr_count((string) $response->getContent(), '<option value="0" selected>Não incluir entrega</option>'));
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
                    ['tipo_item' => 'servico', 'descricao' => 'Fusível', 'quantidade' => 1, 'valor_unitario' => 'R$ 100,00', 'nivel_minimo' => 1],
                    ['tipo_item' => 'servico', 'descricao' => 'Bateria', 'quantidade' => 1, 'valor_unitario' => 'R$ 200,00', 'nivel_minimo' => 3],
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
                ['tipo_item' => 'servico', 'descricao' => 'Fusível', 'quantidade' => 1, 'valor_unitario' => 'R$ 100,00', 'nivel_minimo' => 1],
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
                            $this->itemPayload(['id' => 1, 'descricao' => 'Fusível', 'nivel_minimo' => 1]),
                            $this->itemPayload(['id' => 2, 'descricao' => 'Bateria', 'nivel_minimo' => 2]),
                            $this->itemPayload(['id' => 3, 'descricao' => 'Película', 'nivel_minimo' => 3]),
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
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
            'nivel_minimo' => 1,
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
