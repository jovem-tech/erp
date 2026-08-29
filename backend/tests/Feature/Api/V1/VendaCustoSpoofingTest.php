<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Integridade do custo na venda — specs/037-precificacao-integrada-ao-fluxo.
 *
 * Item de catalogo tem custo proprio: o do cadastro. Ate esta entrega,
 * SaleWorkflowService::normalizeItems() aceitava o `custo_unitario` que viesse
 * no payload, e um POST com zero fazia `vendas.custo_total` zerar e a margem
 * gravada marcar 100%.
 *
 * Era inocuo enquanto o desktop nao enviava o campo. Deixa de ser no instante
 * em que o PDV passa a exibir custo, porque ai o campo existe no payload — e
 * qualquer cliente HTTP pode manda-lo.
 */
class VendaCustoSpoofingTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->grantGroupPermissions(1, [
            'vendas' => ['visualizar', 'criar', 'editar', 'excluir'],
        ]);
        $this->seedFinanceCategory();
    }

    public function test_custo_enviado_pelo_cliente_e_ignorado_em_peca_cadastrada(): void
    {
        $token = $this->loginAndGetToken($this->createAdmin()->email);

        $partId = $this->createPecaRecord([
            'codigo' => 'PC-SPOOF',
            'nome' => 'Película 3D',
            'preco_custo' => 8.00,
            'preco_venda' => 25.00,
            'quantidade_atual' => 10,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', [
                'itens' => [[
                    'tipo_item' => 'peca',
                    'referencia_id' => $partId,
                    'quantidade' => 2,
                    'valor_unitario' => 25.00,
                    // A tentativa: zerar o custo para a margem parecer 100%.
                    'custo_unitario' => 0,
                ]],
                'pagamentos' => [['forma_pagamento' => 'dinheiro', 'valor' => 50.00]],
            ])->assertCreated();

        $venda = DB::table('vendas')->latest('id')->first();

        // Custo do CADASTRO (2 × R$ 8,00), não o que o cliente mandou.
        $this->assertEqualsWithDelta(16.00, (float) $venda->custo_total, 0.01);
        $this->assertEqualsWithDelta(34.00, (float) $venda->margem_valor, 0.01);
    }

    /**
     * Item avulso nao tem cadastro para consultar: ali o custo informado e a
     * unica fonte que existe, e continua sendo aceito.
     */
    public function test_custo_informado_continua_valendo_em_item_avulso(): void
    {
        $token = $this->loginAndGetToken($this->createAdmin()->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', [
                'itens' => [[
                    'tipo_item' => 'avulso',
                    'descricao' => 'Cabo HDMI avulso',
                    'quantidade' => 1,
                    'valor_unitario' => 40.00,
                    'custo_unitario' => 22.00,
                ]],
                'pagamentos' => [['forma_pagamento' => 'dinheiro', 'valor' => 40.00]],
            ])->assertCreated();

        $venda = DB::table('vendas')->latest('id')->first();

        $this->assertEqualsWithDelta(22.00, (float) $venda->custo_total, 0.01);
        $this->assertEqualsWithDelta(18.00, (float) $venda->margem_valor, 0.01);
    }

    private function createAdmin(): object
    {
        return $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.spoof@example.com',
            'grupo_id' => 1,
            'perfil' => 'admin',
        ]);
    }

    private function seedFinanceCategory(): void
    {
        $groupId = (int) DB::table('financeiro_dre_grupos')->where('nome', 'Receita Operacional')->value('id');

        DB::table('financeiro_categorias')->updateOrInsert(
            ['nome' => 'Venda de balcão'],
            [
                'tipo' => 'receber',
                'dre_grupo_id' => $groupId ?: null,
                'impacta_dre_padrao' => 1,
                'impacta_fluxo_caixa_padrao' => 1,
                'ativo' => 1,
            ]
        );
    }

    private function loginAndGetToken(string $email): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Senha@123',
            'device_name' => 'desktop-vendas',
        ])->json('data.access_token');
    }
}
