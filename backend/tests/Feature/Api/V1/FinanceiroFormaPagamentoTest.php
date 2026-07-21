<?php

namespace Tests\Feature\Api\V1;

use App\Models\FinanceiroFormaPagamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class FinanceiroFormaPagamentoTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->grantGroupPermissions(1, [
            'financeiro' => ['visualizar', 'criar', 'editar', 'excluir'],
        ]);

        FinanceiroFormaPagamento::flushCatalog();
    }

    private function actingAsFinanceAdmin(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);
    }

    public function test_catalogo_expoe_as_formas_semeadas(): void
    {
        $this->actingAsFinanceAdmin();

        $response = $this->getJson('/api/v1/financeiro/catalogo')->assertOk();

        $codigos = collect($response->json('data.formas_pagamento'))->pluck('codigo')->all();

        $this->assertEqualsCanonicalizing(
            ['dinheiro', 'pix', 'cartao_credito', 'cartao_debito', 'boleto', 'transferencia'],
            $codigos
        );
    }

    public function test_cria_forma_personalizada_com_codigo_gerado_do_nome(): void
    {
        $this->actingAsFinanceAdmin();

        $this->postJson('/api/v1/financeiro/formas-pagamento', [
            'nome' => 'Vale Refeição',
            'is_cartao' => false,
        ])->assertCreated()->assertJsonPath('data.forma_pagamento.codigo', 'vale_refeicao');

        $this->assertDatabaseHas('financeiro_formas_pagamento', [
            'codigo' => 'vale_refeicao',
            'sistema' => false,
            // Formas novas não entram na coluna-resumo, que é um ENUM legado.
            'resumo_enum' => false,
        ]);
    }

    public function test_forma_de_sistema_nao_pode_ser_excluida(): void
    {
        $this->actingAsFinanceAdmin();

        $cartao = FinanceiroFormaPagamento::query()->where('codigo', 'cartao_credito')->firstOrFail();

        $this->deleteJson('/api/v1/financeiro/formas-pagamento/' . $cartao->id)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FORMA_PAGAMENTO_PROTEGIDA');

        $this->assertDatabaseHas('financeiro_formas_pagamento', ['codigo' => 'cartao_credito']);
    }

    public function test_forma_personalizada_em_uso_nao_pode_ser_excluida(): void
    {
        $this->actingAsFinanceAdmin();

        $forma = FinanceiroFormaPagamento::query()->create([
            'codigo' => 'cheque',
            'nome' => 'Cheque',
            'is_cartao' => false,
            'sistema' => false,
            'resumo_enum' => false,
            'ordem_exibicao' => 900,
            'ativo' => true,
        ]);

        $contaId = DB::table('financeiro_contas')->insertGetId([
            'nome' => 'Caixa Teste',
            'tipo' => 'caixa',
            'data_inicio_controle' => now()->toDateString(),
            'considera_disponivel' => true,
            'ativo' => true,
            'cor' => '#3868B0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('financeiro_conta_defaults')->insert([
            'conta_financeira_id' => $contaId,
            'forma_pagamento' => 'cheque',
        ]);

        $this->deleteJson('/api/v1/financeiro/formas-pagamento/' . $forma->id)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FORMA_PAGAMENTO_EM_USO');
    }

    public function test_forma_personalizada_sem_uso_pode_ser_excluida(): void
    {
        $this->actingAsFinanceAdmin();

        $forma = FinanceiroFormaPagamento::query()->create([
            'codigo' => 'voucher',
            'nome' => 'Voucher',
            'is_cartao' => false,
            'sistema' => false,
            'resumo_enum' => false,
            'ordem_exibicao' => 900,
            'ativo' => true,
        ]);

        $this->deleteJson('/api/v1/financeiro/formas-pagamento/' . $forma->id)->assertOk();

        $this->assertDatabaseMissing('financeiro_formas_pagamento', ['codigo' => 'voucher']);
    }

    public function test_catalogo_separa_codigos_de_detalhe_dos_aceitos_pela_coluna_resumo(): void
    {
        FinanceiroFormaPagamento::query()->create([
            'codigo' => 'cheque',
            'nome' => 'Cheque',
            'is_cartao' => false,
            'sistema' => false,
            'resumo_enum' => false,
            'ordem_exibicao' => 900,
            'ativo' => true,
        ]);
        FinanceiroFormaPagamento::flushCatalog();

        // Colunas varchar (movimentos/recebimentos) aceitam a forma nova...
        $this->assertContains('cheque', FinanceiroFormaPagamento::validCodes());
        // ...mas a coluna-resumo legada (ENUM) continua restrita às 6 originais.
        $this->assertNotContains('cheque', FinanceiroFormaPagamento::summaryCodes());
    }

    public function test_deteccao_de_cartao_segue_a_marcacao_do_cadastro(): void
    {
        FinanceiroFormaPagamento::query()->create([
            'codigo' => 'voucher_alimentacao',
            'nome' => 'Voucher Alimentação',
            'is_cartao' => true,
            'sistema' => false,
            'resumo_enum' => false,
            'ordem_exibicao' => 900,
            'ativo' => true,
        ]);
        FinanceiroFormaPagamento::flushCatalog();

        $this->assertTrue(FinanceiroFormaPagamento::isCardCode('cartao_credito'));
        $this->assertFalse(FinanceiroFormaPagamento::isCardCode('pix'));
        // Forma personalizada marcada como cartão dispara o fluxo de cartão
        // mesmo sem "cartao" no código.
        $this->assertTrue(FinanceiroFormaPagamento::isCardCode('voucher_alimentacao'));
    }

    public function test_forma_inativa_sai_das_opcoes_mas_nao_e_excluida(): void
    {
        $this->actingAsFinanceAdmin();

        $forma = FinanceiroFormaPagamento::query()->create([
            'codigo' => 'cheque',
            'nome' => 'Cheque',
            'is_cartao' => false,
            'sistema' => false,
            'resumo_enum' => false,
            'ordem_exibicao' => 900,
            'ativo' => true,
        ]);

        $this->patchJson('/api/v1/financeiro/formas-pagamento/' . $forma->id, [
            'nome' => 'Cheque',
            'ativo' => false,
        ])->assertOk();

        FinanceiroFormaPagamento::flushCatalog();

        $this->assertNotContains('cheque', FinanceiroFormaPagamento::validCodes());
        $this->assertDatabaseHas('financeiro_formas_pagamento', ['codigo' => 'cheque']);
    }
}
