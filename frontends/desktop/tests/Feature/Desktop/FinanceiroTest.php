<?php

namespace Tests\Feature\Desktop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FinanceiroTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_page_renders_avulso_control(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response([
                'status' => 'success',
                'data' => ['categorias' => []],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar']]))
            ->get('/financeiro/novo');

        $response->assertOk()
            ->assertSee('Lançamento avulso')
            ->assertSee('financeiroAvulso', false);
    }

    /**
     * A tabela precisa dizer QUANDO cada título foi liquidado — a despesa paga
     * e a receita recebida. Enquanto pendente a coluna fica vazia:
     * financeiro.data_pagamento é derivada dos movimentos e só existe depois da
     * baixa.
     */
    public function test_index_page_shows_the_settlement_date_column(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro*' => Http::response([
                'status' => 'success',
                'data' => [
                    'lancamentos' => [
                        [
                            'id' => 1,
                            'tipo' => 'pagar',
                            'categoria' => 'Energia',
                            'valor' => 100.0,
                            'status' => 'pago',
                            'data_vencimento' => '2026-08-15',
                            'data_pagamento' => '2026-08-14',
                        ],
                        [
                            'id' => 2,
                            'tipo' => 'receber',
                            'categoria' => 'Serviço',
                            'valor' => 80.0,
                            'status' => 'pago',
                            'data_vencimento' => '2026-08-15',
                            'data_pagamento' => '2026-08-16',
                        ],
                        [
                            'id' => 3,
                            'tipo' => 'pagar',
                            'categoria' => 'Aluguel',
                            'valor' => 500.0,
                            'status' => 'pendente',
                            'data_vencimento' => '2026-09-05',
                            'data_pagamento' => null,
                        ],
                    ],
                    'status_options' => [],
                    'totais_despesas' => ['fixas' => 600.0, 'variaveis' => 0.0],
                ],
                'error' => null,
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 15, 'total' => 3, 'last_page' => 1, 'from' => 1, 'to' => 3]],
            ], 200),
        ]);

        $content = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar']]))
            ->get('/financeiro')
            ->assertOk()
            ->assertSee('Baixa')
            // Despesa paga e receita recebida, cada uma com sua data.
            ->assertSee('14/08/2026')
            ->assertSee('16/08/2026')
            ->getContent();

        // O pendente não inventa data: a célula fica com o travessão.
        $this->assertStringContainsString('<td data-label="Baixa">', $content);
        $this->assertStringContainsString('—', $content);
    }

    /**
     * O modal de baixa precisa declarar o tipo do PRÓPRIO título. O loop dos
     * modais não redeclarava $tipo, então herdava o da última linha da página:
     * numa lista terminada em "a receber", uma conta a pagar passava a
     * oferecer os campos de operadora/taxa da maquininha — e preencher isso
     * cria uma despesa de taxa que não existe (ver financeiro-pay.js).
     */
    public function test_pay_modal_declares_the_type_of_its_own_entry(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response([
                'status' => 'success',
                'data' => [
                    'categorias' => [],
                    // O select de conta só aparece com conta ativa — é ele que
                    // carrega o texto de entrada/saída sob teste.
                    'contas_financeiras' => [
                        'contas' => [
                            ['id' => 1, 'nome' => 'Inter', 'ativo' => true, 'considera_disponivel' => true],
                        ],
                        'contas_padrao' => [],
                        'tipos' => [],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/financeiro*' => Http::response([
                'status' => 'success',
                'data' => [
                    'lancamentos' => [
                        [
                            'id' => 41,
                            'tipo' => 'pagar',
                            'categoria' => 'Energia',
                            'valor' => 100.0,
                            'status' => 'pendente',
                            'data_vencimento' => '2026-09-10',
                        ],
                        // Última da lista é "a receber": é o valor que vazava
                        // para o modal do título acima.
                        [
                            'id' => 42,
                            'tipo' => 'receber',
                            'categoria' => 'Serviço',
                            'valor' => 80.0,
                            'status' => 'pendente',
                            'data_vencimento' => '2026-09-11',
                        ],
                    ],
                    'status_options' => [],
                    'totais_despesas' => ['fixas' => 100.0, 'variaveis' => 0.0],
                ],
                'error' => null,
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 15, 'total' => 2, 'last_page' => 1, 'from' => 1, 'to' => 2]],
            ], 200),
        ]);

        $content = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'editar']]))
            ->get('/financeiro')
            ->assertOk()
            ->getContent();

        $modalPagar = $this->trechoDoModal($content, 41);
        $modalReceber = $this->trechoDoModal($content, 42);

        $this->assertStringContainsString('data-tipo="pagar"', $modalPagar);
        $this->assertStringContainsString('data-tipo="receber"', $modalReceber);

        // E o texto da conta acompanha a direção de cada um.
        $this->assertStringContainsString('Define de qual conta o dinheiro sai.', $modalPagar);
        $this->assertStringContainsString('Define em qual conta o dinheiro entra.', $modalReceber);
    }

    /**
     * Sem data-tipo o financeiro-pay.js assume "receber" — a tela de detalhe
     * de uma conta a pagar oferecia os campos de maquininha.
     */
    public function test_detail_pay_form_declares_the_entry_type(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response([
                'status' => 'success',
                'data' => [
                    'categorias' => [],
                    // O select de conta só aparece com conta ativa — é ele que
                    // carrega o texto de entrada/saída sob teste.
                    'contas_financeiras' => [
                        'contas' => [
                            ['id' => 1, 'nome' => 'Inter', 'ativo' => true, 'considera_disponivel' => true],
                        ],
                        'contas_padrao' => [],
                        'tipos' => [],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/financeiro/55' => Http::response([
                'status' => 'success',
                'data' => [
                    'lancamento' => [
                        'id' => 55,
                        'tipo' => 'pagar',
                        'categoria' => 'Energia',
                        'descricao' => 'Conta de luz',
                        'valor' => 100.0,
                        'status' => 'pendente',
                        'data_vencimento' => '2026-09-10',
                    ],
                    'resumo' => ['total_movimentos' => 0, 'valor_aberto' => 100.0],
                    'detalhes' => [],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $this->withSession($this->desktopSession(['financeiro' => ['visualizar', 'editar']]))
            ->get('/financeiro/55')
            ->assertOk()
            ->assertSee('data-tipo="pagar"', false)
            ->assertSee('Define de qual conta o dinheiro sai.', false);
    }

    /** Recorta o HTML de um modal de baixa para asserções por título. */
    private function trechoDoModal(string $html, int $id): string
    {
        $inicio = strpos($html, 'id="payModal'.$id.'"');
        $this->assertNotFalse($inicio, "Modal do lançamento {$id} não foi renderizado.");

        $fim = strpos($html, 'id="payModal', $inicio + 1);

        return $fim === false ? substr($html, $inicio) : substr($html, $inicio, $fim - $inicio);
    }

    public function test_index_page_renders_lancamentos_from_api(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro*' => Http::response([
                'status' => 'success',
                'data' => [
                    'lancamentos' => [
                        [
                            'id' => 1,
                            'tipo' => 'receber',
                            'categoria' => 'Serviço',
                            'valor' => 150.0,
                            'status' => 'pendente',
                            'data_vencimento' => now()->addDays(5)->toDateString(),
                            'grupo_dre' => 'Receita Operacional',
                            'subgrupo_dre' => 'Serviços e peças de OS',
                        ],
                    ],
                    'status_options' => [
                        ['value' => 'pendente', 'label' => 'Pendente'],
                        ['value' => 'pago', 'label' => 'Pago'],
                    ],
                ],
                'error' => null,
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 15, 'total' => 1, 'last_page' => 1, 'from' => 1, 'to' => 1]],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar', 'editar', 'excluir']]))
            ->get('/financeiro');

        $response->assertOk()
            ->assertSee('Financeiro')
            ->assertSee('Serviço')
            ->assertSee('Novo lançamento')
            ->assertSee('Relatórios')
            ->assertSee(route('financeiro.relatorios.fluxo-caixa'), false)
            ->assertSee(route('financeiro.relatorios.dre'), false)
            ->assertSee(route('financeiro.relatorios.dre-caixa'), false)
            ->assertSee(route('financeiro.relatorios.margem'), false)
            ->assertSee('Mais ações')
            ->assertSee(route('financeiro.despesas-fixas.index'), false)
            ->assertSee(route('financeiro.cartoes.index'), false)
            ->assertSee(route('financeiro.configuracoes'), false)
            ->assertSee('Despesas fixas')
            ->assertSee('Mês (vencimento)')
            // Sessão sem permissão de "precificacao" — item some do dropdown.
            ->assertDontSee(route('financeiro.precificacao.index'), false);
    }

    /**
     * O recibo de pagamento de fatura (gerado pela baixa em lote — ver
     * FinanceiroCartaoCreditoService::payInvoice() no backend) é a "conta" que
     * agrupa despesas fixas e variáveis. Precisa aparecer como linha própria
     * aqui, reaproveitando o mesmo template das demais, com a badge do cartão
     * linkando de volta para a fatura de origem.
     */
    public function test_index_page_renders_the_invoice_payment_receipt_row(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro*' => Http::response([
                'status' => 'success',
                'data' => [
                    'lancamentos' => [
                        [
                            'id' => 90,
                            'tipo' => 'pagar',
                            'categoria' => 'Fatura de cartão de crédito',
                            'descricao' => 'Pagamento da fatura Inter — venc. 25/08/2026 (2 despesas)',
                            'valor' => 75.0,
                            'status' => 'pago',
                            'data_vencimento' => '2026-08-25',
                            'origem_tipo' => 'fatura_cartao_credito',
                            'origem_trilha' => ['Pagamento de fatura em lote'],
                            // Modalidade NULL: não é compra no cartão, é o
                            // pagamento da fatura — por isso não recebe as
                            // travas de "gerencie pela fatura".
                            'cartao_modalidade' => null,
                            'cartao_credito' => ['id' => 7, 'nome' => 'Inter'],
                        ],
                    ],
                    'status_options' => [],
                    'totais_despesas' => ['fixas' => 25.0, 'variaveis' => 50.0],
                ],
                'error' => null,
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 15, 'total' => 1, 'last_page' => 1, 'from' => 1, 'to' => 1]],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar', 'criar', 'editar', 'excluir'],
                'contas_saldos' => ['visualizar'],
            ]))
            ->get('/financeiro');

        $response->assertOk()
            ->assertSee('Fatura de cartão de crédito')
            ->assertSee('Pagamento de fatura em lote')
            ->assertSee('Inter')
            // Badge do cartão linka para a fatura que este recibo pagou.
            ->assertSee(route('financeiro.cartoes-credito.faturas.show', [
                'cartaoCredito' => 7,
                'dataVencimento' => '2026-08-25',
            ]), false)
            // Os totais continuam vindo do backend sem o valor do recibo
            // somado (as despesas que ele resume já entram individualmente).
            ->assertSee('R$ 25,00')
            ->assertSee('R$ 50,00');
    }

    public function test_index_page_shows_precificacao_in_mais_acoes_when_permitted(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro*' => Http::response([
                'status' => 'success',
                'data' => [
                    'lancamentos' => [],
                    'status_options' => [],
                ],
                'error' => null,
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1, 'from' => 0, 'to' => 0]],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar'],
                'precificacao' => ['visualizar'],
            ]))
            ->get('/financeiro');

        $response->assertOk()
            ->assertSee(route('financeiro.precificacao.index'), false);
    }

    public function test_despesas_page_forces_pagar_but_shows_both_fixed_and_variable(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response([
                'status' => 'success',
                'data' => [
                    'categorias' => [],
                    'cartao' => ['operadoras' => [], 'bandeiras' => [], 'taxas' => []],
                    'contas_financeiras' => ['contas' => [], 'contas_padrao' => [], 'tipos' => []],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/financeiro*' => Http::response([
                'status' => 'success',
                'data' => [
                    'lancamentos' => [
                        [
                            'id' => 10,
                            'tipo' => 'pagar',
                            'categoria' => 'Internet',
                            'valor' => 120.0,
                            'status' => 'pendente',
                            'data_vencimento' => now()->toDateString(),
                            'dre_fixo_mensal' => true,
                        ],
                        [
                            'id' => 11,
                            'tipo' => 'pagar',
                            'categoria' => 'Compra de embalagens',
                            'valor' => 45.0,
                            'status' => 'pendente',
                            'data_vencimento' => now()->toDateString(),
                            'dre_fixo_mensal' => false,
                        ],
                    ],
                    'status_options' => [
                        ['value' => 'pendente', 'label' => 'Pendente'],
                    ],
                    'totais_despesas' => ['fixas' => 120.0, 'variaveis' => 45.0],
                ],
                'error' => null,
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 15, 'total' => 2, 'last_page' => 1, 'from' => 1, 'to' => 2]],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar', 'editar', 'excluir']]))
            ->get('/financeiro/despesas-fixas');

        $response->assertOk()
            ->assertSee('Despesas')
            ->assertSee('Internet')
            ->assertSee('Compra de embalagens')
            ->assertSee('Total despesas fixas')
            ->assertSee('Total despesas variáveis')
            // A tela agora expõe o filtro "Tipo de despesa" (fixa/variável) —
            // não é mais forçado no servidor como antes.
            ->assertSee('Só variáveis')
            ->assertSee('Nova despesa')
            ->assertSee(route('financeiro.create', ['tipo' => 'pagar']), false);

        Http::assertSent(static function ($request): bool {
            if (! str_starts_with($request->url(), 'http://127.0.0.1:8000/api/v1/financeiro?')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['tipo'] ?? null) === 'pagar'
                && ! array_key_exists('dre_fixo_mensal', $query);
        });
    }

    public function test_despesas_page_dre_fixo_mensal_filter_disables_default_view(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response([
                'status' => 'success',
                'data' => ['categorias' => [], 'cartao' => ['operadoras' => [], 'bandeiras' => [], 'taxas' => []], 'contas_financeiras' => ['contas' => [], 'contas_padrao' => [], 'tipos' => []]],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/financeiro*' => Http::response([
                'status' => 'success',
                'data' => ['lancamentos' => [], 'status_options' => [], 'totais_despesas' => ['fixas' => 0.0, 'variaveis' => 0.0]],
                'error' => null,
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1, 'from' => 0, 'to' => 0]],
            ], 200),
        ]);

        $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar', 'editar', 'excluir']]))
            ->get('/financeiro/despesas-fixas?dre_fixo_mensal=1');

        Http::assertSent(static function ($request): bool {
            if (! str_starts_with($request->url(), 'http://127.0.0.1:8000/api/v1/financeiro?')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['dre_fixo_mensal'] ?? null) === '1'
                && ! array_key_exists('periodo_atual_e_atrasadas', $query);
        });
    }

    public function test_create_with_tipo_pagar_query_locks_tipo_to_pagar(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response([
                'status' => 'success',
                'data' => ['categorias' => []],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar']]))
            ->get('/financeiro/novo?tipo=pagar');

        $response->assertOk()
            ->assertSee('Nova despesa')
            // "A receber" não deve nem existir como opção quando travado.
            ->assertDontSee('A receber')
            ->assertSee('name="tipo" value="pagar"', false);
    }

    public function test_create_without_tipo_query_still_allows_receber(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response([
                'status' => 'success',
                'data' => ['categorias' => []],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar']]))
            ->get('/financeiro/novo');

        $response->assertOk()
            ->assertSee('Novo lançamento')
            ->assertSee('A receber')
            ->assertSee('A pagar');
    }

    public function test_despesas_fixas_page_applies_periodo_atual_e_atrasadas_by_default(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response([
                'status' => 'success',
                'data' => ['categorias' => [], 'cartao' => ['operadoras' => [], 'bandeiras' => [], 'taxas' => []]],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/financeiro*' => Http::response([
                'status' => 'success',
                'data' => ['lancamentos' => [], 'status_options' => [], 'totais_despesas' => ['fixas' => 0.0, 'variaveis' => 0.0]],
                'error' => null,
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1, 'from' => 0, 'to' => 0]],
            ], 200),
        ]);

        // Sem mês/status na URL: deve ativar a visão padrão (mês atual +
        // atrasadas), sem esperar o usuário escolher nada.
        $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar', 'editar', 'excluir']]))
            ->get('/financeiro/despesas-fixas');

        Http::assertSent(static function ($request): bool {
            if (! str_starts_with($request->url(), 'http://127.0.0.1:8000/api/v1/financeiro?')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['periodo_atual_e_atrasadas'] ?? null) === '1';
        });
    }

    public function test_despesas_fixas_page_disables_default_view_once_mes_is_chosen(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response([
                'status' => 'success',
                'data' => ['categorias' => [], 'cartao' => ['operadoras' => [], 'bandeiras' => [], 'taxas' => []]],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/financeiro*' => Http::response([
                'status' => 'success',
                'data' => ['lancamentos' => [], 'status_options' => [], 'totais_despesas' => ['fixas' => 0.0, 'variaveis' => 0.0]],
                'error' => null,
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1, 'from' => 0, 'to' => 0]],
            ], 200),
        ]);

        // Usuário escolheu um mês explicitamente: a visão "inteligente"
        // padrão sai de cena, filtro passa a valer literalmente.
        $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar', 'editar', 'excluir']]))
            ->get('/financeiro/despesas-fixas?mes=2026-12');

        Http::assertSent(static function ($request): bool {
            if (! str_starts_with($request->url(), 'http://127.0.0.1:8000/api/v1/financeiro?')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ! array_key_exists('periodo_atual_e_atrasadas', $query)
                && ($query['mes'] ?? null) === '2026-12';
        });
    }

    public function test_show_page_groups_actions_in_mais_acoes_dropdown(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response([
                'status' => 'success',
                'data' => ['categorias' => [], 'cartao' => ['operadoras' => [], 'bandeiras' => [], 'taxas' => []]],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/financeiro/63' => Http::response([
                'status' => 'success',
                'data' => [
                    'lancamento' => [
                        'id' => 63,
                        'tipo' => 'receber',
                        'categoria' => 'Serviço',
                        'descricao' => 'Cobrança da OS OS26070014',
                        'valor' => 80.0,
                        'status' => 'pendente',
                        'data_vencimento' => '2026-07-14',
                        'avulso' => false,
                    ],
                    'resumo' => ['valor_movimentado' => 0, 'valor_aberto' => 80.0, 'percentual_quitado' => 0, 'total_movimentos' => 0],
                    'detalhes' => [
                        'contraparte' => ['tipo' => 'cliente', 'id' => 396, 'titulo' => 'Quem pagou', 'nome' => 'Deborah Evelyn Rosa'],
                        'origem' => ['titulo' => 'Ordem de serviço', 'descricao' => 'Lançamento vinculado ao fluxo financeiro de uma OS.'],
                        'os' => [
                            'id' => 3626,
                            'numero_os' => 'OS26070014',
                            'status' => 'entregue_reparado',
                            'status_nome' => 'Equipamento Entregue',
                            'datas' => [],
                            'valores' => [],
                            'cliente' => ['id' => 396, 'nome' => 'Deborah Evelyn Rosa'],
                            'equipamento' => [],
                            'defeito' => [],
                            'orcamento' => ['id' => 8, 'numero' => 'ORC-2607-000008', 'status' => 'aprovado'],
                        ],
                        'movimentos' => [],
                        'impactos' => [],
                        'auditoria' => [],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar', 'criar', 'editar', 'excluir'],
                'os' => ['visualizar'],
                'orcamentos' => ['visualizar'],
                'clientes' => ['visualizar'],
            ]))
            ->get('/financeiro/63');

        $response->assertOk()
            ->assertSee('Mais ações')
            ->assertSee('Ver lançamentos')
            ->assertSee(route('financeiro.index'), false)
            ->assertSee('Novo lançamento')
            ->assertSee(route('financeiro.create'), false)
            ->assertSee('Editar lançamento')
            ->assertSee('Registrar baixa')
            ->assertSee('Ver OS vinculada')
            ->assertSee(route('orders.show', 3626), false)
            ->assertSee('Ver orçamento vinculado')
            ->assertSee(route('orcamentos.show', 8), false)
            ->assertSee('Ver cliente')
            ->assertSee(route('clients.show', 396), false)
            ->assertSee('Cancelar lançamento')
            ->assertSee('Excluir lançamento')
            ->assertSee('payModal63', false)
            ->assertSee('voltar_para', false);
    }

    public function test_show_page_hides_linked_actions_without_permissions(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/64' => Http::response([
                'status' => 'success',
                'data' => [
                    'lancamento' => [
                        'id' => 64,
                        'tipo' => 'receber',
                        'categoria' => 'Serviço',
                        'descricao' => 'Lançamento pago',
                        'valor' => 50.0,
                        'status' => 'pago',
                        'avulso' => true,
                    ],
                    'resumo' => ['valor_movimentado' => 50.0, 'valor_aberto' => 0, 'percentual_quitado' => 100, 'total_movimentos' => 1],
                    'detalhes' => [
                        'contraparte' => ['tipo' => 'cliente', 'id' => 396, 'nome' => 'Deborah'],
                        'origem' => [],
                        'os' => null,
                        'movimentos' => [],
                        'impactos' => [],
                        'auditoria' => [],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar']]))
            ->get('/financeiro/64');

        $response->assertOk()
            // "Mais ações" sempre aparece (com "Ver lançamentos", que só exige
            // financeiro,visualizar). Sem permissão de criar/editar/excluir e
            // sem OS/orçamento/cliente vinculado, nenhuma outra ação aparece.
            ->assertSee('Mais ações')
            ->assertSee('Ver lançamentos')
            ->assertDontSee('Novo lançamento')
            ->assertDontSee('Editar lançamento')
            ->assertDontSee('Registrar baixa')
            ->assertDontSee('Ver OS vinculada')
            ->assertDontSee('Ver orçamento vinculado')
            ->assertDontSee('Ver cliente')
            ->assertDontSee('Cancelar lançamento')
            ->assertDontSee('Excluir lançamento');
    }

    public function test_store_redirects_to_index_on_success(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro' => Http::response([
                'status' => 'success',
                'data' => ['lancamento' => ['id' => 5, 'tipo' => 'receber', 'status' => 'pendente']],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar', 'editar', 'excluir']]))
            ->post('/financeiro', [
                'tipo' => 'receber',
                'categoria' => 'Serviço',
                'descricao' => 'Serviço de teste',
                'cliente_id' => 1,
                'avulso' => '1',
                'valor' => 150.0,
                'data_vencimento' => now()->addDays(5)->toDateString(),
            ]);

        $response->assertRedirect(route('financeiro.index'));
        Http::assertSent(static function ($request) {
            return $request->url() === 'http://127.0.0.1:8000/api/v1/financeiro'
                && $request->method() === 'POST'
                && $request['avulso'] === true;
        });
    }

    public function test_store_sends_dre_fixo_mensal_when_tipo_is_pagar(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro' => Http::response([
                'status' => 'success',
                'data' => ['lancamento' => ['id' => 6, 'tipo' => 'pagar', 'status' => 'pendente']],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar', 'editar', 'excluir']]))
            ->post('/financeiro', [
                'tipo' => 'pagar',
                'categoria' => 'Internet',
                'descricao' => 'Internet do mês',
                'valor' => 120.0,
                'data_vencimento' => now()->toDateString(),
                'dre_fixo_mensal' => '1',
            ]);

        $response->assertRedirect(route('financeiro.index'));
        Http::assertSent(static function ($request) {
            return $request->url() === 'http://127.0.0.1:8000/api/v1/financeiro'
                && $request->method() === 'POST'
                && $request['dre_fixo_mensal'] === true;
        });
    }

    public function test_store_sends_repetir_proximos_meses_and_adjusts_success_message(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro' => Http::response([
                'status' => 'success',
                'data' => ['lancamento' => ['id' => 8, 'tipo' => 'pagar', 'status' => 'pendente']],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar', 'editar', 'excluir']]))
            ->post('/financeiro', [
                'tipo' => 'pagar',
                'categoria' => 'Internet',
                'descricao' => 'Internet do mês',
                'valor' => 120.0,
                'data_vencimento' => now()->toDateString(),
                'dre_fixo_mensal' => '1',
                'repetir_proximos_meses' => '1',
            ]);

        $response->assertRedirect(route('financeiro.index'));
        $response->assertSessionHas('success', 'Lançamento criado com sucesso, com mais 11 lançamentos futuros gerados (um por mês, pendentes).');
        Http::assertSent(static function ($request) {
            return $request->url() === 'http://127.0.0.1:8000/api/v1/financeiro'
                && $request->method() === 'POST'
                && $request['repetir_proximos_meses'] === true;
        });
    }

    public function test_update_omits_repetir_proximos_meses_even_when_sent(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/9' => Http::response([
                'status' => 'success',
                'data' => ['lancamento' => ['id' => 9, 'tipo' => 'pagar', 'status' => 'pendente']],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar', 'editar', 'excluir']]))
            ->put('/financeiro/9', [
                'tipo' => 'pagar',
                'categoria' => 'Internet',
                'descricao' => 'Internet do mês',
                'valor' => 120.0,
                'data_vencimento' => now()->toDateString(),
                'dre_fixo_mensal' => '1',
                'repetir_proximos_meses' => '1',
            ]);

        $response->assertRedirect(route('financeiro.index'));
        Http::assertSent(static function ($request) {
            return $request->url() === 'http://127.0.0.1:8000/api/v1/financeiro/9'
                && ! array_key_exists('repetir_proximos_meses', $request->data());
        });
    }

    public function test_create_page_renders_repetir_checkbox_but_edit_page_does_not(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response([
                'status' => 'success',
                'data' => ['categorias' => [], 'contas_financeiras' => ['contas' => [], 'contas_padrao' => [], 'tipos' => []], 'formas_pagamento' => []],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/financeiro/11' => Http::response([
                'status' => 'success',
                'data' => [
                    'lancamento' => [
                        'id' => 11,
                        'tipo' => 'pagar',
                        'categoria' => 'Internet',
                        'descricao' => 'Internet do mês',
                        'valor' => 120.0,
                        'status' => 'pendente',
                        'data_vencimento' => now()->toDateString(),
                        'dre_fixo_mensal' => true,
                    ],
                    'resumo' => ['total_movimentos' => 0],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $createResponse = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar', 'editar']]))
            ->get('/financeiro/novo');

        $createResponse->assertOk()->assertSee('financeiroRepetirMeses', false);

        $editResponse = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar', 'editar']]))
            ->get('/financeiro/11/editar');

        $editResponse->assertOk()->assertDontSee('financeiroRepetirMeses', false);
    }

    public function test_create_page_hides_os_and_cliente_for_generic_pagar_category(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response([
                'status' => 'success',
                'data' => [
                    'categorias' => [
                        ['id' => 1, 'nome' => 'Energia', 'tipo' => 'pagar', 'dre_grupo' => ['id' => 3, 'nome' => 'Despesas Operacionais']],
                        ['id' => 2, 'nome' => 'Compra de peças', 'tipo' => 'pagar', 'dre_grupo' => ['id' => 4, 'nome' => 'Custo Direto (OS)']],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession(['financeiro' => ['visualizar', 'criar']]),
                ['_old_input' => ['tipo' => 'pagar', 'categoria' => 'Energia']]
            ))
            ->get('/financeiro/novo');

        $response->assertOk();
        $this->assertMatchesRegularExpression('/id="financeiroOsWrapper"\s+class="d-none"/', $response->getContent());
        $this->assertMatchesRegularExpression('/id="financeiroClienteWrapper"\s+class="d-none"/', $response->getContent());
    }

    public function test_create_page_shows_os_and_cliente_for_peca_pagar_category(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response([
                'status' => 'success',
                'data' => [
                    'categorias' => [
                        ['id' => 1, 'nome' => 'Energia', 'tipo' => 'pagar', 'dre_grupo' => ['id' => 3, 'nome' => 'Despesas Operacionais']],
                        ['id' => 2, 'nome' => 'Compra de peças', 'tipo' => 'pagar', 'dre_grupo' => ['id' => 4, 'nome' => 'Custo Direto (OS)']],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession(['financeiro' => ['visualizar', 'criar']]),
                ['_old_input' => ['tipo' => 'pagar', 'categoria' => 'Compra de peças']]
            ))
            ->get('/financeiro/novo');

        $response->assertOk();
        $this->assertDoesNotMatchRegularExpression('/id="financeiroOsWrapper"\s+class="d-none"/', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/id="financeiroClienteWrapper"\s+class="d-none"/', $response->getContent());
    }

    public function test_create_page_hides_vinculos_section_when_despesa_fixa_chosen(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response([
                'status' => 'success',
                'data' => ['categorias' => []],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession(['financeiro' => ['visualizar', 'criar']]),
                ['_old_input' => ['tipo' => 'pagar', 'dre_fixo_mensal' => '1']]
            ))
            ->get('/financeiro/novo');

        $response->assertOk();
        $this->assertMatchesRegularExpression('/id="financeiroVinculosSection"\s+class="d-none"/', $response->getContent());
    }

    public function test_create_page_shows_vinculos_section_when_despesa_variavel_chosen(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response([
                'status' => 'success',
                'data' => ['categorias' => []],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession(['financeiro' => ['visualizar', 'criar']]),
                ['_old_input' => ['tipo' => 'pagar', 'dre_fixo_mensal' => '0']]
            ))
            ->get('/financeiro/novo');

        $response->assertOk();
        $this->assertDoesNotMatchRegularExpression('/id="financeiroVinculosSection"\s+class="d-none"/', $response->getContent());
    }

    public function test_store_omits_dre_fixo_mensal_when_tipo_is_receber(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro' => Http::response([
                'status' => 'success',
                'data' => ['lancamento' => ['id' => 7, 'tipo' => 'receber', 'status' => 'pendente']],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar', 'editar', 'excluir']]))
            ->post('/financeiro', [
                'tipo' => 'receber',
                'categoria' => 'Serviço',
                'descricao' => 'Serviço de teste',
                'cliente_id' => 1,
                'avulso' => '1',
                'valor' => 150.0,
                'data_vencimento' => now()->addDays(5)->toDateString(),
                'dre_fixo_mensal' => '1',
            ]);

        $response->assertRedirect(route('financeiro.index'));
        Http::assertSent(static function ($request) {
            return $request->url() === 'http://127.0.0.1:8000/api/v1/financeiro'
                && $request->method() === 'POST'
                && ! array_key_exists('dre_fixo_mensal', $request->data());
        });
    }

    public function test_store_omits_dre_fixo_mensal_when_classificacao_left_empty(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro' => Http::response([
                'status' => 'success',
                'data' => ['lancamento' => ['id' => 12, 'tipo' => 'pagar', 'status' => 'pendente']],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        // "Todas as categorias" no select "Despesa fixa?" envia
        // dre_fixo_mensal='' — nesse caso a chave deve ser omitida do
        // payload para a API, para o backend aplicar o padrão da categoria
        // (resolveClassification()) em vez de forçar false.
        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar', 'editar', 'excluir']]))
            ->post('/financeiro', [
                'tipo' => 'pagar',
                'categoria' => 'Aluguel',
                'descricao' => 'Aluguel do mês',
                'valor' => 1200.0,
                'data_vencimento' => now()->toDateString(),
                'dre_fixo_mensal' => '',
            ]);

        $response->assertRedirect(route('financeiro.index'));
        Http::assertSent(static function ($request) {
            return $request->url() === 'http://127.0.0.1:8000/api/v1/financeiro'
                && ! array_key_exists('dre_fixo_mensal', $request->data());
        });
    }

    public function test_create_page_renders_classificacao_select_beside_tipo(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response([
                'status' => 'success',
                'data' => ['categorias' => [], 'contas_financeiras' => ['contas' => [], 'contas_padrao' => [], 'tipos' => []], 'formas_pagamento' => []],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar']]))
            ->get('/financeiro/novo');

        $response->assertOk()
            ->assertSee('financeiroClassificacaoFixa', false)
            ->assertSee('Despesa fixa?')
            ->assertSee('Todas as categorias')
            ->assertSee('Despesa variável');
    }

    public function test_create_page_filters_categoria_options_by_classificacao(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response([
                'status' => 'success',
                'data' => [
                    'categorias' => [
                        ['id' => 1, 'nome' => 'Aluguel', 'tipo' => 'pagar', 'dre_fixo_mensal_padrao' => true],
                        ['id' => 2, 'nome' => 'Compra de embalagens', 'tipo' => 'pagar', 'dre_fixo_mensal_padrao' => false],
                    ],
                    'contas_financeiras' => ['contas' => [], 'contas_padrao' => [], 'tipos' => []],
                    'formas_pagamento' => [],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession(['financeiro' => ['visualizar', 'criar']]),
                ['_old_input' => ['tipo' => 'pagar', 'dre_fixo_mensal' => '1']]
            ))
            ->get('/financeiro/novo');

        $response->assertOk()
            // Servidor já filtra as opções que não batem com a
            // classificação escolhida (degrada bem mesmo sem JS) — só a
            // categoria fixa aparece como <option> no HTML.
            ->assertSee('value="Aluguel"', false)
            ->assertDontSee('value="Compra de embalagens"', false);
    }

    public function test_client_detail_shows_financeiro_history_with_permission(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/clients/396' => Http::response([
                'status' => 'success',
                'data' => ['client' => ['id' => 396, 'nome_razao' => 'Cliente Financeiro']],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/financeiro*' => Http::response([
                'status' => 'success',
                'data' => [
                    'lancamentos' => [[
                        'id' => 81,
                        'tipo' => 'receber',
                        'categoria' => 'Receita avulsa',
                        'descricao' => 'Configuração simples por WhatsApp',
                        'cliente_id' => 396,
                        'avulso' => true,
                        'valor' => 80,
                        'status' => 'pago',
                        'data_vencimento' => '2026-07-05',
                    ]],
                    'status_options' => [],
                ],
                'error' => null,
                'meta' => ['pagination' => ['total' => 1]],
            ], 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'clientes' => ['visualizar'],
                'financeiro' => ['visualizar'],
            ]))
            ->get('/clientes/396');

        $response->assertOk()
            ->assertSee('Financeiro do cliente')
            ->assertSee('Configuração simples por WhatsApp')
            ->assertSee(route('financeiro.index', ['cliente_id' => 396, 'tipo' => 'receber']));

        Http::assertSent(static function ($request): bool {
            if (! str_starts_with($request->url(), 'http://127.0.0.1:8000/api/v1/financeiro?')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['cliente_id'] ?? null) === '396'
                && ($query['tipo'] ?? null) === 'receber';
        });
    }

    public function test_client_detail_hides_financeiro_history_without_permission(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/clients/396' => Http::response([
                'status' => 'success',
                'data' => ['client' => ['id' => 396, 'nome_razao' => 'Cliente sem acesso financeiro']],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['clientes' => ['visualizar']]))
            ->get('/clientes/396');

        $response->assertOk()
            ->assertDontSee('Financeiro do cliente');

        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), '/api/v1/financeiro'));
    }

    public function test_user_without_module_permission_is_redirected(): void
    {
        $response = $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']]))
            ->get('/financeiro');

        $response->assertRedirect();
        $this->assertNotSame(200, $response->getStatusCode());
    }

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
     * @param array<string, array<int, string>> $permissions
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
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function fakeUser(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 99,
            'nome' => 'Usuário de Teste',
            'email' => 'usuario@teste.local',
            'perfil' => 'admin',
            'group' => [
                'id' => 1,
                'nome' => 'Administrador',
                'descricao' => 'Grupo completo',
                'sistema' => true,
            ],
            'modules' => [],
            'permissions' => [],
            'foto' => '',
            'ativo' => true,
        ], $overrides);
    }
}
