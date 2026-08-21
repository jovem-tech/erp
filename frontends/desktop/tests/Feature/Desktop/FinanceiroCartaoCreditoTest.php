<?php

namespace Tests\Feature\Desktop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FinanceiroCartaoCreditoTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounts_page_shows_credit_card_tab_with_open_invoice_total(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito' => Http::response($this->cardsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/contas*' => Http::response($this->dashboardPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar'],
                'contas_saldos' => ['visualizar', 'criar', 'editar'],
            ]))
            ->get('/financeiro/contas?mes=2026-08');

        $response->assertOk()
            ->assertSee('Cartões de crédito')
            ->assertSee('Nubank PJ')
            // Fatura em aberto do cartão.
            ->assertSee('R$ 894,96')
            ->assertSee('Novo cartão')
            ->assertSee(route('financeiro.cartoes-credito.faturas', ['cartaoCredito' => 7]), false);
    }

    public function test_credit_card_totals_are_not_mixed_into_the_account_summary(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito' => Http::response($this->cardsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/contas*' => Http::response($this->dashboardPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar'],
                'contas_saldos' => ['visualizar'],
            ]))
            ->get('/financeiro/contas?mes=2026-08');

        // A fatura em aberto é dívida, não dinheiro em caixa: os totais do
        // topo continuam vindo só do dashboard de contas.
        $response->assertOk()
            ->assertSee('R$ 1.784,09')
            ->assertSee('não entra');
    }

    public function test_accounts_page_still_renders_when_card_endpoint_fails(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito' => Http::response([], 500),
            'http://127.0.0.1:8000/api/v1/financeiro/contas*' => Http::response($this->dashboardPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $this->withSession($this->desktopSession([
            'financeiro' => ['visualizar'],
            'contas_saldos' => ['visualizar'],
        ]))
            ->get('/financeiro/contas?mes=2026-08')
            ->assertOk()
            ->assertSee('Contas e Saldos')
            ->assertSee('Cadastre os cartões da assistência');
    }

    public function test_store_sends_card_payload_to_the_api(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito' => Http::response([
                'status' => 'success',
                'data' => ['cartao' => ['id' => 9, 'nome' => 'Inter Empresarial']],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $this->withSession($this->desktopSession(['contas_saldos' => ['visualizar', 'criar']]))
            ->from('/financeiro/contas')
            ->post('/financeiro/contas/cartoes-credito', [
                'nome' => 'Inter Empresarial',
                'instituicao' => 'Banco Inter',
                'final_cartao' => '4321',
                'dia_fechamento' => '10',
                'dia_vencimento' => '20',
                'cor' => '#3868B0',
            ])
            ->assertRedirect('/financeiro/contas')
            ->assertSessionHas('success');

        Http::assertSent(static function ($request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/financeiro/cartoes-credito')
                && $request['nome'] === 'Inter Empresarial'
                && (int) $request['dia_fechamento'] === 10
                && (int) $request['dia_vencimento'] === 20;
        });
    }

    public function test_store_rejects_out_of_range_days(): void
    {
        $this->withSession($this->desktopSession(['contas_saldos' => ['visualizar', 'criar']]))
            ->from('/financeiro/contas')
            ->post('/financeiro/contas/cartoes-credito', [
                'nome' => 'Cartão inválido',
                'dia_fechamento' => '0',
                'dia_vencimento' => '40',
            ])
            ->assertSessionHasErrors(['dia_fechamento', 'dia_vencimento']);
    }

    public function test_invoice_page_lists_expenses_and_offers_bulk_settlement(): void
    {
        // Conta ativa só neste teste: é ela que faz o select de conta (e o
        // texto de entrada/saída sob teste) aparecer no modal de baixa.
        $catalogo = $this->catalogPayload();
        $catalogo['data']['contas_financeiras']['contas'] = [
            ['id' => 1, 'nome' => 'Inter', 'ativo' => true, 'considera_disponivel' => true],
        ];

        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito/7/faturas/2026-09-20' => Http::response($this->invoicePayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($catalogo, 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar'],
                'contas_saldos' => ['visualizar', 'editar'],
            ]))
            ->get('/financeiro/contas/cartoes-credito/7/faturas/2026-09-20');

        $response->assertOk()
            ->assertSee('Plano de celular')
            ->assertSee('Tela para OS')
            // Fixa e variável convivem na mesma fatura — é o ponto da tela.
            ->assertSee('Fixa')
            ->assertSee('Variável')
            ->assertSee('Marcar fatura como paga')
            // Pagar fatura é sempre saída: o campo de conta não pode usar o
            // texto de recebimento ("onde o dinheiro ficará disponível").
            ->assertSee('Define de qual conta o dinheiro sai.', false)
            ->assertDontSee('Define onde o dinheiro ficará disponível', false);
    }

    /**
     * Fatura ainda recebendo compras (não é a corrente nem está vencida):
     * o backend manda pode_pagar=false e a tela não pode oferecer o botão,
     * senão dava para pagar fora de ordem por este caminho mesmo com a
     * lista de faturas já bloqueando (ver
     * test_pay_button_only_shows_for_the_current_and_overdue_invoices).
     */
    public function test_pay_button_is_hidden_for_an_invoice_that_cannot_be_paid_yet(): void
    {
        $payload = $this->invoicePayload();
        $payload['data']['fatura']['eh_atual'] = false;
        $payload['data']['fatura']['pode_pagar'] = false;

        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito/7/faturas/2026-09-20' => Http::response($payload, 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar'],
                'contas_saldos' => ['visualizar', 'editar'],
            ]))
            ->get('/financeiro/contas/cartoes-credito/7/faturas/2026-09-20')
            ->assertOk()
            ->assertDontSee('Marcar fatura como paga');
    }

    /**
     * "Cancelar baixa da fatura" só aparece quando existe baixa para estornar
     * (pode_cancelar_baixa, calculado no backend) — e só para quem pode editar
     * contas e saldos.
     */
    public function test_invoice_page_offers_cancelling_the_settlement_when_it_is_paid(): void
    {
        $payload = $this->invoicePayload();
        $payload['data']['fatura']['pode_cancelar_baixa'] = true;

        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito/7/faturas/2026-09-20' => Http::response($payload, 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar'],
                'contas_saldos' => ['visualizar', 'editar'],
            ]))
            ->get('/financeiro/contas/cartoes-credito/7/faturas/2026-09-20')
            ->assertOk()
            ->assertSee('Mais ações')
            ->assertSee('Cancelar baixa da fatura')
            ->assertSee('/financeiro/contas/cartoes-credito/7/faturas/2026-09-20/cancelar-baixa', false);
    }

    public function test_invoice_page_hides_cancelling_the_settlement_when_there_is_none(): void
    {
        $payload = $this->invoicePayload();
        $payload['data']['fatura']['pode_cancelar_baixa'] = false;

        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito/7/faturas/2026-09-20' => Http::response($payload, 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar'],
                'contas_saldos' => ['visualizar', 'editar'],
            ]))
            ->get('/financeiro/contas/cartoes-credito/7/faturas/2026-09-20')
            ->assertOk()
            ->assertDontSee('Cancelar baixa da fatura');
    }

    public function test_cancelling_the_settlement_posts_to_the_api_and_reports_the_result(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito/7/faturas/2026-09-20/cancelar-baixa' => Http::response([
                'status' => 'success',
                'data' => ['resultado' => [
                    'despesas_estornadas' => 2,
                    'recibos_cancelados' => 1,
                    'valor_estornado' => 130.0,
                ]],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $this->withSession($this->desktopSession(['contas_saldos' => ['visualizar', 'editar']]))
            ->from('/financeiro/contas/cartoes-credito/7/faturas/2026-09-20')
            ->post('/financeiro/contas/cartoes-credito/7/faturas/2026-09-20/cancelar-baixa', [
                'admin_email' => 'admin@example.com',
                'admin_password' => 'Senha@123',
            ])
            ->assertRedirect('/financeiro/contas/cartoes-credito/7/faturas/2026-09-20')
            ->assertSessionHas('success');

        // As credenciais do admin precisam chegar na API — é lá que o
        // step-up é de fato verificado.
        Http::assertSent(static fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/faturas/2026-09-20/cancelar-baixa')
            && $request['admin_email'] === 'admin@example.com'
            && $request['admin_password'] === 'Senha@123');
    }

    public function test_cancelling_the_settlement_requires_permission_to_edit_accounts(): void
    {
        $this->withSession($this->desktopSession(['contas_saldos' => ['visualizar']]))
            ->post('/financeiro/contas/cartoes-credito/7/faturas/2026-09-20/cancelar-baixa', [
                'admin_email' => 'admin@example.com',
                'admin_password' => 'Senha@123',
            ])
            ->assertRedirect();

        Http::assertNothingSent();
    }

    public function test_cancelling_the_settlement_without_admin_credentials_never_reaches_the_api(): void
    {
        Http::fake();

        $this->withSession($this->desktopSession(['contas_saldos' => ['visualizar', 'editar']]))
            ->from('/financeiro/contas/cartoes-credito/7/faturas/2026-09-20')
            ->post('/financeiro/contas/cartoes-credito/7/faturas/2026-09-20/cancelar-baixa')
            ->assertSessionHasErrors(['admin_email', 'admin_password']);

        Http::assertNothingSent();
    }

    public function test_bulk_settlement_posts_to_the_api_and_reports_the_result(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito/7/faturas/2026-09-20/pagar' => Http::response([
                'status' => 'success',
                'data' => ['resultado' => [
                    'result' => 'ok',
                    'succeeded' => [['financeiro_id' => 1], ['financeiro_id' => 2]],
                    'failed' => [],
                    'succeeded_count' => 2,
                    'failed_count' => 0,
                    'valor_baixado' => 130.0,
                ]],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $this->withSession($this->desktopSession(['contas_saldos' => ['visualizar', 'editar']]))
            ->from('/financeiro/contas/cartoes-credito/7/faturas/2026-09-20')
            ->post('/financeiro/contas/cartoes-credito/7/faturas/2026-09-20/pagar', [
                'data_pagamento' => '2026-09-20',
                'forma_pagamento' => 'pix',
            ])
            ->assertRedirect('/financeiro/contas/cartoes-credito/7/faturas/2026-09-20')
            ->assertSessionHas('success');

        Http::assertSent(static function ($request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/faturas/2026-09-20/pagar')
                && $request['forma_pagamento'] === 'pix';
        });
    }

    public function test_bulk_settlement_surfaces_partial_failures(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito/7/faturas/2026-09-20/pagar' => Http::response([
                'status' => 'success',
                'data' => ['resultado' => [
                    'result' => 'partial',
                    'succeeded' => [['financeiro_id' => 1]],
                    'failed' => [['financeiro_id' => 2, 'descricao' => 'Tela para OS', 'reason' => 'Título já liquidado.']],
                    'succeeded_count' => 1,
                    'failed_count' => 1,
                    'valor_baixado' => 100.0,
                ]],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $this->withSession($this->desktopSession(['contas_saldos' => ['visualizar', 'editar']]))
            ->from('/financeiro/contas/cartoes-credito/7/faturas/2026-09-20')
            ->post('/financeiro/contas/cartoes-credito/7/faturas/2026-09-20/pagar', [])
            ->assertRedirect('/financeiro/contas/cartoes-credito/7/faturas/2026-09-20')
            ->assertSessionHas('error');
    }

    public function test_expense_form_offers_the_card_select(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar']]))
            ->get('/financeiro/novo?tipo=pagar');

        $response->assertOk()
            ->assertSee('name="cartao_credito_id"', false)
            ->assertSee('name="data_compra"', false)
            ->assertSee('Nubank PJ');
    }

    /**
     * Quem só tem o módulo financeiro (Despesas/Lançamentos) não entra no
     * cadastro de cartões: a tela vive dentro de Contas e Saldos e segue a
     * permissão de lá. O middleware do desktop redireciona com aviso em vez
     * de devolver 403 (ver EnsureRoutePermission).
     */
    /**
     * Regressão: em "Novo lançamento" (sem ?tipo=pagar) a tela nasce como
     * "a receber" e o usuário troca para "a pagar" sem recarregar. O bloco do
     * cartão precisa estar no HTML mesmo assim — antes ele era condicionado
     * ao tipo no servidor e simplesmente não existia para o JS mostrar.
     */
    public function test_generic_entry_form_ships_the_card_block_even_when_it_starts_as_receivable(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar']]))
            ->get('/financeiro/novo');

        $response->assertOk()
            ->assertSee('name="cartao_credito_id"', false)
            ->assertSee('name="data_compra"', false)
            ->assertSee('Nubank PJ');

        // Nasce escondido (tipo = a receber) — quem revela é o JS.
        $this->assertMatchesRegularExpression(
            '/id="financeiroCartaoCreditoWrapper"\s+class="d-none"/',
            $response->getContent()
        );
    }

    /**
     * "Cartão de crédito" significa coisas opostas nos dois sentidos: recebendo
     * é a maquininha (operadora/taxa), pagando é o cartão da assistência. O
     * formulário precisa dizer qual dos dois está em jogo.
     */
    public function test_expense_form_labels_card_options_as_the_shops_own_card(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar']]))
            ->get('/financeiro/novo?tipo=pagar');

        $response->assertOk()
            ->assertSee('Cartão de crédito (cartão da assistência)')
            ->assertSee('Não há taxa de operadora aqui');
    }

    /**
     * Regressão: a baixa de uma conta a PAGAR não pode oferecer operadora/
     * bandeira/taxa — isso é da maquininha, só existe recebendo do cliente.
     * Sem o data-tipo no form o JS não tinha como distinguir.
     */
    public function test_payable_settlement_form_exposes_its_type_so_acquirer_fields_stay_hidden(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro*' => Http::response($this->lancamentosPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'editar']]))
            ->get('/financeiro/despesas-fixas');

        $response->assertOk()->assertSee('data-tipo="pagar"', false);
    }

    public function test_expense_form_offers_installments_for_card_purchases(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar']]))
            ->get('/financeiro/novo?tipo=pagar');

        $response->assertOk()
            ->assertSee('name="parcelas"', false)
            ->assertSee('À vista (1x)')
            ->assertSee('12x')
            ->assertSee('O valor informado acima é o total da compra.');
    }

    /**
     * O JS trava o Status em "Pendente" quando a forma é cartão de crédito
     * (quem liquida é a fatura). Aqui garantimos que o formulário entrega o que
     * esse JS precisa: a dica explicando a trava e o data-has-movements, que
     * impede travar um título que já tem baixa real.
     */
    public function test_expense_form_ships_the_status_lock_hint_for_credit_purchases(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar']]))
            ->get('/financeiro/novo?tipo=pagar')
            ->assertOk()
            ->assertSee('data-status-cartao-credito-hint', false)
            ->assertSee('Compra no crédito fica sempre pendente — quem liquida é a fatura do cartão.', false)
            ->assertSee('data-has-movements="0"', false);
    }

    public function test_installments_are_sent_to_the_api(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro' => Http::response([
                'status' => 'success',
                'data' => ['lancamento' => ['id' => 77]],
                'error' => null,
                'meta' => [],
            ], 201),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
        ]);

        $this->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar']]))
            ->from('/financeiro/novo')
            ->post('/financeiro', [
                'tipo' => 'pagar',
                'categoria' => 'Compra de peças',
                'descricao' => 'Ar-condicionado novo',
                'valor' => '3600.00',
                'forma_pagamento' => 'cartao_credito',
                'cartao_credito_id' => '7',
                'data_compra' => '2026-08-05',
                'data_vencimento' => '2026-08-05',
                'parcelas' => '12',
            ]);

        Http::assertSent(static function ($request): bool {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/api/v1/financeiro')
                && (int) $request['parcelas'] === 12
                && (int) $request['cartao_credito_id'] === 7;
        });
    }

    public function test_installments_are_not_sent_when_there_is_no_card(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro' => Http::response([
                'status' => 'success',
                'data' => ['lancamento' => ['id' => 78]],
                'error' => null,
                'meta' => [],
            ], 201),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
        ]);

        $this->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar']]))
            ->from('/financeiro/novo')
            ->post('/financeiro', [
                'tipo' => 'pagar',
                'categoria' => 'Compra de peças',
                'descricao' => 'Compra no dinheiro',
                'valor' => '100.00',
                'forma_pagamento' => 'dinheiro',
                'data_vencimento' => '2026-08-05',
                'parcelas' => '12',
            ]);

        // Sem cartão não há fatura para dividir — parcelas não pode vazar.
        Http::assertSent(static function ($request): bool {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/api/v1/financeiro')
                && ! isset($request['parcelas']);
        });
    }

    public function test_expense_detail_shows_the_card_purchase_block(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/146' => Http::response($this->detailPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar'],
                'contas_saldos' => ['visualizar'],
            ]))
            ->get('/financeiro/146');

        $response->assertOk()
            ->assertSee('Compra no cartão')
            ->assertSee('Nubank PJ')
            ->assertSee('final 4321')
            ->assertSee('Cartão de crédito')
            ->assertSee('2 de 12')
            ->assertSee('Conta Inter')
            // Link direto para a fatura em que a compra caiu.
            ->assertSee(route('financeiro.cartoes-credito.faturas.show', [
                'cartaoCredito' => 7,
                'dataVencimento' => '2026-09-20',
            ]), false);
    }

    /**
     * financeiro.forma_pagamento fica NULL enquanto o título está pendente, e
     * é justamente aí que a despesa de cartão passa a maior parte do tempo —
     * a tela não pode dizer "Não informada" nesse caso.
     */
    public function test_pending_card_expense_still_shows_a_payment_method(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/146' => Http::response($this->detailPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar']]))
            ->get('/financeiro/146');

        $response->assertOk()->assertDontSee('Não informada');
    }

    /**
     * A peca comprada no cartao para um conserto nasce ligada a OS; a fatura
     * precisa mostrar essa ligacao, senao so sobra a descricao livre.
     */
    public function test_invoice_expenses_show_the_linked_order(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito/7/faturas/2026-09-20' => Http::response($this->invoicePayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar'],
                'contas_saldos' => ['visualizar'],
                'os' => ['visualizar'],
            ]))
            ->get('/financeiro/contas/cartoes-credito/7/faturas/2026-09-20');

        $response->assertOk()
            ->assertSee('OS26070025')
            ->assertSee(route('orders.show', ['order' => 3637]), false);
    }

    /**
     * O recibo de pagamento de fatura nao tem os_id proprio — quem tem OS sao
     * as despesas que ele quitou. A tela precisa mostrar essa lista, senao o
     * usuario ve "Sem OS vinculada" num pagamento que quitou peca de OS.
     */
    public function test_invoice_payment_receipt_shows_the_expenses_and_their_orders(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/151' => Http::response($this->invoiceReceiptDetailPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar'],
                'os' => ['visualizar'],
                'contas_saldos' => ['visualizar'],
            ]))
            ->get('/financeiro/151');

        $response->assertOk()
            ->assertSee('Despesas quitadas por este pagamento')
            ->assertSee('1 de 2')
            ->assertSee('peça para o notebook', false)
            ->assertSee('OS26070025')
            ->assertSee(route('orders.show', ['order' => 3637]), false)
            // Fornecedor da despesa também deixa de ficar invisível no recibo.
            ->assertSee('Fornecedor Teste')
            ->assertSee(route('financeiro.cartoes-credito.faturas.show', [
                'cartaoCredito' => 7,
                'dataVencimento' => '2026-09-20',
            ]), false)
            ->assertDontSee('Sem OS vinculada');
    }

    public function test_invoice_list_shows_the_current_invoice_kpi_and_filters(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito/7/faturas*' => Http::response($this->invoiceListPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar', 'criar'],
                'contas_saldos' => ['visualizar'],
            ]))
            ->get('/financeiro/contas/cartoes-credito/7/faturas');

        $response->assertOk()
            // KPI da fatura atual, com situação.
            ->assertSee('Fatura atual')
            ->assertSee('R$ 150,00')
            ->assertSee('Vencida')
            // Filtros pedidos: situação, mês e período.
            ->assertSee('name="situacao"', false)
            ->assertSee('name="mes"', false)
            ->assertSee('name="vencimento_de"', false)
            ->assertSee('name="vencimento_ate"', false)
            // Botão de lançar despesa já no cartão.
            ->assertSee('Nova despesa neste cartão')
            // e(): o & da querystring sai como &amp; dentro do href.
            ->assertSee(e(route('financeiro.create', ['tipo' => 'pagar', 'cartao_credito_id' => 7])), false)
            // Aviso do rotativo quando há fatura vencida em aberto.
            ->assertSee('Há fatura vencida em aberto.');
    }

    /**
     * "Lançar despesa em fatura paga" é a saída para o bloqueio de compra em
     * fatura fechada — só faz sentido quando existe alguma fatura paga.
     */
    public function test_invoice_list_offers_the_forgotten_expense_action_when_there_is_a_paid_invoice(): void
    {
        $payload = $this->invoiceListPayload();
        $payload['data']['faturas'][0]['status'] = 'paga';

        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito/7/faturas*' => Http::response($payload, 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar', 'criar'],
                'contas_saldos' => ['visualizar', 'editar'],
            ]))
            ->get('/financeiro/contas/cartoes-credito/7/faturas')
            ->assertOk()
            ->assertSee('Mais ações')
            ->assertSee('Lançar despesa em fatura paga')
            ->assertSee('/financeiro/contas/cartoes-credito/7/faturas/despesa-esquecida', false)
            // O select do modal lista a fatura paga, com o fechamento dela.
            ->assertSee('Vence em 20/08/2026', false)
            // A janela do ciclo vai junto: é ela que trava o calendário da data
            // da compra (financeiro-despesa-esquecida.js).
            ->assertSee('data-abertura="2026-07-11"', false)
            ->assertSee('data-fechamento="2026-08-10"', false)
            ->assertSee('assets/js/financeiro-despesa-esquecida.js', false);
    }

    public function test_invoice_list_hides_the_forgotten_expense_action_without_a_paid_invoice(): void
    {
        // Fixture padrão: as duas faturas em aberto, nada para corrigir aqui.
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito/7/faturas*' => Http::response($this->invoiceListPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar', 'criar'],
                'contas_saldos' => ['visualizar', 'editar'],
            ]))
            ->get('/financeiro/contas/cartoes-credito/7/faturas')
            ->assertOk()
            ->assertDontSee('Lançar despesa em fatura paga');
    }

    public function test_forgotten_expense_is_forwarded_to_the_api(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito/7/faturas/2026-08-20/despesa-esquecida' => Http::response([
                'status' => 'success',
                'data' => ['lancamento' => ['id' => 55]],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $this->withSession($this->desktopSession([
            'financeiro' => ['visualizar', 'criar'],
            'contas_saldos' => ['visualizar', 'editar'],
        ]))
            ->from('/financeiro/contas/cartoes-credito/7/faturas')
            ->post('/financeiro/contas/cartoes-credito/7/faturas/despesa-esquecida', [
                'data_vencimento' => '2026-08-20',
                'categoria' => 'Compra de peças',
                'descricao' => 'Cabo esquecido',
                'valor' => '45.00',
                'data_compra' => '2026-08-08',
            ])
            ->assertRedirect('/financeiro/contas/cartoes-credito/7/faturas')
            ->assertSessionHas('success');

        // O vencimento vai no path da API; o resto, no corpo.
        Http::assertSent(static fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/faturas/2026-08-20/despesa-esquecida')
            && $request['descricao'] === 'Cabo esquecido'
            && $request['data_compra'] === '2026-08-08'
            && ! array_key_exists('data_vencimento', $request->data()));
    }

    public function test_forgotten_expense_requires_the_mandatory_fields(): void
    {
        Http::fake();

        $this->withSession($this->desktopSession([
            'financeiro' => ['visualizar', 'criar'],
            'contas_saldos' => ['visualizar', 'editar'],
        ]))
            ->from('/financeiro/contas/cartoes-credito/7/faturas')
            ->post('/financeiro/contas/cartoes-credito/7/faturas/despesa-esquecida', [])
            ->assertSessionHasErrors(['data_vencimento', 'categoria', 'descricao', 'valor', 'data_compra']);

        Http::assertNothingSent();
    }

    /**
     * Saber que a fatura foi paga não basta — a tela precisa dizer quando.
     */
    public function test_invoice_list_shows_the_payment_date_of_a_settled_invoice(): void
    {
        $payload = $this->invoiceListPayload();
        $payload['data']['faturas'][0]['status'] = 'paga';
        $payload['data']['faturas'][0]['data_pagamento'] = '2026-08-18';

        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito/7/faturas*' => Http::response($payload, 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar'],
                'contas_saldos' => ['visualizar', 'editar'],
            ]))
            ->get('/financeiro/contas/cartoes-credito/7/faturas')
            ->assertOk()
            ->assertSee('Pagamento')
            ->assertSee('18/08/2026');
    }

    public function test_invoice_page_shows_when_the_invoice_was_paid(): void
    {
        $payload = $this->invoicePayload();
        $payload['data']['fatura']['status'] = 'paga';
        $payload['data']['fatura']['data_pagamento'] = '2026-09-18';

        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito/7/faturas/2026-09-20' => Http::response($payload, 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar'],
                'contas_saldos' => ['visualizar', 'editar'],
            ]))
            ->get('/financeiro/contas/cartoes-credito/7/faturas/2026-09-20')
            ->assertOk()
            ->assertSee('Paga em 18/09/2026');
    }

    public function test_invoice_list_forwards_filters_to_the_api(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito/7/faturas*' => Http::response($this->invoiceListPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $this->withSession($this->desktopSession(['contas_saldos' => ['visualizar']]))
            ->get('/financeiro/contas/cartoes-credito/7/faturas?situacao=aberta&mes=2026-09&vencimento_de=2026-01-01')
            ->assertOk();

        Http::assertSent(static function ($request): bool {
            return str_contains($request->url(), '/cartoes-credito/7/faturas')
                && str_contains($request->url(), 'situacao=aberta')
                && str_contains($request->url(), 'mes=2026-09')
                && str_contains($request->url(), 'vencimento_de=2026-01-01');
        });
    }

    public function test_new_expense_button_preselects_the_card(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar']]))
            ->get('/financeiro/novo?tipo=pagar&cartao_credito_id=7');

        // Cartão e forma de pagamento já vêm escolhidos, e o bloco do cartão
        // nasce visível (sem depender do JS para revelar).
        $content = $response->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/value="7"\s+data-conta-id="[^"]*"\s+data-conta-nome="[^"]*"\s+selected/', $content);
        $this->assertMatchesRegularExpression('/value="cartao_credito"\s+selected/', $content);
        $this->assertStringNotContainsString('id="financeiroCartaoCreditoWrapper" class="d-none"', $content);
    }

    public function test_invoice_list_action_is_labelled_as_invoice_not_expenses(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito/7/faturas*' => Http::response($this->invoiceListPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $this->withSession($this->desktopSession(['contas_saldos' => ['visualizar']]))
            ->get('/financeiro/contas/cartoes-credito/7/faturas')
            ->assertOk()
            ->assertSee('Ver fatura')
            ->assertDontSee('Ver despesas');
    }

    /**
     * Pagar só na fatura corrente e nas vencidas: as futuras ainda estão
     * recebendo compras e o total muda até o fechamento.
     */
    public function test_pay_button_only_shows_for_the_current_and_overdue_invoices(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito/7/faturas*' => Http::response($this->invoiceListPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $content = $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar'],
                'contas_saldos' => ['visualizar', 'editar'],
            ]))
            ->get('/financeiro/contas/cartoes-credito/7/faturas')
            ->assertOk()
            ->assertSee('Pagar fatura')
            ->getContent();

        // 20/08 é a atual (e vencida): tem botão e modal.
        $this->assertStringContainsString('#pagarFaturaModal20260820', $content);
        $this->assertStringContainsString('id="pagarFaturaModal20260820"', $content);
        // 20/09 é futura e não vencida: não pode ter nem botão nem modal.
        $this->assertStringNotContainsString('pagarFaturaModal20260920', $content);
    }

    public function test_pay_button_is_hidden_without_permission_to_edit_accounts(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito/7/faturas*' => Http::response($this->invoiceListPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $this->withSession($this->desktopSession(['contas_saldos' => ['visualizar']]))
            ->get('/financeiro/contas/cartoes-credito/7/faturas')
            ->assertOk()
            ->assertDontSee('Pagar fatura');
    }

    public function test_invoice_list_shows_the_closing_date_column(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito/7/faturas*' => Http::response($this->invoiceListPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $this->withSession($this->desktopSession(['contas_saldos' => ['visualizar']]))
            ->get('/financeiro/contas/cartoes-credito/7/faturas')
            ->assertOk()
            ->assertSee('Fechamento')
            // Fecha 10/08 e vence 20/08 — as duas datas na mesma linha.
            ->assertSee('10/08/2026')
            ->assertSee('20/08/2026')
            ->assertSee('10/09/2026');
    }

    /**
     * Despesa de cartão não é editada pela listagem de Lançamentos: o caminho é
     * a fatura, onde dá para ver o efeito da alteração no total do ciclo.
     */
    public function test_lancamentos_list_routes_card_expense_editing_to_the_invoice(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro*' => Http::response($this->lancamentoCartaoPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'editar']]))
            ->get('/financeiro');

        $response->assertOk()
            ->assertSee('Editar pela fatura do cartão')
            // O link de edição direta some para esta despesa.
            ->assertDontSee(e(route('financeiro.edit', ['financeiro' => 11])), false);
    }

    public function test_invoice_page_offers_editing_each_expense(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito/7/faturas/2026-09-20' => Http::response($this->invoicePayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar', 'editar'],
                'contas_saldos' => ['visualizar'],
            ]))
            ->get('/financeiro/contas/cartoes-credito/7/faturas/2026-09-20');

        // O link carrega a origem para o save voltar à fatura.
        $response->assertOk()->assertSee(e(route('financeiro.edit', [
            'financeiro' => 1,
            'voltar_cartao_id' => 7,
            'voltar_fatura_vencimento' => '2026-09-20',
        ])), false);
    }

    public function test_invoice_page_hides_editing_without_financeiro_edit_permission(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes-credito/7/faturas/2026-09-20' => Http::response($this->invoicePayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $this->withSession($this->desktopSession([
            'financeiro' => ['visualizar'],
            'contas_saldos' => ['visualizar'],
        ]))
            ->get('/financeiro/contas/cartoes-credito/7/faturas/2026-09-20')
            ->assertOk()
            ->assertDontSee('financeiro/1/editar');
    }

    public function test_editing_from_the_invoice_returns_to_the_invoice(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/1' => Http::response([
                'status' => 'success',
                'data' => ['lancamento' => ['id' => 1]],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response($this->catalogPayload(), 200),
        ]);

        $this->withSession($this->desktopSession(['financeiro' => ['visualizar', 'editar']]))
            ->from('/financeiro/1/editar')
            ->put('/financeiro/1', [
                'tipo' => 'pagar',
                'categoria' => 'Compra de peças',
                'descricao' => 'Peça editada',
                'valor' => '50.00',
                'data_vencimento' => '2026-09-20',
                'voltar_cartao_id' => '7',
                'voltar_fatura_vencimento' => '2026-09-20',
            ])
            ->assertRedirect(route('financeiro.cartoes-credito.faturas.show', [
                'cartaoCredito' => 7,
                'dataVencimento' => '2026-09-20',
            ]));
    }

    public function test_accounts_permission_is_required_for_the_card_registry(): void
    {
        $this->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar']]))
            ->get('/financeiro/contas/cartoes-credito/7/faturas')
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    /** @return array<string, mixed> */
    private function cardsPayload(): array
    {
        return [
            'status' => 'success',
            'data' => ['cartoes' => [[
                'id' => 7,
                'nome' => 'Nubank PJ',
                'instituicao' => 'Nubank',
                'final_cartao' => '4321',
                'dia_fechamento' => 10,
                'dia_vencimento' => 20,
                'cor' => '#8b5cf6',
                'ativo' => true,
                'observacoes' => null,
                'fatura_atual' => [
                    'data_vencimento' => '2026-09-20',
                    'total' => 894.96,
                    'total_fixas' => 821.87,
                    'total_variaveis' => 73.09,
                    'quantidade_despesas' => 5,
                    'status' => 'aberta',
                ],
                'total_em_aberto' => 894.96,
                'faturas_abertas' => 1,
            ]]],
            'error' => null,
            'meta' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function invoicePayload(): array
    {
        return [
            'status' => 'success',
            'data' => [
                'cartao' => [
                    'id' => 7,
                    'nome' => 'Nubank PJ',
                    'instituicao' => 'Nubank',
                    'final_cartao' => '4321',
                    'dia_fechamento' => 10,
                    'dia_vencimento' => 20,
                    'cor' => '#8b5cf6',
                    'ativo' => true,
                ],
                'fatura' => [
                    'data_vencimento' => '2026-09-20',
                    'total' => 130.0,
                    'total_fixas' => 100.0,
                    'total_variaveis' => 30.0,
                    'quantidade_despesas' => 2,
                    'status' => 'aberta',
                    'vencida' => false,
                    'total_em_aberto' => 130.0,
                    'eh_atual' => true,
                    'pode_pagar' => true,
                ],
                'despesas' => [
                    [
                        'id' => 1,
                        'descricao' => 'Plano de celular',
                        'categoria' => 'Telefonia',
                        'valor' => 100.0,
                        'status' => 'pendente',
                        'dre_fixo_mensal' => true,
                        'data_compra' => '2026-08-15',
                        'data_vencimento' => '2026-09-20',
                        'fornecedor' => 'Vivo',
                    ],
                    [
                        'id' => 2,
                        'descricao' => 'Tela para OS',
                        'categoria' => 'Compra de peças',
                        'valor' => 30.0,
                        'status' => 'pendente',
                        'dre_fixo_mensal' => false,
                        'data_compra' => '2026-09-05',
                        'data_vencimento' => '2026-09-20',
                        'fornecedor' => null,
                        'os' => ['id' => 3637, 'numero_os' => 'OS26070025'],
                    ],
                ],
            ],
            'error' => null,
            'meta' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function catalogPayload(): array
    {
        return [
            'status' => 'success',
            'data' => [
                'categorias' => [[
                    'id' => 1,
                    'nome' => 'Compra de peças',
                    'tipo' => 'pagar',
                    'dre_grupo' => ['nome' => 'Custo Direto (OS)'],
                    'dre_subgrupo' => ['nome' => 'Compra emergencial de peças'],
                    'dre_fixo_mensal_padrao' => false,
                ]],
                'dre_grupos' => [],
                'dre_subgrupos' => [],
                'comissoes_tecnicos' => [],
                'comissao_percentual_padrao' => 0,
                'formas_pagamento' => [
                    ['codigo' => 'pix', 'nome' => 'Pix', 'ativo' => true, 'resumo_enum' => true],
                    ['codigo' => 'cartao_credito', 'nome' => 'Cartão de crédito', 'ativo' => true, 'resumo_enum' => true],
                ],
                'chaves_pix' => [],
                'chaves_pix_tipos' => [],
                'cartao' => ['operadoras' => [], 'bandeiras' => [], 'taxas' => []],
                'contas_financeiras' => ['contas' => [], 'contas_padrao' => [], 'tipos' => []],
                'cartoes_credito' => [[
                    'id' => 7,
                    'nome' => 'Nubank PJ',
                    'instituicao' => 'Nubank',
                    'final_cartao' => '4321',
                    'dia_fechamento' => 10,
                    'dia_vencimento' => 20,
                    'cor' => '#8b5cf6',
                    'ativo' => true,
                ]],
            ],
            'error' => null,
            'meta' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function dashboardPayload(): array
    {
        return [
            'status' => 'success',
            'data' => [
                'mes' => '2026-08',
                'ate' => '2026-08-17',
                'resumo' => [
                    'disponivel_operacional' => 1784.09,
                    'total_em_contas' => 1784.09,
                    'cartao_a_receber' => 48.10,
                    'posicao_total' => 1832.19,
                ],
                'contas' => [[
                    'id' => 1,
                    'nome' => 'Caixa da loja',
                    'tipo' => 'caixa',
                    'instituicao' => 'loja',
                    'data_inicio_controle' => '2026-08-12',
                    'considera_disponivel' => true,
                    'ativo' => true,
                    'cor' => '#3868B0',
                    'observacoes' => null,
                    'formas_padrao' => ['dinheiro'],
                    'saldo_disponivel' => 10.0,
                    'cartao_pendente' => 0,
                    'posicao_total' => 10.0,
                    'mes' => ['saldo_inicial' => 0, 'entradas' => 100.0, 'saidas' => 90.0, 'saldo_final' => 10.0],
                ]],
                'cartoes_pendentes' => [],
                'transferencias_recentes' => [],
                'sem_conta' => ['quantidade' => 0, 'valor' => 0],
                'opcoes' => ['tipos' => [
                    ['value' => 'caixa', 'label' => 'Caixa físico'],
                    ['value' => 'banco', 'label' => 'Banco'],
                ]],
            ],
            'error' => null,
            'meta' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function lancamentosPayload(): array
    {
        return [
            'status' => 'success',
            'data' => ['lancamentos' => [[
                'id' => 11,
                'tipo' => 'pagar',
                'categoria' => 'Compra de peças',
                'descricao' => 'Peça no cartão',
                'valor' => 130.0,
                'valor_aberto' => 130.0,
                'status' => 'pendente',
                'data_vencimento' => '2026-09-20',
                'origem_trilha' => [],
            ]]],
            'error' => null,
            'meta' => [
                'pagination' => ['current_page' => 1, 'per_page' => 15, 'total' => 1, 'last_page' => 1],
                'status_options' => [],
                'totais_despesas' => ['fixas' => 0, 'variaveis' => 130.0],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function invoiceListPayload(): array
    {
        return [
            'status' => 'success',
            'data' => [
                'cartao' => [
                    'id' => 7,
                    'nome' => 'Nubank PJ',
                    'instituicao' => 'Nubank',
                    'final_cartao' => '4321',
                    'dia_fechamento' => 10,
                    'dia_vencimento' => 20,
                    'cor' => '#8b5cf6',
                    'ativo' => true,
                ],
                'faturas' => [
                    [
                        'data_vencimento' => '2026-08-20',
                        'data_fechamento' => '2026-08-10',
                        'data_abertura' => '2026-07-11',
                        'data_pagamento' => null,
                        'total' => 150.0,
                        'total_fixas' => 100.0,
                        'total_variaveis' => 50.0,
                        'quantidade_despesas' => 2,
                        'status' => 'aberta',
                        'vencida' => true,
                        'total_em_aberto' => 150.0,
                    ],
                    [
                        'data_vencimento' => '2026-09-20',
                        'data_fechamento' => '2026-09-10',
                        'data_abertura' => '2026-08-11',
                        'total' => 75.0,
                        'total_fixas' => 25.0,
                        'total_variaveis' => 50.0,
                        'quantidade_despesas' => 2,
                        'status' => 'aberta',
                        'vencida' => false,
                        'total_em_aberto' => 75.0,
                    ],
                ],
                'fatura_atual' => [
                    'data_vencimento' => '2026-08-20',
                    'data_fechamento' => '2026-08-10',
                    'total' => 150.0,
                    'total_fixas' => 100.0,
                    'total_variaveis' => 50.0,
                    'quantidade_despesas' => 2,
                    'status' => 'aberta',
                    'vencida' => true,
                    'total_em_aberto' => 150.0,
                ],
            ],
            'error' => null,
            'meta' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function detailPayload(): array
    {
        return [
            'status' => 'success',
            'data' => [
                'lancamento' => [
                    'id' => 146,
                    'tipo' => 'pagar',
                    'status' => 'pendente',
                    'categoria' => 'Compra de peças',
                    'descricao' => 'Ar-condicionado novo',
                    'valor' => 300.0,
                    'data_vencimento' => '2026-09-20',
                    'data_pagamento' => null,
                    'observacoes' => null,
                    'avulso' => true,
                ],
                'resumo' => [
                    'valor_titulo' => 300.0,
                    'valor_movimentado' => 0.0,
                    'valor_aberto' => 300.0,
                    'total_movimentos' => 0,
                    'percentual_quitado' => 0.0,
                ],
                'detalhes' => [
                    'tipo_label' => 'A pagar',
                    'status_label' => 'Pendente',
                    // NULL de propósito: título pendente não tem baixa, então a
                    // coluna forma_pagamento ainda está vazia.
                    'forma_pagamento_label' => null,
                    'contraparte' => [],
                    'origem' => ['titulo' => 'Lançamento avulso'],
                    'os' => null,
                    'movimentos' => [],
                    'cartao_credito' => [
                        'id' => 7,
                        'nome' => 'Nubank PJ',
                        'instituicao' => 'Nubank',
                        'final_cartao' => '4321',
                        'cor' => '#8b5cf6',
                        'modalidade' => 'credito',
                        'modalidade_label' => 'Cartão de crédito',
                        'dia_fechamento' => 10,
                        'dia_vencimento' => 20,
                        'data_compra' => '2026-08-15',
                        'fatura_vencimento' => '2026-09-20',
                        'parcela_numero' => 2,
                        'parcelas_total' => 12,
                        'conta_financeira_nome' => 'Conta Inter',
                    ],
                    'impactos' => [
                        'impacta_dre' => true,
                        'impacta_fluxo_caixa' => true,
                        'dre_fixo_mensal' => false,
                        'grupo_dre' => 'Custo Direto (OS)',
                        'subgrupo_dre' => 'Compra emergencial de peças',
                        'data_competencia' => '2026-08-15',
                    ],
                    'auditoria' => ['criado_em' => '2026-08-15 10:00', 'atualizado_em' => '2026-08-15 10:00'],
                ],
            ],
            'error' => null,
            'meta' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function invoiceReceiptDetailPayload(): array
    {
        return [
            'status' => 'success',
            'data' => [
                'lancamento' => [
                    'id' => 151,
                    'tipo' => 'pagar',
                    'status' => 'pago',
                    'categoria' => 'Fatura de cartão de crédito',
                    'descricao' => 'Pagamento da fatura Nubank PJ — venc. 20/09/2026 (2 despesas)',
                    'valor' => 75.0,
                    'data_vencimento' => '2026-09-20',
                    'data_pagamento' => '2026-09-20',
                    'observacoes' => null,
                    'avulso' => true,
                    'cartao_modalidade' => null,
                ],
                'resumo' => [
                    'valor_titulo' => 75.0,
                    'valor_movimentado' => 75.0,
                    'valor_aberto' => 0.0,
                    'total_movimentos' => 1,
                    'percentual_quitado' => 100.0,
                ],
                'detalhes' => [
                    'tipo_label' => 'A pagar',
                    'status_label' => 'Pago',
                    'forma_pagamento_label' => 'PIX',
                    'contraparte' => [],
                    'origem' => ['titulo' => 'Pagamento de fatura'],
                    'os' => null,
                    'movimentos' => [],
                    // Recibo não é compra no cartão: o bloco "Compra no cartão"
                    // continua nulo (ver FinanceiroService::creditCardDetail()).
                    'cartao_credito' => null,
                    'fatura_cartao' => [
                        'cartao' => [
                            'id' => 7,
                            'nome' => 'Nubank PJ',
                            'instituicao' => 'Nubank',
                            'final_cartao' => '4321',
                            'cor' => '#8b5cf6',
                        ],
                        'data_vencimento' => '2026-09-20',
                        'quantidade_despesas' => 2,
                        'quantidade_com_os' => 1,
                        'valor_total' => 75.0,
                        'despesas' => [
                            [
                                'id' => 146,
                                'descricao' => 'internet claro',
                                'categoria' => 'Telefonia',
                                'valor' => 25.0,
                                'status' => 'pago',
                                'data_compra' => '2026-08-19',
                                'parcela_numero' => 1,
                                'parcelas_total' => 4,
                                'os' => null,
                                'fornecedor' => null,
                            ],
                            [
                                'id' => 150,
                                'descricao' => 'peça para o notebook do carlos',
                                'categoria' => 'Compra de peças',
                                'valor' => 50.0,
                                'status' => 'pago',
                                'data_compra' => '2026-08-19',
                                'parcela_numero' => null,
                                'parcelas_total' => null,
                                'os' => ['id' => 3637, 'numero_os' => 'OS26070025'],
                                'fornecedor' => ['id' => 4, 'nome' => 'Fornecedor Teste'],
                            ],
                        ],
                    ],
                    'impactos' => [
                        'impacta_dre' => false,
                        'impacta_fluxo_caixa' => false,
                        'dre_fixo_mensal' => false,
                        'grupo_dre' => null,
                        'subgrupo_dre' => null,
                        'data_competencia' => '2026-09-20',
                    ],
                    'auditoria' => ['criado_em' => '2026-09-20 10:00', 'atualizado_em' => '2026-09-20 10:00'],
                ],
            ],
            'error' => null,
            'meta' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function lancamentoCartaoPayload(): array
    {
        return [
            'status' => 'success',
            'data' => ['lancamentos' => [[
                'id' => 11,
                'tipo' => 'pagar',
                'categoria' => 'Compra de peças',
                'descricao' => 'Peça no cartão',
                'valor' => 50.0,
                'valor_aberto' => 50.0,
                'status' => 'pago',
                'data_vencimento' => '2026-09-20',
                'origem_trilha' => [],
                'cartao_modalidade' => 'credito',
                'cartao_credito' => ['id' => 7, 'nome' => 'Nubank PJ'],
            ]]],
            'error' => null,
            'meta' => [
                'pagination' => ['current_page' => 1, 'per_page' => 15, 'total' => 1, 'last_page' => 1],
                'status_options' => [],
                'totais_despesas' => ['fixas' => 0, 'variaveis' => 50.0],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function notificationsPayload(): array
    {
        return [
            'status' => 'success',
            'data' => ['items' => [], 'unread_count' => 0],
            'error' => null,
            'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 6, 'total' => 0, 'last_page' => 1]],
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
                    'group' => [
                        'id' => 1,
                        'nome' => 'Administrador',
                        'descricao' => 'Grupo completo',
                        'sistema' => true,
                    ],
                    'modules' => array_keys($permissions),
                    'permissions' => $permissions,
                    'foto' => '',
                    'ativo' => true,
                ],
            ],
        ];
    }
}
