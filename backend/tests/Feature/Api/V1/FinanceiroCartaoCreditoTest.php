<?php

namespace Tests\Feature\Api\V1;

use App\Models\Financeiro;
use App\Models\FinanceiroCartaoCredito;
use App\Services\Financeiro\FinanceiroCartaoCreditoService;
use App\Services\Financeiro\FinanceiroService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class FinanceiroCartaoCreditoTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    private int $authenticatedUserId;

    private ?\App\Models\User $admin = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->seedOrderCatalog();
        $this->grantGroupPermissions(1, [
            'financeiro' => ['visualizar', 'criar', 'editar', 'excluir'],
            'contas_saldos' => ['visualizar', 'criar', 'editar'],
        ]);

        $user = $this->createUserRecord(['grupo_id' => 1]);
        $this->authenticatedUserId = (int) $user->id;
        Sanctum::actingAs($user, ['*']);
    }

    /**
     * O cálculo do ciclo é a peça com mais casos de borda da feature: define
     * em qual fatura cada compra cai e, por consequência, o que a baixa em
     * lote vai liquidar junto.
     */
    #[DataProvider('invoiceCycleProvider')]
    public function test_resolve_invoice_cycle_maps_purchase_to_the_right_invoice(
        int $diaFechamento,
        int $diaVencimento,
        string $dataCompra,
        string $vencimentoEsperado,
        string $cenario
    ): void {
        $cartao = new FinanceiroCartaoCredito([
            'nome' => 'Cartão teste',
            'dia_fechamento' => $diaFechamento,
            'dia_vencimento' => $diaVencimento,
        ]);

        $ciclo = app(FinanceiroCartaoCreditoService::class)
            ->resolveInvoiceCycle($cartao, CarbonImmutable::parse($dataCompra));

        $this->assertSame($vencimentoEsperado, $ciclo['data_vencimento'], $cenario);
    }

    /** @return array<string, array{int, int, string, string, string}> */
    public static function invoiceCycleProvider(): array
    {
        return [
            'compra antes do fechamento entra na fatura do mes' => [10, 20, '2026-08-05', '2026-08-20', 'antes do fechamento'],
            'compra no dia do fechamento ainda entra' => [10, 20, '2026-08-10', '2026-08-20', 'no limite do fechamento'],
            'compra depois do fechamento vai para a proxima' => [10, 20, '2026-08-11', '2026-09-20', 'apos o fechamento'],
            'fecha 28 vence 5 cai no mes seguinte' => [28, 5, '2026-08-20', '2026-09-05', 'vencimento no mes seguinte'],
            'fecha 5 vence 15 cai no mesmo mes' => [5, 15, '2026-08-03', '2026-08-15', 'vencimento no mesmo mes'],
            'fechamento 31 em fevereiro usa o ultimo dia' => [31, 10, '2026-02-15', '2026-03-10', 'fevereiro tem 28 dias'],
            'vencimento 31 em fevereiro clampa para 28' => [10, 31, '2026-02-05', '2026-02-28', 'clamp do vencimento'],
            'fechamento igual ao vencimento vai para o mes seguinte' => [15, 15, '2026-08-10', '2026-09-15', 'fecha=vence'],
            'virada de ano' => [10, 20, '2026-12-15', '2027-01-20', 'dezembro para janeiro'],
        ];
    }

    public function test_index_lists_registered_cards(): void
    {
        $this->createCartao(['nome' => 'Nubank PJ']);
        $this->createCartao(['nome' => 'Inter Empresarial']);

        $response = $this->getJson('/api/v1/financeiro/cartoes-credito');

        $response->assertOk()->assertJsonCount(2, 'data.cartoes');
    }

    public function test_store_creates_a_card(): void
    {
        $response = $this->postJson('/api/v1/financeiro/cartoes-credito', [
            'nome' => 'Nubank PJ',
            'instituicao' => 'Nubank',
            'final_cartao' => '4321',
            'dia_fechamento' => 10,
            'dia_vencimento' => 20,
        ]);

        $response->assertCreated()->assertJsonPath('data.cartao.nome', 'Nubank PJ');
        $this->assertDatabaseHas('financeiro_cartoes_credito', ['nome' => 'Nubank PJ', 'dia_fechamento' => 10]);
    }

    public function test_store_rejects_invalid_closing_and_due_days(): void
    {
        $response = $this->postJson('/api/v1/financeiro/cartoes-credito', [
            'nome' => 'Cartão inválido',
            'dia_fechamento' => 0,
            'dia_vencimento' => 45,
        ]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $details = $response->json('error.details');
        $this->assertArrayHasKey('dia_fechamento', $details);
        $this->assertArrayHasKey('dia_vencimento', $details);
    }

    public function test_expense_linked_to_a_card_gets_the_invoice_due_date_computed(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);

        // Compra depois do fechamento: precisa cair na fatura do mês seguinte,
        // ignorando a data_vencimento enviada pelo formulário.
        $response = $this->postJson('/api/v1/financeiro', [
            'tipo' => 'pagar',
            'categoria' => 'Compra de peças',
            'descricao' => 'Tela para OS do cliente',
            'valor' => 150.00,
            'forma_pagamento' => 'cartao_credito',
            'cartao_credito_id' => $cartao->id,
            'data_compra' => '2026-08-15',
            'data_vencimento' => '2026-08-15',
        ]);

        $response->assertCreated();

        $despesa = Financeiro::query()->latest('id')->first();
        $this->assertSame('2026-09-20', $despesa->data_vencimento->toDateString());
        $this->assertSame('2026-08-15', $despesa->data_compra->toDateString());
        // A despesa é incorrida na compra, não no vencimento da fatura.
        $this->assertSame('2026-08-15', $despesa->data_competencia->toDateString());
    }

    public function test_expense_without_card_keeps_the_informed_due_date(): void
    {
        $this->postJson('/api/v1/financeiro', [
            'tipo' => 'pagar',
            'categoria' => 'Compra de peças',
            'descricao' => 'Compra no dinheiro',
            'valor' => 50.00,
            'data_vencimento' => '2026-08-15',
        ])->assertCreated();

        $despesa = Financeiro::query()->latest('id')->first();
        $this->assertSame('2026-08-15', $despesa->data_vencimento->toDateString());
        $this->assertNull($despesa->cartao_credito_id);
        $this->assertNull($despesa->data_compra);
    }

    /**
     * Quem liquida uma compra no crédito é a fatura. Deixar o título nascer
     * "pago" dispararia a baixa automática de finalizeAfterSave() e a despesa
     * sairia do saldo em aberto da fatura sem ninguém ter pago a fatura.
     */
    public function test_a_credit_card_expense_created_as_paid_falls_back_to_pending(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);

        $this->postJson('/api/v1/financeiro', [
            'tipo' => 'pagar',
            'categoria' => 'Compra de peças',
            'descricao' => 'Tela comprada no crédito',
            'valor' => 150.00,
            'forma_pagamento' => 'cartao_credito',
            'cartao_credito_id' => $cartao->id,
            'data_compra' => '2026-08-05',
            'data_vencimento' => '2026-08-05',
            'status' => 'pago',
        ])->assertCreated();

        $despesa = Financeiro::query()->latest('id')->firstOrFail();

        $this->assertSame(Financeiro::STATUS_PENDENTE, $despesa->status);
        $this->assertNull($despesa->data_pagamento);
        $this->assertSame(0, $despesa->movimentos()->count());

        // Continua compondo o saldo em aberto da fatura.
        $fatura = collect(app(FinanceiroCartaoCreditoService::class)->invoiceList($cartao))
            ->firstWhere('data_vencimento', '2026-08-20');
        $this->assertSame('aberta', $fatura['status']);
        $this->assertSame(150.0, $fatura['total_em_aberto']);
    }

    /**
     * Débito sai da conta na hora e nunca entra em fatura — continua podendo
     * nascer pago, com a baixa automática de sempre.
     */
    public function test_a_debit_card_expense_can_still_be_created_as_paid(): void
    {
        $cartao = $this->createCartao();

        $this->postJson('/api/v1/financeiro', [
            'tipo' => 'pagar',
            'categoria' => 'Compra de peças',
            'descricao' => 'Cola comprada no débito',
            'valor' => 25.00,
            'forma_pagamento' => 'cartao_debito',
            'cartao_credito_id' => $cartao->id,
            'data_compra' => '2026-08-05',
            'data_vencimento' => '2026-08-05',
            'status' => 'pago',
        ])->assertCreated();

        $despesa = Financeiro::query()->latest('id')->firstOrFail();

        $this->assertSame(Financeiro::STATUS_PAGO, $despesa->status);
        $this->assertSame(1, $despesa->movimentos()->count());
    }

    /**
     * Ao RECEBER, "cartão de crédito" é a maquininha (dinheiro do cliente
     * entrando), não o cartão da assistência — a trava do crédito não pode
     * pegar esse caso.
     */
    public function test_a_receivable_paid_by_card_is_not_affected_by_the_credit_rule(): void
    {
        $this->postJson('/api/v1/financeiro', [
            'tipo' => 'receber',
            'categoria' => 'Serviço',
            'descricao' => 'Serviço recebido na maquininha',
            'valor' => 200.00,
            'avulso' => true,
            'forma_pagamento' => 'cartao_credito',
            'data_vencimento' => '2026-08-05',
            'status' => 'pago',
        ])->assertCreated();

        $titulo = Financeiro::query()->latest('id')->firstOrFail();

        $this->assertSame(Financeiro::STATUS_PAGO, $titulo->status);
        $this->assertSame(1, $titulo->movimentos()->count());
    }

    public function test_card_cannot_be_linked_to_a_receivable(): void
    {
        $cartao = $this->createCartao();

        $this->postJson('/api/v1/financeiro', [
            'tipo' => 'receber',
            'categoria' => 'Serviço',
            'descricao' => 'Recebimento indevido',
            'valor' => 10.00,
            'avulso' => true,
            'forma_pagamento' => 'cartao_credito',
            'cartao_credito_id' => $cartao->id,
            'data_compra' => '2026-08-10',
            'data_vencimento' => '2026-08-10',
        ])->assertStatus(422);
    }

    public function test_invoice_list_groups_expenses_and_splits_fixed_from_variable(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);

        // Mesma fatura (vencimento 20/09): uma fixa e uma variável.
        $this->createDespesaNoCartao($cartao, '2026-08-15', 100.00, 'Telefonia');
        $this->createDespesaNoCartao($cartao, '2026-09-05', 30.00, 'Compra de peças');
        // Fatura seguinte (vencimento 20/10).
        $this->createDespesaNoCartao($cartao, '2026-09-25', 70.00, 'Compra de peças');

        $faturas = app(FinanceiroCartaoCreditoService::class)->invoiceList($cartao);

        $this->assertCount(2, $faturas);

        $porVencimento = collect($faturas)->keyBy('data_vencimento');

        $this->assertSame(130.0, $porVencimento['2026-09-20']['total']);
        $this->assertSame(100.0, $porVencimento['2026-09-20']['total_fixas']);
        $this->assertSame(30.0, $porVencimento['2026-09-20']['total_variaveis']);
        $this->assertSame(2, $porVencimento['2026-09-20']['quantidade_despesas']);
        $this->assertSame('aberta', $porVencimento['2026-09-20']['status']);

        $this->assertSame(70.0, $porVencimento['2026-10-20']['total']);
    }

    public function test_paying_an_invoice_settles_every_linked_expense_at_once(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);
        $contaId = $this->createContaFinanceira();

        $fixa = $this->createDespesaNoCartao($cartao, '2026-08-15', 100.00, 'Telefonia');
        $variavel = $this->createDespesaNoCartao($cartao, '2026-09-05', 30.00, 'Compra de peças');
        // Fatura diferente: não pode ser tocada por esta baixa.
        $outraFatura = $this->createDespesaNoCartao($cartao, '2026-09-25', 70.00, 'Compra de peças');

        $response = $this->postJson(
            "/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-09-20/pagar",
            ['data_pagamento' => '2026-09-20', 'forma_pagamento' => 'pix', 'conta_financeira_id' => $contaId]
        );

        $response->assertOk()
            ->assertJsonPath('data.resultado.succeeded_count', 2)
            ->assertJsonPath('data.resultado.failed_count', 0)
            ->assertJsonPath('data.resultado.valor_baixado', 130.0);

        $this->assertSame(Financeiro::STATUS_PAGO, $fixa->refresh()->status);
        $this->assertSame(Financeiro::STATUS_PAGO, $variavel->refresh()->status);
        $this->assertSame(Financeiro::STATUS_PENDENTE, $outraFatura->refresh()->status);

        $this->assertSame('paga', collect(app(FinanceiroCartaoCreditoService::class)->invoiceList($cartao))
            ->firstWhere('data_vencimento', '2026-09-20')['status']);
    }

    /**
     * A fatura em si é uma despesa — a conta que efetivamente pagou N outras
     * despesas — mas antes disso ela não existia como lançamento nenhum: só
     * as despesas individuais apareciam nas listagens. Agora cada baixa em
     * lote deixa seu próprio recibo.
     */
    public function test_paying_an_invoice_creates_a_single_aggregate_receipt(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);
        $contaId = $this->createContaFinanceira();

        $this->createDespesaNoCartao($cartao, '2026-08-15', 100.00, 'Telefonia');
        $this->createDespesaNoCartao($cartao, '2026-09-05', 30.00, 'Compra de peças');

        $response = $this->postJson(
            "/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-09-20/pagar",
            ['data_pagamento' => '2026-09-20', 'forma_pagamento' => 'pix', 'conta_financeira_id' => $contaId]
        )->assertOk();

        $agregadorId = $response->json('data.resultado.financeiro_agregador_id');
        $this->assertNotNull($agregadorId);

        $agregador = Financeiro::query()->findOrFail($agregadorId);

        $this->assertSame(130.0, round((float) $agregador->valor, 2));
        $this->assertSame(Financeiro::STATUS_PAGO, $agregador->status);
        $this->assertSame(Financeiro::ORIGEM_TIPO_FATURA_CARTAO_CREDITO, $agregador->origem_tipo);
        $this->assertSame($cartao->id, $agregador->cartao_credito_id);
        // Modalidade NULL mantém o recibo fora da própria fatura que ele resume.
        $this->assertNull($agregador->cartao_modalidade);
        $this->assertFalse((bool) $agregador->impacta_dre);
        $this->assertFalse((bool) $agregador->impacta_fluxo_caixa);
        $this->assertFalse((bool) $agregador->dre_fixo_mensal);
        $this->assertSame(1, $agregador->movimentos()->count());
        $this->assertSame(0.0, round((float) app(FinanceiroService::class)->movementSummary($agregador)['valor_aberto'], 2));

        // O recibo não pode inflar o total nem a contagem de despesas da
        // própria fatura.
        $fatura = collect(app(FinanceiroCartaoCreditoService::class)->invoiceList($cartao))
            ->firstWhere('data_vencimento', '2026-09-20');
        $this->assertSame(130.0, $fatura['total']);
        $this->assertSame(2, $fatura['quantidade_despesas']);
    }

    /**
     * O recibo da fatura nao tem os_id (uma saida de caixa quita N compras, e
     * cada uma pode ter a sua OS), entao a tela de detalhe mostrava
     * "Sem OS vinculada" mesmo com despesas ligadas a OS. O detalhe do recibo
     * precisa devolver as despesas por tras dele, com a OS de cada uma.
     */
    public function test_invoice_payment_receipt_detail_lists_the_expenses_and_their_orders(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);
        $contaId = $this->createContaFinanceira();
        $clienteId = $this->createClientRecord();
        $equipamentoId = $this->createEquipmentRecord($clienteId);
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
        ]);

        $this->postJson('/api/v1/financeiro', [
            'tipo' => 'pagar',
            'categoria' => 'Compra de peças',
            'descricao' => 'Peça para o notebook',
            'valor' => 50.00,
            'forma_pagamento' => 'cartao_credito',
            'cartao_credito_id' => $cartao->id,
            'data_compra' => '2026-08-15',
            'data_vencimento' => '2026-08-15',
            'os_id' => $orderId,
        ])->assertCreated();
        $despesaComOs = Financeiro::query()->latest('id')->firstOrFail();

        $despesaSemOs = $this->createDespesaNoCartao($cartao, '2026-08-16', 25.00, 'Telefonia');

        $response = $this->postJson(
            "/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-09-20/pagar",
            ['data_pagamento' => '2026-09-20', 'forma_pagamento' => 'pix', 'conta_financeira_id' => $contaId]
        )->assertOk();

        $agregadorId = (int) $response->json('data.resultado.financeiro_agregador_id');
        $this->assertGreaterThan(0, $agregadorId);

        $detalhe = $this->getJson("/api/v1/financeiro/{$agregadorId}")
            ->assertOk()
            ->json('data.detalhes.fatura_cartao');

        $this->assertIsArray($detalhe);
        $this->assertSame(2, $detalhe['quantidade_despesas']);
        $this->assertSame(1, $detalhe['quantidade_com_os']);
        $this->assertSame(75.0, round((float) $detalhe['valor_total'], 2));
        $this->assertSame($cartao->id, $detalhe['cartao']['id']);

        $despesas = collect($detalhe['despesas'])->keyBy('id');
        $this->assertSame($orderId, $despesas[$despesaComOs->id]['os']['id']);
        $this->assertNull($despesas[$despesaSemOs->id]['os']);
        $this->assertSame(50.0, round((float) $despesas[$despesaComOs->id]['valor'], 2));

        // A propria fatura tambem passa a expor a OS de cada despesa.
        $fatura = $this->getJson("/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-09-20")
            ->assertOk()
            ->json('data.despesas');

        $this->assertSame(
            $orderId,
            collect($fatura)->firstWhere('id', $despesaComOs->id)['os']['id']
        );
    }

    /**
     * Titulo comum (sem cartao) nao pode ganhar o bloco de despesas da fatura:
     * ele continua caindo no "Sem OS vinculada" da tela.
     */
    public function test_a_regular_title_has_no_invoice_expense_block(): void
    {
        $this->postJson('/api/v1/financeiro', [
            'tipo' => 'pagar',
            'categoria' => 'Aluguel',
            'descricao' => 'Aluguel da loja',
            'valor' => 1200.00,
            'data_vencimento' => '2026-08-10',
        ])->assertCreated();

        $titulo = Financeiro::query()->latest('id')->firstOrFail();

        $this->getJson("/api/v1/financeiro/{$titulo->id}")
            ->assertOk()
            ->assertJsonPath('data.detalhes.fatura_cartao', null);
    }

    public function test_totais_fixo_variavel_are_not_inflated_by_the_invoice_payment_receipt(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);
        $contaId = $this->createContaFinanceira();

        $this->createDespesaNoCartao($cartao, '2026-08-15', 100.00, 'Telefonia');
        $this->createDespesaNoCartao($cartao, '2026-09-05', 30.00, 'Compra de peças');

        $this->postJson(
            "/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-09-20/pagar",
            ['conta_financeira_id' => $contaId]
        )->assertOk();

        // Sem a exclusão do recibo, "variaveis" viria 160 (30 da despesa +
        // 130 do recibo) — as despesas da fatura já são contadas aqui uma vez
        // cada, individualmente.
        $this->getJson('/api/v1/financeiro')
            ->assertOk()
            ->assertJsonPath('data.totais_despesas.fixas', 100.0)
            ->assertJsonPath('data.totais_despesas.variaveis', 30.0);
    }

    public function test_invoice_payment_receipt_appears_in_the_general_listing_but_not_in_fixed_or_variable_filters(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);
        $contaId = $this->createContaFinanceira();

        $this->createDespesaNoCartao($cartao, '2026-08-05', 80.00, 'Compra de peças');

        $this->postJson(
            "/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20/pagar",
            ['conta_financeira_id' => $contaId]
        )->assertOk();

        $temRecibo = static fn (array $lancamentos): bool => collect($lancamentos)
            ->contains(fn (array $l): bool => ($l['origem_tipo'] ?? null) === Financeiro::ORIGEM_TIPO_FATURA_CARTAO_CREDITO);

        $this->assertTrue($temRecibo($this->getJson('/api/v1/financeiro')->json('data.lancamentos')));
        // Não é fixa nem variável: sai das duas visões filtradas.
        $this->assertFalse($temRecibo($this->getJson('/api/v1/financeiro?dre_fixo_mensal=1')->json('data.lancamentos')));
        $this->assertFalse($temRecibo($this->getJson('/api/v1/financeiro?dre_fixo_mensal=0')->json('data.lancamentos')));
    }

    /**
     * Fatura paga é valor já conferido e quitado com o banco: lançar uma compra
     * esquecida nela mudaria um total fechado. A compra tem que ir para uma
     * fatura ainda aberta.
     */
    public function test_a_purchase_cannot_be_added_to_an_already_paid_invoice(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);
        $contaId = $this->createContaFinanceira();

        $this->createDespesaNoCartao($cartao, '2026-08-05', 100.00, 'Compra de peças');

        $this->postJson(
            "/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20/pagar",
            ['conta_financeira_id' => $contaId]
        )->assertOk();

        // Compra esquecida do mesmo ciclo (fecha 10/08), lançada depois.
        $this->postJson('/api/v1/financeiro', [
            'tipo' => 'pagar',
            'categoria' => 'Compra de peças',
            'descricao' => 'Compra esquecida',
            'valor' => 45.00,
            'forma_pagamento' => 'cartao_credito',
            'cartao_credito_id' => $cartao->id,
            'data_compra' => '2026-08-08',
            'data_vencimento' => '2026-08-08',
        ])->assertStatus(422)->assertJsonPath('error.code', 'FINANCEIRO_SAVE_FAILED');

        // O total da fatura paga continua intocado.
        $fatura = collect(app(FinanceiroCartaoCreditoService::class)->invoiceList($cartao))
            ->firstWhere('data_vencimento', '2026-08-20');
        $this->assertSame(100.0, $fatura['total']);
        $this->assertSame('paga', $fatura['status']);
    }

    /**
     * Válvula de escape do bloqueio acima: a compra que o banco cobrou na
     * fatura paga mas que ninguém lançou entra por um caminho próprio, já
     * quitada junto com a fatura — sem cancelar a baixa e pagar tudo de novo.
     */
    /**
     * A tela precisa dizer QUANDO a fatura foi paga, não só que foi. Enquanto
     * aberta a data é null — anunciar a data de um pagamento parcial como
     * "paga em" enganaria.
     */
    public function test_invoice_list_exposes_the_date_the_invoice_was_settled(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);
        $contaId = $this->createContaFinanceira();
        $service = app(FinanceiroCartaoCreditoService::class);

        $this->createDespesaNoCartao($cartao, '2026-08-05', 100.00, 'Compra de peças');

        $antes = collect($service->invoiceList($cartao))->firstWhere('data_vencimento', '2026-08-20');
        $this->assertSame('aberta', $antes['status']);
        $this->assertNull($antes['data_pagamento']);

        $this->postJson(
            "/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20/pagar",
            ['data_pagamento' => '2026-08-18', 'conta_financeira_id' => $contaId]
        )->assertOk();

        $depois = collect($service->invoiceList($cartao))->firstWhere('data_vencimento', '2026-08-20');
        $this->assertSame('paga', $depois['status']);
        $this->assertSame('2026-08-18', $depois['data_pagamento']);

        // O detalhe da fatura serve a mesma data para a tela.
        $this->getJson("/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20")
            ->assertOk()
            ->assertJsonPath('data.fatura.data_pagamento', '2026-08-18');
    }

    public function test_a_forgotten_expense_can_be_added_to_a_paid_invoice_already_settled(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);
        $contaId = $this->createContaFinanceira();

        $this->createDespesaNoCartao($cartao, '2026-08-05', 100.00, 'Compra de peças');

        $this->postJson(
            "/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20/pagar",
            ['data_pagamento' => '2026-08-20', 'forma_pagamento' => 'pix', 'conta_financeira_id' => $contaId]
        )->assertOk();

        $this->postJson("/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20/despesa-esquecida", [
            'categoria' => 'Compra de peças',
            'descricao' => 'Cabo que esqueci de lançar',
            'valor' => 45.00,
            'data_compra' => '2026-08-08',
        ])->assertCreated();

        $despesa = Financeiro::query()->latest('id')->firstOrFail();

        // Nasce quitada, no mesmo ponto do fluxo de caixa da baixa da fatura.
        $this->assertSame(Financeiro::STATUS_PAGO, $despesa->status);
        $this->assertSame('2026-08-20', $despesa->data_vencimento->toDateString());
        $this->assertSame('2026-08-08', $despesa->data_compra->toDateString());
        // Competência é a compra, não o pagamento da fatura.
        $this->assertSame('2026-08-08', $despesa->data_competencia->toDateString());

        $movimento = $despesa->movimentos()->firstOrFail();
        $this->assertSame('2026-08-20', $movimento->data_movimento->toDateString());
        $this->assertSame($contaId, (int) $movimento->conta_financeira_id);

        // O total da fatura se corrige e ela CONTINUA paga.
        $fatura = collect(app(FinanceiroCartaoCreditoService::class)->invoiceList($cartao))
            ->firstWhere('data_vencimento', '2026-08-20');
        $this->assertSame(145.0, $fatura['total']);
        $this->assertSame('paga', $fatura['status']);
        $this->assertSame(0.0, $fatura['total_em_aberto']);
    }

    /**
     * A janela [abertura, fechamento] é o que o calendário do modal aceita.
     * Fechar em 10 e vencer em 20 põe as duas pontas no mesmo mês; a abertura
     * é sempre o dia seguinte ao fechamento anterior.
     */
    public function test_invoice_list_exposes_the_window_in_which_the_invoice_accepted_purchases(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);
        $service = app(FinanceiroCartaoCreditoService::class);

        $this->createDespesaNoCartao($cartao, '2026-08-05', 100.00, 'Compra de peças');

        $fatura = collect($service->invoiceList($cartao))->firstWhere('data_vencimento', '2026-08-20');

        $this->assertSame('2026-07-11', $fatura['data_abertura']);
        $this->assertSame('2026-08-10', $fatura['data_fechamento']);

        // A janela tem que casar com o cálculo do ciclo: comprar na abertura ou
        // no fechamento cai nesta fatura; um dia antes da abertura, não.
        foreach (['2026-07-11', '2026-08-10'] as $dentro) {
            $this->assertSame(
                '2026-08-20',
                $service->resolveInvoiceCycle($cartao, CarbonImmutable::parse($dentro))['data_vencimento'],
                $dentro.' deveria cair nesta fatura'
            );
        }

        $this->assertNotSame(
            '2026-08-20',
            $service->resolveInvoiceCycle($cartao, CarbonImmutable::parse('2026-07-10'))['data_vencimento']
        );
    }

    /**
     * Fechamento 31 em março: a abertura tem que ser 1º de março (dia seguinte
     * a 28/29 de fevereiro), não transbordar para uma data inexistente.
     */
    public function test_the_invoice_window_clamps_the_previous_closing_on_short_months(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 31, 'dia_vencimento' => 10]);

        $this->assertSame(
            '2026-03-01',
            app(FinanceiroCartaoCreditoService::class)
                ->openingDateForDueDate($cartao, CarbonImmutable::parse('2026-04-10'))
        );
    }

    public function test_a_forgotten_expense_is_refused_when_the_purchase_falls_in_another_invoice(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);
        $contaId = $this->createContaFinanceira();

        $this->createDespesaNoCartao($cartao, '2026-08-05', 100.00, 'Compra de peças');
        $this->postJson(
            "/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20/pagar",
            ['conta_financeira_id' => $contaId]
        )->assertOk();

        // 15/08 é depois do fechamento (10/08): cai na fatura de 20/09.
        $this->postJson("/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20/despesa-esquecida", [
            'categoria' => 'Compra de peças',
            'descricao' => 'Compra de outro ciclo',
            'valor' => 45.00,
            'data_compra' => '2026-08-15',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FINANCEIRO_CARTAO_CREDITO_DESPESA_ESQUECIDA_FAILED');
    }

    public function test_a_forgotten_expense_is_refused_on_an_invoice_that_is_still_open(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);

        $this->createDespesaNoCartao($cartao, '2026-08-05', 100.00, 'Compra de peças');

        // Fatura em aberto: o caminho é o cadastro normal, não esta exceção.
        $this->postJson("/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20/despesa-esquecida", [
            'categoria' => 'Compra de peças',
            'descricao' => 'Deveria ir pelo cadastro normal',
            'valor' => 45.00,
            'data_compra' => '2026-08-08',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FINANCEIRO_CARTAO_CREDITO_DESPESA_ESQUECIDA_FAILED');
    }

    /**
     * A trava é da fatura PAGA, não da data passada: fatura vencida e ainda em
     * aberto continua aceitando o lançamento esquecido.
     */
    public function test_a_purchase_can_still_be_added_to_an_overdue_but_unpaid_invoice(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);

        $this->createDespesaNoCartao($cartao, '2026-08-05', 100.00, 'Compra de peças');
        $this->createDespesaNoCartao($cartao, '2026-08-08', 45.00, 'Compra de peças');

        $fatura = collect(app(FinanceiroCartaoCreditoService::class)->invoiceList($cartao))
            ->firstWhere('data_vencimento', '2026-08-20');

        $this->assertSame('aberta', $fatura['status']);
        $this->assertSame(145.0, $fatura['total']);
    }

    /**
     * Alimenta o "min" do calendário: primeira data que ainda cai em fatura
     * aberta é o dia seguinte ao FECHAMENTO da última fatura paga (não ao
     * vencimento dela) — comprar no dia do fechamento ainda entra naquela.
     */
    public function test_minimum_purchase_date_is_the_day_after_the_last_paid_cycle_closed(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);
        $contaId = $this->createContaFinanceira();
        $service = app(FinanceiroCartaoCreditoService::class);

        // Sem fatura paga não há restrição nenhuma.
        $this->createDespesaNoCartao($cartao, '2026-08-05', 100.00, 'Compra de peças');
        $this->assertNull($service->minimumPurchaseDate($cartao));

        $this->postJson(
            "/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20/pagar",
            ['conta_financeira_id' => $contaId]
        )->assertOk();

        // Ciclo pago fechou em 10/08 -> a partir de 11/08 cai na próxima fatura.
        $this->assertSame('2026-08-11', $service->minimumPurchaseDate($cartao));
    }

    public function test_preview_exposes_the_minimum_purchase_date_and_whether_the_invoice_is_paid(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);
        $contaId = $this->createContaFinanceira();

        $this->createDespesaNoCartao($cartao, '2026-08-05', 100.00, 'Compra de peças');
        $this->postJson(
            "/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20/pagar",
            ['conta_financeira_id' => $contaId]
        )->assertOk();

        // Data que cai na fatura já paga.
        $this->getJson("/api/v1/financeiro/cartoes-credito/{$cartao->id}/prever-fatura?data_compra=2026-08-08")
            ->assertOk()
            ->assertJsonPath('data.fatura.data_vencimento', '2026-08-20')
            ->assertJsonPath('data.fatura.fatura_paga', true)
            ->assertJsonPath('data.fatura.compra_minima', '2026-08-11');

        // Data que cai na fatura seguinte, ainda aberta.
        $this->getJson("/api/v1/financeiro/cartoes-credito/{$cartao->id}/prever-fatura?data_compra=2026-08-15")
            ->assertOk()
            ->assertJsonPath('data.fatura.data_vencimento', '2026-09-20')
            ->assertJsonPath('data.fatura.fatura_paga', false);
    }

    /**
     * Estornar a baixa devolve as despesas para "pendente" (elas continuam
     * devidas — o que deixou de valer foi o pagamento) e cancela o recibo.
     */
    /**
     * O recibo é gerenciado pela fatura, não pelas telas genéricas de
     * Lançamentos. Salvar pelo formulário comum apagava o vínculo com o cartão
     * (resolveClassification() zera cartao_credito_id quando o payload não traz
     * cartão) e devolvia o título para "pendente" — a fatura paga passava a
     * parecer não registrada.
     */
    public function test_the_invoice_payment_receipt_cannot_be_touched_by_the_generic_endpoints(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);
        $contaId = $this->createContaFinanceira();

        $this->createDespesaNoCartao($cartao, '2026-08-05', 100.00, 'Compra de peças');

        $reciboId = $this->postJson(
            "/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20/pagar",
            ['conta_financeira_id' => $contaId]
        )->assertOk()->json('data.resultado.financeiro_agregador_id');

        $this->patchJson("/api/v1/financeiro/{$reciboId}", [
            'tipo' => 'pagar',
            'categoria' => 'Outra coisa',
            'descricao' => 'Editado por fora',
            'valor' => 10.00,
            'data_vencimento' => '2026-08-18',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'FINANCEIRO_UPDATE_BLOCKED_RECIBO_FATURA');

        $this->postJson("/api/v1/financeiro/{$reciboId}/cancelar")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'FINANCEIRO_CANCEL_BLOCKED_RECIBO_FATURA');

        $this->deleteJson("/api/v1/financeiro/{$reciboId}", $this->adminCredentials())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'FINANCEIRO_DELETE_BLOCKED_RECIBO_FATURA');

        $this->postJson("/api/v1/financeiro/{$reciboId}/baixar", ['valor_movimento' => 10.00])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'FINANCEIRO_BAIXA_BLOCKED_RECIBO_FATURA');

        // Continua íntegro: pago, com o vínculo do cartão e o movimento.
        $recibo = Financeiro::query()->findOrFail($reciboId);
        $this->assertSame(Financeiro::STATUS_PAGO, $recibo->status);
        $this->assertSame($cartao->id, $recibo->cartao_credito_id);
        $this->assertSame('2026-08-20', $recibo->data_vencimento->toDateString());
        $this->assertSame(100.0, round((float) $recibo->valor, 2));
        $this->assertSame(1, $recibo->movimentos()->count());
    }

    public function test_cancelling_an_invoice_payment_reopens_the_expenses_and_cancels_the_receipt(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);
        $contaId = $this->createContaFinanceira();

        $fixa = $this->createDespesaNoCartao($cartao, '2026-08-15', 100.00, 'Telefonia');
        $variavel = $this->createDespesaNoCartao($cartao, '2026-09-05', 30.00, 'Compra de peças');

        $reciboId = $this->postJson(
            "/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-09-20/pagar",
            ['conta_financeira_id' => $contaId]
        )->assertOk()->json('data.resultado.financeiro_agregador_id');

        $this->postJson("/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-09-20/cancelar-baixa", $this->adminCredentials())
            ->assertOk()
            ->assertJsonPath('data.resultado.despesas_estornadas', 2)
            ->assertJsonPath('data.resultado.recibos_cancelados', 1)
            ->assertJsonPath('data.resultado.valor_estornado', 130.0);

        // Despesas voltam a dever, sem movimento nenhum.
        foreach ([$fixa, $variavel] as $despesa) {
            $despesa->refresh();
            $this->assertSame(Financeiro::STATUS_PENDENTE, $despesa->status);
            $this->assertNull($despesa->data_pagamento);
            $this->assertSame(0, $despesa->movimentos()->count());
        }

        // Recibo fica cancelado (não apagado): o histórico mostra que houve um
        // pagamento e que ele foi estornado.
        $recibo = Financeiro::query()->findOrFail($reciboId);
        $this->assertSame(Financeiro::STATUS_CANCELADO, $recibo->status);
        $this->assertSame(0, $recibo->movimentos()->count());

        // A fatura volta a ficar aberta, com o saldo cheio.
        $fatura = collect(app(FinanceiroCartaoCreditoService::class)->invoiceList($cartao))
            ->firstWhere('data_vencimento', '2026-09-20');
        $this->assertSame('aberta', $fatura['status']);
        $this->assertSame(130.0, $fatura['total_em_aberto']);
    }

    public function test_an_invoice_can_be_paid_again_after_its_payment_was_cancelled(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);
        $contaId = $this->createContaFinanceira();

        $this->createDespesaNoCartao($cartao, '2026-08-05', 100.00, 'Compra de peças');

        $primeiroRecibo = $this->postJson(
            "/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20/pagar",
            ['conta_financeira_id' => $contaId]
        )->assertOk()->json('data.resultado.financeiro_agregador_id');

        $this->postJson("/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20/cancelar-baixa", $this->adminCredentials())
            ->assertOk();

        $segundoRecibo = $this->postJson(
            "/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20/pagar",
            ['conta_financeira_id' => $contaId]
        )->assertOk()
            ->assertJsonPath('data.resultado.valor_baixado', 100.0)
            ->json('data.resultado.financeiro_agregador_id');

        // Recibo novo, e o cancelado continua no histórico.
        $this->assertNotSame($primeiroRecibo, $segundoRecibo);
        $this->assertSame(Financeiro::STATUS_CANCELADO, Financeiro::query()->findOrFail($primeiroRecibo)->status);
        $this->assertSame(Financeiro::STATUS_PAGO, Financeiro::query()->findOrFail($segundoRecibo)->status);
    }

    public function test_cancelling_the_payment_of_an_unpaid_invoice_fails(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);

        $despesa = $this->createDespesaNoCartao($cartao, '2026-08-05', 100.00, 'Compra de peças');

        $this->postJson("/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20/cancelar-baixa", $this->adminCredentials())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FINANCEIRO_CARTAO_CREDITO_FATURA_ESTORNO_FAILED');

        $this->assertSame(Financeiro::STATUS_PENDENTE, $despesa->refresh()->status);
    }

    /**
     * Estorno mexe em dinheiro já conciliado: além da permissão do operador,
     * exige confirmação de um administrador — mesma regra de excluir/cancelar
     * um lançamento.
     */
    public function test_cancelling_an_invoice_payment_requires_admin_credentials(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);
        $contaId = $this->createContaFinanceira();

        $despesa = $this->createDespesaNoCartao($cartao, '2026-08-05', 100.00, 'Compra de peças');

        $this->postJson(
            "/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20/pagar",
            ['conta_financeira_id' => $contaId]
        )->assertOk();

        // Sem credencial nenhuma: barra na validação.
        $this->postJson("/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20/cancelar-baixa")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        // Senha errada de um admin real.
        $this->postJson("/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20/cancelar-baixa", [
            'admin_email' => $this->adminCredentials()['admin_email'],
            'admin_password' => 'senha-errada',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FINANCEIRO_ADMIN_AUTH_INVALID');

        // Credencial válida, mas de quem não é administrador.
        $naoAdmin = $this->createUserRecord(['perfil' => 'atendente', 'grupo_id' => 1]);
        $this->postJson("/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20/cancelar-baixa", [
            'admin_email' => $naoAdmin->email,
            'admin_password' => 'Senha@123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FINANCEIRO_ADMIN_AUTH_INVALID');

        // Nada foi estornado em nenhuma das tentativas.
        $this->assertSame(Financeiro::STATUS_PAGO, $despesa->refresh()->status);
    }

    public function test_cancelling_an_invoice_payment_requires_permission_to_edit_accounts(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);
        $contaId = $this->createContaFinanceira();

        $despesa = $this->createDespesaNoCartao($cartao, '2026-08-05', 100.00, 'Compra de peças');

        $this->postJson(
            "/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20/pagar",
            ['conta_financeira_id' => $contaId]
        )->assertOk();

        // Revoga só o "editar" de contas_saldos, mantendo o "visualizar" —
        // mesmo padrão de test_accounts_permission_gates_the_card_registry().
        $moduleId = (int) DB::table('modulos')->where('slug', 'contas_saldos')->value('id');
        $editarId = (int) DB::table('permissoes')->where('slug', 'editar')->value('id');

        DB::table('grupo_permissoes')
            ->where('grupo_id', 1)
            ->where('modulo_id', $moduleId)
            ->where('permissao_id', $editarId)
            ->delete();
        app(\App\Services\Auth\RbacAuthorizationService::class)->forgetUser($this->authenticatedUserId);

        $this->postJson("/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20/cancelar-baixa", $this->adminCredentials())
            ->assertForbidden();

        $this->assertSame(Financeiro::STATUS_PAGO, $despesa->refresh()->status);
    }

    public function test_paying_an_invoice_without_open_expenses_fails(): void
    {
        $cartao = $this->createCartao();

        $this->postJson("/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-09-20/pagar", [])
            ->assertStatus(422);

        // Chamada que não liquidou nada não deixa recibo de R$ 0,00.
        $this->assertSame(
            0,
            Financeiro::query()->where('origem_tipo', Financeiro::ORIGEM_TIPO_FATURA_CARTAO_CREDITO)->count()
        );
    }

    public function test_card_cannot_be_swapped_after_the_expense_was_settled(): void
    {
        $cartao = $this->createCartao(['nome' => 'Cartão A']);
        $outroCartao = $this->createCartao(['nome' => 'Cartão B']);
        $contaId = $this->createContaFinanceira();

        $despesa = $this->createDespesaNoCartao($cartao, '2026-08-05', 100.00, 'Compra de peças');

        // Despesa no crédito só é liquidada pela fatura (baixa individual é
        // bloqueada — ver test_paying_a_credit_card_expense_individually_is_blocked).
        $this->postJson("/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20/pagar", [
            'forma_pagamento' => 'pix',
            'conta_financeira_id' => $contaId,
        ])->assertOk();

        $this->patchJson("/api/v1/financeiro/{$despesa->id}", [
            'cartao_credito_id' => $outroCartao->id,
        ])->assertStatus(422);
    }

    public function test_repeating_a_fixed_expense_on_a_card_recomputes_each_invoice_cycle(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);

        // Compra em 15/08 (após o fechamento do dia 10) -> 1ª fatura vence
        // 20/09; as repetições seguem o ciclo mês a mês.
        $this->postJson('/api/v1/financeiro', [
            'tipo' => 'pagar',
            'categoria' => 'Telefonia',
            'descricao' => 'Plano de celular',
            'valor' => 89.90,
            'forma_pagamento' => 'cartao_credito',
            'cartao_credito_id' => $cartao->id,
            'data_compra' => '2026-08-15',
            'data_vencimento' => '2026-08-15',
            'dre_fixo_mensal' => true,
            'repetir_proximos_meses' => true,
        ])->assertCreated();

        $vencimentos = Financeiro::query()
            ->where('cartao_credito_id', $cartao->id)
            ->orderBy('data_vencimento')
            ->pluck('data_vencimento')
            ->map(static fn ($data): string => CarbonImmutable::parse($data)->toDateString())
            ->all();

        $this->assertSame('2026-09-20', $vencimentos[0]);
        $this->assertSame('2026-10-20', $vencimentos[1]);
        $this->assertSame('2026-11-20', $vencimentos[2]);

        // Toda cópia herda o cartão e mantém data_compra própria (é a compra
        // que se repete todo mês, não o vencimento).
        $this->assertSame(
            0,
            Financeiro::query()->where('cartao_credito_id', $cartao->id)->whereNull('data_compra')->count()
        );
    }

    public function test_debit_purchase_is_due_on_the_purchase_day_and_never_joins_an_invoice(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);

        // Compra no débito depois do fechamento: no crédito iria para a fatura
        // de 20/09, mas no débito o dinheiro sai da conta na hora.
        $this->postJson('/api/v1/financeiro', [
            'tipo' => 'pagar',
            'categoria' => 'Compra de peças',
            'descricao' => 'Cola T7000 no débito',
            'valor' => 25.00,
            'forma_pagamento' => 'cartao_debito',
            'cartao_credito_id' => $cartao->id,
            'data_compra' => '2026-08-15',
            'data_vencimento' => '2026-08-15',
        ])->assertCreated();

        $despesa = Financeiro::query()->latest('id')->first();
        $this->assertSame('2026-08-15', $despesa->data_vencimento->toDateString());
        $this->assertSame($cartao->id, $despesa->cartao_credito_id);

        // Não compõe fatura: a listagem de faturas do cartão ignora o débito.
        $this->assertSame([], app(FinanceiroCartaoCreditoService::class)->invoiceList($cartao));
    }

    /**
     * Despesa lançada no crédito entra em fatura (ver MODALIDADE_CREDITO) —
     * baixar, cancelar ou excluir pelo lançamento individual bypassaria o
     * ciclo real da fatura, então o controller passa a bloquear essas três
     * ações e devolve o gerenciamento para a fatura.
     */
    public function test_paying_a_credit_card_expense_individually_is_blocked(): void
    {
        $cartao = $this->createCartao();
        $despesa = $this->createDespesaNoCartao($cartao, '2026-08-15', 150.00, 'Compra de peças');

        $this->postJson("/api/v1/financeiro/{$despesa->id}/baixar", [
            'valor_movimento' => 150.00,
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'FINANCEIRO_BAIXA_BLOCKED_CARTAO_FATURA');

        $this->assertDatabaseHas('financeiro', ['id' => $despesa->id, 'status' => 'pendente']);
    }

    public function test_cancelling_a_credit_card_expense_individually_is_blocked(): void
    {
        $cartao = $this->createCartao();
        $despesa = $this->createDespesaNoCartao($cartao, '2026-08-15', 150.00, 'Compra de peças');

        $this->postJson("/api/v1/financeiro/{$despesa->id}/cancelar")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'FINANCEIRO_CANCEL_BLOCKED_CARTAO_FATURA');

        $this->assertDatabaseHas('financeiro', ['id' => $despesa->id, 'status' => 'pendente']);
    }

    public function test_deleting_a_credit_card_expense_individually_is_blocked(): void
    {
        $cartao = $this->createCartao();
        $despesa = $this->createDespesaNoCartao($cartao, '2026-08-15', 150.00, 'Compra de peças');

        $this->deleteJson("/api/v1/financeiro/{$despesa->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'FINANCEIRO_DELETE_BLOCKED_CARTAO_FATURA');

        $this->assertDatabaseHas('financeiro', ['id' => $despesa->id]);
    }

    /**
     * Débito nunca entra em fatura (o dinheiro já saiu da conta na compra),
     * então o bloqueio é exclusivo do crédito — confirma que a baixa
     * individual continua funcionando para despesa de débito.
     */
    public function test_debit_card_expense_can_still_be_paid_individually(): void
    {
        $cartao = $this->createCartao();

        $this->postJson('/api/v1/financeiro', [
            'tipo' => 'pagar',
            'categoria' => 'Compra de peças',
            'descricao' => 'Cola T7000 no débito',
            'valor' => 25.00,
            'forma_pagamento' => 'cartao_debito',
            'cartao_credito_id' => $cartao->id,
            'data_compra' => '2026-08-15',
            'data_vencimento' => '2026-08-15',
        ])->assertCreated();

        $despesa = Financeiro::query()->latest('id')->firstOrFail();

        $this->postJson("/api/v1/financeiro/{$despesa->id}/baixar", [
            'valor_movimento' => 25.00,
        ])->assertOk();

        $this->assertDatabaseHas('financeiro', ['id' => $despesa->id, 'status' => 'pago']);
    }

    public function test_invoice_totals_ignore_debit_purchases_on_the_same_card(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);

        $this->createDespesaNoCartao($cartao, '2026-08-15', 100.00, 'Telefonia');
        // Mesmo cartão, mas no débito — não pode entrar no total da fatura.
        $this->postJson('/api/v1/financeiro', [
            'tipo' => 'pagar',
            'categoria' => 'Compra de peças',
            'descricao' => 'Compra no débito',
            'valor' => 999.00,
            'forma_pagamento' => 'cartao_debito',
            'cartao_credito_id' => $cartao->id,
            'data_compra' => '2026-08-16',
            'data_vencimento' => '2026-08-16',
        ])->assertCreated();

        $faturas = app(FinanceiroCartaoCreditoService::class)->invoiceList($cartao);

        $this->assertCount(1, $faturas);
        $this->assertSame(100.0, $faturas[0]['total']);
    }

    public function test_card_requires_a_card_payment_method(): void
    {
        $cartao = $this->createCartao();

        $this->postJson('/api/v1/financeiro', [
            'tipo' => 'pagar',
            'categoria' => 'Compra de peças',
            'descricao' => 'Pix vinculado a cartão não faz sentido',
            'valor' => 10.00,
            'forma_pagamento' => 'pix',
            'cartao_credito_id' => $cartao->id,
            'data_compra' => '2026-08-15',
            'data_vencimento' => '2026-08-15',
        ])->assertStatus(422);
    }

    public function test_card_can_be_linked_to_a_financial_account(): void
    {
        $contaId = $this->createContaFinanceira();

        $response = $this->postJson('/api/v1/financeiro/cartoes-credito', [
            'nome' => 'Cartão com conta',
            'dia_fechamento' => 10,
            'dia_vencimento' => 20,
            'conta_financeira_id' => $contaId,
        ]);

        $response->assertCreated();

        $cartoes = app(FinanceiroCartaoCreditoService::class)->list();
        $cartao = collect($cartoes)->firstWhere('nome', 'Cartão com conta');

        $this->assertSame($contaId, $cartao['conta_financeira_id']);
        $this->assertSame('Conta corrente', $cartao['conta_financeira_nome']);
    }

    /**
     * Caso do usuário: ar-condicionado comprado em 12x. O total é dividido e
     * cada parcela cai numa fatura consecutiva — nunca tudo na mesma.
     */
    public function test_installments_split_the_total_across_consecutive_invoices(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);

        $this->postJson('/api/v1/financeiro', [
            'tipo' => 'pagar',
            'categoria' => 'Compra de peças',
            'descricao' => 'Ar-condicionado novo',
            'valor' => 3600.00,
            'forma_pagamento' => 'cartao_credito',
            'cartao_credito_id' => $cartao->id,
            'data_compra' => '2026-08-05',
            'data_vencimento' => '2026-08-05',
            'parcelas' => 12,
        ])->assertCreated();

        $parcelas = Financeiro::query()
            ->where('cartao_credito_id', $cartao->id)
            ->orderBy('cartao_parcela_numero')
            ->get();

        $this->assertCount(12, $parcelas);
        $this->assertSame(3600.0, round($parcelas->sum('valor'), 2));
        $this->assertSame(300.0, round((float) $parcelas->first()->valor, 2));

        // Compra em 05/08 fecha em 10/08 -> 1ª vence 20/08; as demais seguem
        // faturas consecutivas.
        $vencimentos = $parcelas->map(fn ($p): string => $p->data_vencimento->toDateString())->all();
        $this->assertSame('2026-08-20', $vencimentos[0]);
        $this->assertSame('2026-09-20', $vencimentos[1]);
        $this->assertSame('2027-07-20', $vencimentos[11]);

        // Uma fatura por parcela — nenhuma duplicada.
        $this->assertSame(12, count(array_unique($vencimentos)));

        // Toda parcela mantém a competência na data da compra: o gasto foi
        // assumido de uma vez, só o pagamento é que se espalha.
        $this->assertSame(
            0,
            Financeiro::query()->where('cartao_credito_id', $cartao->id)
                ->whereDate('data_competencia', '!=', '2026-08-05')->count()
        );
    }

    public function test_installment_split_keeps_every_cent_of_the_total(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);

        // 100 / 3 não é exato: a 1ª parcela absorve o centavo restante.
        $this->postJson('/api/v1/financeiro', [
            'tipo' => 'pagar',
            'categoria' => 'Compra de peças',
            'descricao' => 'Compra quebrada',
            'valor' => 100.00,
            'forma_pagamento' => 'cartao_credito',
            'cartao_credito_id' => $cartao->id,
            'data_compra' => '2026-08-05',
            'data_vencimento' => '2026-08-05',
            'parcelas' => 3,
        ])->assertCreated();

        $valores = Financeiro::query()
            ->where('cartao_credito_id', $cartao->id)
            ->orderBy('cartao_parcela_numero')
            ->pluck('valor')
            ->map(static fn ($v): float => round((float) $v, 2))
            ->all();

        $this->assertSame([33.34, 33.33, 33.33], $valores);
        $this->assertSame(100.0, round(array_sum($valores), 2));
    }

    /**
     * Regressão do cálculo: com fechamento 28 e compra no dia 29, "compra + 1
     * mês" cairia em 28/fev e voltaria para a MESMA fatura da 1ª parcela.
     */
    public function test_installments_never_collide_on_short_months(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 28, 'dia_vencimento' => 5]);

        $vencimentos = app(FinanceiroCartaoCreditoService::class)
            ->installmentDueDates($cartao, CarbonImmutable::parse('2026-01-29'), 4);

        $this->assertSame(count($vencimentos), count(array_unique($vencimentos)));
        $this->assertSame(['2026-03-05', '2026-04-05', '2026-05-05', '2026-06-05'], $vencimentos);
    }

    public function test_installments_only_apply_to_credit_not_debit(): void
    {
        $cartao = $this->createCartao();

        $this->postJson('/api/v1/financeiro', [
            'tipo' => 'pagar',
            'categoria' => 'Compra de peças',
            'descricao' => 'Compra no débito',
            'valor' => 300.00,
            'forma_pagamento' => 'cartao_debito',
            'cartao_credito_id' => $cartao->id,
            'data_compra' => '2026-08-05',
            'data_vencimento' => '2026-08-05',
            'parcelas' => 3,
        ])->assertCreated();

        // Débito é à vista: um título só, com o valor cheio.
        $titulos = Financeiro::query()->where('cartao_credito_id', $cartao->id)->get();
        $this->assertCount(1, $titulos);
        $this->assertSame(300.0, round((float) $titulos->first()->valor, 2));
        $this->assertNull($titulos->first()->cartao_parcelas_total);
    }

    public function test_each_installment_lands_alone_in_its_invoice(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);

        $this->postJson('/api/v1/financeiro', [
            'tipo' => 'pagar',
            'categoria' => 'Compra de peças',
            'descricao' => 'Ar-condicionado novo',
            'valor' => 1200.00,
            'forma_pagamento' => 'cartao_credito',
            'cartao_credito_id' => $cartao->id,
            'data_compra' => '2026-08-05',
            'data_vencimento' => '2026-08-05',
            'parcelas' => 4,
        ])->assertCreated();

        $faturas = app(FinanceiroCartaoCreditoService::class)->invoiceList($cartao);

        $this->assertCount(4, $faturas);
        foreach ($faturas as $fatura) {
            $this->assertSame(300.0, $fatura['total']);
            $this->assertSame(1, $fatura['quantidade_despesas']);
        }
    }

    public function test_detail_exposes_the_card_purchase_information(): void
    {
        $cartaoId = $this->createContaFinanceira();
        $cartao = $this->createCartao([
            'nome' => 'Nubank PJ',
            'final_cartao' => '4321',
            'dia_fechamento' => 10,
            'dia_vencimento' => 20,
            'conta_financeira_id' => $cartaoId,
        ]);

        $this->postJson('/api/v1/financeiro', [
            'tipo' => 'pagar',
            'categoria' => 'Compra de peças',
            'descricao' => 'Ar-condicionado novo',
            'valor' => 1200.00,
            'forma_pagamento' => 'cartao_credito',
            'cartao_credito_id' => $cartao->id,
            'data_compra' => '2026-08-05',
            'data_vencimento' => '2026-08-05',
            'parcelas' => 4,
        ])->assertCreated();

        $primeira = Financeiro::query()->where('cartao_parcela_numero', 1)->firstOrFail();

        $response = $this->getJson('/api/v1/financeiro/'.$primeira->id);

        $response->assertOk()
            ->assertJsonPath('data.detalhes.cartao_credito.nome', 'Nubank PJ')
            ->assertJsonPath('data.detalhes.cartao_credito.final_cartao', '4321')
            ->assertJsonPath('data.detalhes.cartao_credito.modalidade_label', 'Cartão de crédito')
            ->assertJsonPath('data.detalhes.cartao_credito.data_compra', '2026-08-05')
            ->assertJsonPath('data.detalhes.cartao_credito.fatura_vencimento', '2026-08-20')
            ->assertJsonPath('data.detalhes.cartao_credito.parcela_numero', 1)
            ->assertJsonPath('data.detalhes.cartao_credito.parcelas_total', 4)
            ->assertJsonPath('data.detalhes.cartao_credito.conta_financeira_nome', 'Conta corrente');
    }

    public function test_detail_of_a_debit_purchase_has_no_invoice_due_date(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);

        $this->postJson('/api/v1/financeiro', [
            'tipo' => 'pagar',
            'categoria' => 'Compra de peças',
            'descricao' => 'Cola no débito',
            'valor' => 25.00,
            'forma_pagamento' => 'cartao_debito',
            'cartao_credito_id' => $cartao->id,
            'data_compra' => '2026-08-15',
            'data_vencimento' => '2026-08-15',
        ])->assertCreated();

        $despesa = Financeiro::query()->latest('id')->firstOrFail();

        $this->getJson('/api/v1/financeiro/'.$despesa->id)
            ->assertOk()
            ->assertJsonPath('data.detalhes.cartao_credito.modalidade_label', 'Cartão de débito')
            // Débito não tem fatura: o valor saiu da conta no dia da compra.
            ->assertJsonPath('data.detalhes.cartao_credito.fatura_vencimento', null)
            ->assertJsonPath('data.detalhes.cartao_credito.parcelas_total', null);
    }

    public function test_detail_has_no_card_block_for_a_regular_expense(): void
    {
        $this->postJson('/api/v1/financeiro', [
            'tipo' => 'pagar',
            'categoria' => 'Compra de peças',
            'descricao' => 'Compra no dinheiro',
            'valor' => 30.00,
            'data_vencimento' => '2026-08-15',
        ])->assertCreated();

        $despesa = Financeiro::query()->latest('id')->firstOrFail();

        $this->getJson('/api/v1/financeiro/'.$despesa->id)
            ->assertOk()
            ->assertJsonPath('data.detalhes.cartao_credito', null);
    }

    public function test_invoices_are_listed_from_the_current_one_forward(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);

        $this->createDespesaNoCartao($cartao, '2026-08-05', 10.00, 'Compra de peças');
        $this->createDespesaNoCartao($cartao, '2026-10-05', 30.00, 'Compra de peças');
        $this->createDespesaNoCartao($cartao, '2026-09-05', 20.00, 'Compra de peças');

        $faturas = app(FinanceiroCartaoCreditoService::class)->invoiceList($cartao);

        // Crescente: a mais próxima de vencer primeiro.
        $this->assertSame(
            ['2026-08-20', '2026-09-20', '2026-10-20'],
            array_column($faturas, 'data_vencimento')
        );
    }

    public function test_overdue_open_invoice_is_flagged(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);

        // Compra bem no passado: a fatura já venceu e continua pendente.
        $this->createDespesaNoCartao($cartao, '2020-01-05', 40.00, 'Compra de peças');

        $faturas = app(FinanceiroCartaoCreditoService::class)->invoiceList($cartao);

        $this->assertTrue($faturas[0]['vencida']);
        $this->assertSame('aberta', $faturas[0]['status']);
    }

    public function test_invoice_filters_narrow_the_list(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);

        $this->createDespesaNoCartao($cartao, '2026-08-05', 10.00, 'Compra de peças');
        $this->createDespesaNoCartao($cartao, '2026-09-05', 20.00, 'Compra de peças');
        $this->createDespesaNoCartao($cartao, '2026-10-05', 30.00, 'Compra de peças');

        $service = app(FinanceiroCartaoCreditoService::class);

        $porMes = $service->invoiceList($cartao, ['mes' => '2026-09']);
        $this->assertSame(['2026-09-20'], array_column($porMes, 'data_vencimento'));

        $porPeriodo = $service->invoiceList($cartao, [
            'vencimento_de' => '2026-09-01',
            'vencimento_ate' => '2026-10-31',
        ]);
        $this->assertSame(['2026-09-20', '2026-10-20'], array_column($porPeriodo, 'data_vencimento'));

        $pagas = $service->invoiceList($cartao, ['situacao' => 'paga']);
        $this->assertSame([], $pagas);

        $abertas = $service->invoiceList($cartao, ['situacao' => 'aberta']);
        $this->assertCount(3, $abertas);
    }

    public function test_current_invoice_is_the_nearest_open_one(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);
        $contaId = $this->createContaFinanceira();

        $primeira = $this->createDespesaNoCartao($cartao, '2026-08-05', 10.00, 'Compra de peças');
        $this->createDespesaNoCartao($cartao, '2026-09-05', 20.00, 'Compra de peças');

        $service = app(FinanceiroCartaoCreditoService::class);

        $atual = $service->currentInvoice($service->invoiceList($cartao));
        $this->assertSame('2026-08-20', $atual['data_vencimento']);

        // Paga a primeira: a atual passa a ser a seguinte.
        $this->postJson("/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20/pagar", [
            'conta_financeira_id' => $contaId,
        ])->assertOk();

        $atualDepois = $service->currentInvoice($service->invoiceList($cartao));
        $this->assertSame('2026-09-20', $atualDepois['data_vencimento']);
        $this->assertSame(Financeiro::STATUS_PAGO, $primeira->refresh()->status);
    }

    /**
     * A tela de fatura (fatura-show) é alcançada tanto pela lista de faturas
     * quanto pelo link "Registrar baixa" de uma despesa na listagem geral do
     * financeiro — o botão "Marcar fatura como paga" precisa aparecer só
     * quando a fatura é a corrente ou está vencida (mesma regra de
     * faturas.blade.php), senão dava para pagar uma fatura futura ainda
     * recebendo compras chegando por esse caminho alternativo.
     */
    public function test_invoice_detail_exposes_whether_it_can_be_paid(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);

        // Fatura corrente (mais próxima em aberto).
        $this->createDespesaNoCartao($cartao, '2026-08-05', 10.00, 'Compra de peças');
        // Fatura futura: ainda recebendo compras, não deve ser paga por aqui.
        $this->createDespesaNoCartao($cartao, '2026-09-05', 20.00, 'Compra de peças');

        $atual = $this->getJson("/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20")
            ->assertOk()
            ->json('data.fatura');
        $this->assertTrue($atual['eh_atual']);
        $this->assertTrue($atual['pode_pagar']);

        $futura = $this->getJson("/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-09-20")
            ->assertOk()
            ->json('data.fatura');
        $this->assertFalse($futura['eh_atual']);
        $this->assertFalse($futura['pode_pagar']);
    }

    public function test_invoices_endpoint_returns_the_current_invoice_regardless_of_filters(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);

        $this->createDespesaNoCartao($cartao, '2026-08-05', 10.00, 'Compra de peças');
        $this->createDespesaNoCartao($cartao, '2026-10-05', 30.00, 'Compra de peças');

        // Filtra num mês que não é o da fatura atual — o KPI do topo não pode
        // sumir por causa disso.
        $this->getJson("/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas?mes=2026-10")
            ->assertOk()
            ->assertJsonCount(1, 'data.faturas')
            ->assertJsonPath('data.faturas.0.data_vencimento', '2026-10-20')
            ->assertJsonPath('data.fatura_atual.data_vencimento', '2026-08-20');
    }

    /**
     * O botão "Pagar fatura" anuncia este valor, então ele precisa descontar o
     * que já foi baixado em títulos parciais — senão a tela promete um número e
     * a baixa em lote liquida outro.
     */
    public function test_invoice_open_balance_discounts_partial_payments(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);
        $contaId = $this->createContaFinanceira();

        $despesa = $this->createDespesaNoCartao($cartao, '2026-08-05', 100.00, 'Compra de peças');
        $this->createDespesaNoCartao($cartao, '2026-08-06', 50.00, 'Compra de peças');

        $service = app(FinanceiroCartaoCreditoService::class);

        $antes = collect($service->invoiceList($cartao))->firstWhere('data_vencimento', '2026-08-20');
        $this->assertSame(150.0, $antes['total']);
        $this->assertSame(150.0, $antes['total_em_aberto']);

        // Baixa parcial de 30 num dos títulos — via serviço, não pelo endpoint
        // HTTP individual (bloqueado para despesa de crédito desde que baixa
        // passou a ser exclusiva da fatura; ver
        // test_paying_a_credit_card_expense_individually_is_blocked). O
        // cálculo de saldo em aberto continua precisando funcionar para
        // qualquer movimento parcial já registrado, seja lá qual for a
        // origem.
        app(FinanceiroService::class)->registerMovement($despesa, [
            'valor_movimento' => 30.00,
            'forma_pagamento' => 'pix',
            'conta_financeira_id' => $contaId,
        ]);

        $depois = collect($service->invoiceList($cartao))->firstWhere('data_vencimento', '2026-08-20');

        // O total da fatura não muda (é o valor comprado), mas o saldo a pagar sim.
        $this->assertSame(150.0, $depois['total']);
        $this->assertSame(120.0, $depois['total_em_aberto']);
        $this->assertSame('aberta', $depois['status']);
    }

    public function test_invoice_open_balance_is_zero_once_everything_is_settled(): void
    {
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);
        $contaId = $this->createContaFinanceira();

        $this->createDespesaNoCartao($cartao, '2026-08-05', 80.00, 'Compra de peças');

        $this->postJson("/api/v1/financeiro/cartoes-credito/{$cartao->id}/faturas/2026-08-20/pagar", [
            'conta_financeira_id' => $contaId,
        ])->assertOk();

        $fatura = collect(app(FinanceiroCartaoCreditoService::class)->invoiceList($cartao))
            ->firstWhere('data_vencimento', '2026-08-20');

        $this->assertSame('paga', $fatura['status']);
        $this->assertSame(0.0, $fatura['total_em_aberto']);
    }

    /**
     * A data de fechamento exibida na listagem é derivada do vencimento, não
     * guardada. Este teste fecha o ciclo: para várias configurações de cartão e
     * datas de compra, o fechamento derivado tem que ser exatamente o que
     * resolveInvoiceCycle() usou na ida — senão a coluna "Fechamento" mostraria
     * um dia diferente do que definiu a fatura.
     */
    public function test_closing_date_derived_from_due_date_matches_the_original_cycle(): void
    {
        $service = app(FinanceiroCartaoCreditoService::class);
        $divergencias = [];

        foreach ([[10, 20], [28, 5], [5, 15], [31, 10], [10, 31], [15, 15], [30, 1]] as [$fecha, $vence]) {
            $cartao = new FinanceiroCartaoCredito([
                'nome' => 'Cartão teste',
                'dia_fechamento' => $fecha,
                'dia_vencimento' => $vence,
            ]);

            for ($mes = 1; $mes <= 12; $mes++) {
                $referencia = CarbonImmutable::create(2026, $mes, 1);

                foreach ([1, 5, 10, 15, 20, 25, 28, (int) $referencia->daysInMonth] as $dia) {
                    $compra = $referencia->setUnit('day', min($dia, (int) $referencia->daysInMonth));
                    $ciclo = $service->resolveInvoiceCycle($cartao, $compra);
                    $derivado = $service->closingDateForDueDate(
                        $cartao,
                        CarbonImmutable::parse($ciclo['data_vencimento'])
                    );

                    if ($derivado !== $ciclo['ciclo_fechamento']) {
                        $divergencias[] = sprintf(
                            'fecha=%d vence=%d compra=%s: ciclo=%s derivado=%s',
                            $fecha, $vence, $compra->toDateString(), $ciclo['ciclo_fechamento'], $derivado
                        );
                    }
                }
            }
        }

        $this->assertSame([], $divergencias, "Fechamento derivado divergiu do ciclo original:\n".implode("\n", $divergencias));
    }

    public function test_invoice_list_exposes_the_closing_date(): void
    {
        // Fecha 10, vence 20: fechamento e vencimento no mesmo mês.
        $cartao = $this->createCartao(['dia_fechamento' => 10, 'dia_vencimento' => 20]);
        $this->createDespesaNoCartao($cartao, '2026-08-05', 25.00, 'Compra de peças');

        $fatura = collect(app(FinanceiroCartaoCreditoService::class)->invoiceList($cartao))
            ->firstWhere('data_vencimento', '2026-08-20');

        $this->assertSame('2026-08-10', $fatura['data_fechamento']);
    }

    public function test_closing_date_falls_in_the_previous_month_when_due_day_is_lower(): void
    {
        // Fecha 20, vence 25 -> mesmo mês. Fecha 20, vence 5 -> mês anterior.
        $mesmoMes = new FinanceiroCartaoCredito(['nome' => 'a', 'dia_fechamento' => 20, 'dia_vencimento' => 25]);
        $mesAnterior = new FinanceiroCartaoCredito(['nome' => 'b', 'dia_fechamento' => 20, 'dia_vencimento' => 5]);

        $service = app(FinanceiroCartaoCreditoService::class);

        $this->assertSame(
            '2026-09-20',
            $service->closingDateForDueDate($mesmoMes, CarbonImmutable::parse('2026-09-25'))
        );
        $this->assertSame(
            '2026-08-20',
            $service->closingDateForDueDate($mesAnterior, CarbonImmutable::parse('2026-09-05'))
        );
    }

    public function test_accounts_permission_gates_the_card_registry(): void
    {
        $moduleId = (int) DB::table('modulos')->where('slug', 'contas_saldos')->value('id');

        DB::table('grupo_permissoes')
            ->where('grupo_id', 1)
            ->where('modulo_id', $moduleId)
            ->delete();
        app(\App\Services\Auth\RbacAuthorizationService::class)->forgetUser($this->authenticatedUserId);

        $this->getJson('/api/v1/financeiro')->assertOk();
        $this->getJson('/api/v1/financeiro/cartoes-credito')->assertForbidden();
    }

    /**
     * Credenciais de um administrador para o step-up do estorno de fatura.
     * Cria o admin na primeira chamada e reusa nas seguintes, para vários
     * estornos no mesmo teste não criarem usuários repetidos.
     *
     * @return array<string, string>
     */
    private function adminCredentials(): array
    {
        $this->admin ??= $this->createUserRecord(['perfil' => 'admin', 'grupo_id' => 1]);

        return [
            'admin_email' => (string) $this->admin->email,
            'admin_password' => 'Senha@123',
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function createCartao(array $overrides = []): FinanceiroCartaoCredito
    {
        return FinanceiroCartaoCredito::query()->create(array_merge([
            'nome' => 'Cartão '.uniqid(),
            'instituicao' => 'Banco Teste',
            'dia_fechamento' => 10,
            'dia_vencimento' => 20,
            'cor' => '#3868B0',
            'ativo' => true,
        ], $overrides));
    }

    private function createDespesaNoCartao(
        FinanceiroCartaoCredito $cartao,
        string $dataCompra,
        float $valor,
        string $categoria
    ): Financeiro {
        $this->postJson('/api/v1/financeiro', [
            'tipo' => 'pagar',
            'categoria' => $categoria,
            'descricao' => $categoria.' em '.$dataCompra,
            'valor' => $valor,
            'forma_pagamento' => 'cartao_credito',
            'cartao_credito_id' => $cartao->id,
            'data_compra' => $dataCompra,
            'data_vencimento' => $dataCompra,
        ])->assertCreated();

        return Financeiro::query()->latest('id')->firstOrFail();
    }

    private function createContaFinanceira(): int
    {
        return (int) DB::table('financeiro_contas')->insertGetId([
            'nome' => 'Conta corrente',
            'tipo' => 'banco',
            'data_inicio_controle' => '2026-01-01',
            'considera_disponivel' => true,
            'ativo' => true,
            'cor' => '#3868B0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
