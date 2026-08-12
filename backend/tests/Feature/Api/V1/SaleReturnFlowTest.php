<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Devolução e troca de venda — specs/029-devolucao-troca/spec.md.
 */
class SaleReturnFlowTest extends TestCase
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
            'caixa' => ['visualizar', 'criar', 'editar'],
        ]);
        $this->grantGroupPermissions(3, ['vendas' => ['visualizar']]);

        $this->seedFinanceCategories();
    }

    public function test_devolucao_parcial_volta_estoque_e_reembolsa_o_proporcional(): void
    {
        $token = $this->loginAndGetToken($this->createAdmin()->email);

        $partId = $this->createPecaRecord([
            'codigo' => 'PC00080', 'nome' => 'Película 3D',
            'preco_custo' => 8.00, 'preco_venda' => 50.00, 'quantidade_atual' => 10,
        ]);

        // Venda com desconto geral: subtotal 100, desconto 10, total 90.
        $venda = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', [
                'itens' => [['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => 2, 'valor_unitario' => 50.00]],
                'desconto' => 10.00,
                'pagamentos' => [['forma_pagamento' => 'dinheiro', 'valor' => 90.00]],
            ])->assertCreated();

        $vendaId = (int) $venda->json('data.venda.id');
        $itemId = (int) $venda->json('data.venda.itens.0.id');

        $this->assertSame(8, (int) DB::table('pecas')->where('id', $partId)->value('quantidade_atual'));

        // O saldo devolvível reflete o desconto: 45 por unidade, não 50.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/vendas/{$vendaId}/devolvivel")
            ->assertOk()
            ->assertJsonPath('data.itens.0.quantidade_disponivel', 2.0)
            ->assertJsonPath('data.itens.0.reembolso_unitario', 45.0)
            ->assertJsonPath('data.exige_autorizacao', false);

        $devolucao = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/vendas/{$vendaId}/devolucoes", [
                'motivo' => 'Cliente comprou o tamanho errado',
                'itens' => [['venda_item_id' => $itemId, 'quantidade' => 1]],
            ])->assertCreated();

        $devolucao
            ->assertJsonPath('data.devolucao.valor_devolvido', 45.0)
            ->assertJsonPath('data.devolucao.valor_reembolsado', 45.0)
            ->assertJsonPath('data.devolucao.valor_abatido', 0.0);

        $numero = (string) $devolucao->json('data.devolucao.numero');
        $this->assertStringStartsWith('DV-', $numero);

        // Uma unidade voltou à prateleira, com rastro até a venda.
        $this->assertSame(9, (int) DB::table('pecas')->where('id', $partId)->value('quantidade_atual'));
        $this->assertDatabaseHas('movimentacoes', [
            'peca_id' => $partId,
            'venda_id' => $vendaId,
            'tipo' => 'entrada',
            'quantidade' => 1,
            'motivo' => 'Devolução '.$numero,
        ]);

        // Título a pagar quitado, na categoria de devolução.
        $titulo = DB::table('financeiro')
            ->where('venda_id', $vendaId)
            ->where('tipo', 'pagar')
            ->first();

        $this->assertNotNull($titulo);
        $this->assertSame('Devolução de venda', $titulo->categoria);
        $this->assertSame(45.0, (float) $titulo->valor);
        $this->assertSame('pago', $titulo->status);

        // A receita original permanece intacta: apagá-la reescreveria o DRE.
        $this->assertDatabaseHas('financeiro', [
            'venda_id' => $vendaId,
            'tipo' => 'receber',
            'status' => 'pago',
        ]);

        // Margem da venda fica líquida do que voltou.
        $this->assertSame(45.0, (float) DB::table('vendas')->where('id', $vendaId)->value('total_devolvido'));
    }

    public function test_saldo_devolvivel_impede_devolver_mais_do_que_foi_vendido(): void
    {
        $token = $this->loginAndGetToken($this->createAdmin()->email);

        $partId = $this->createPecaRecord(['codigo' => 'PC00081', 'preco_venda' => 20.00, 'quantidade_atual' => 10]);

        $venda = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', [
                'itens' => [['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => 2, 'valor_unitario' => 20.00]],
                'pagamentos' => [['forma_pagamento' => 'dinheiro', 'valor' => 40.00]],
            ])->assertCreated();

        $vendaId = (int) $venda->json('data.venda.id');
        $itemId = (int) $venda->json('data.venda.itens.0.id');

        // Devolver 3 de 2 vendidos é recusado.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/vendas/{$vendaId}/devolucoes", [
                'motivo' => 'Tentativa inválida',
                'itens' => [['venda_item_id' => $itemId, 'quantidade' => 3]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DEVOLUCAO_INVALIDA');

        // Duas devoluções de 1 passam; a terceira não.
        foreach ([1, 1] as $quantidade) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->postJson("/api/v1/vendas/{$vendaId}/devolucoes", [
                    'motivo' => 'Devolução parcial',
                    'itens' => [['venda_item_id' => $itemId, 'quantidade' => $quantidade]],
                ])->assertCreated();
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/vendas/{$vendaId}/devolucoes", [
                'motivo' => 'Terceira devolução',
                'itens' => [['venda_item_id' => $itemId, 'quantidade' => 1]],
            ])
            ->assertStatus(422);

        $this->assertSame(2, DB::table('venda_devolucoes')->where('venda_id', $vendaId)->count());
    }

    public function test_reembolso_e_rateado_entre_as_formas_de_pagamento_da_venda(): void
    {
        $token = $this->loginAndGetToken($this->createAdmin()->email);

        $this->seedCartaoCatalog();
        $bankId = $this->createBankAccount();
        $partId = $this->createPecaRecord(['codigo' => 'PC00082', 'preco_venda' => 100.00, 'quantidade_atual' => 10]);

        // 40 em dinheiro + 60 no cartão.
        $venda = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', [
                'itens' => [['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => 2, 'valor_unitario' => 50.00]],
                'pagamentos' => [
                    ['forma_pagamento' => 'dinheiro', 'valor' => 40.00, 'conta_financeira_id' => $bankId],
                    [
                        'forma_pagamento' => 'cartao_credito', 'valor' => 60.00,
                        'operadora_id' => 1, 'bandeira_id' => 1, 'modalidade' => 'credito', 'parcelas' => 1,
                        'conta_financeira_id' => $bankId,
                    ],
                ],
            ])->assertCreated();

        $vendaId = (int) $venda->json('data.venda.id');
        $itemId = (int) $venda->json('data.venda.itens.0.id');

        $taxasAntes = DB::table('financeiro')->where('origem_tipo', 'financeiro_movimento_cartao')->count();

        // Devolve metade: 50 -> 20 em dinheiro + 30 no cartão.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/vendas/{$vendaId}/devolucoes", [
                'motivo' => 'Devolveu uma unidade',
                'itens' => [['venda_item_id' => $itemId, 'quantidade' => 1]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.devolucao.valor_reembolsado', 50.0);

        $devolucaoId = (int) DB::table('venda_devolucoes')->where('venda_id', $vendaId)->value('id');
        $pagamentos = DB::table('venda_devolucao_pagamentos')
            ->where('venda_devolucao_id', $devolucaoId)
            ->pluck('valor', 'forma_pagamento');

        $this->assertSame(20.0, (float) $pagamentos['dinheiro']);
        $this->assertSame(30.0, (float) $pagamentos['cartao_credito']);

        // A taxa de cartão da venda NÃO é revertida: a operadora cobrou de
        // verdade. Registrar a perda é justamente não desfazer a despesa.
        $this->assertSame(
            $taxasAntes,
            DB::table('financeiro')->where('origem_tipo', 'financeiro_movimento_cartao')->count()
        );
        $this->assertSame(
            0,
            DB::table('financeiro')
                ->where('origem_tipo', 'financeiro_movimento_cartao')
                ->where('status', 'cancelado')
                ->count()
        );

        // Mas o valor perdido fica visível na devolução.
        $this->assertGreaterThan(
            0.0,
            (float) DB::table('venda_devolucoes')->where('id', $devolucaoId)->value('valor_taxa_nao_estornada')
        );
    }

    public function test_venda_fiada_nao_reembolsa_o_que_nunca_foi_pago(): void
    {
        $token = $this->loginAndGetToken($this->createAdmin()->email);

        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Fiado']);
        $partId = $this->createPecaRecord(['codigo' => 'PC00083', 'preco_venda' => 100.00, 'quantidade_atual' => 10]);

        // Venda de 100 com apenas 30 pagos.
        $venda = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', [
                'cliente_id' => $clientId,
                'itens' => [['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => 1, 'valor_unitario' => 100.00]],
                'pagamentos' => [['forma_pagamento' => 'dinheiro', 'valor' => 30.00]],
            ])->assertCreated();

        $vendaId = (int) $venda->json('data.venda.id');
        $itemId = (int) $venda->json('data.venda.itens.0.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/vendas/{$vendaId}/devolucoes", [
                'motivo' => 'Devolveu tudo antes de quitar',
                'itens' => [['venda_item_id' => $itemId, 'quantidade' => 1]],
            ])
            ->assertCreated()
            // Crédito total de 100, mas só 30 saíram do bolso da loja.
            ->assertJsonPath('data.devolucao.valor_devolvido', 100.0)
            ->assertJsonPath('data.devolucao.valor_reembolsado', 30.0)
            ->assertJsonPath('data.devolucao.valor_abatido', 70.0);

        // O que não foi pago abateu a dívida em aberto.
        $receber = DB::table('financeiro')->where('venda_id', $vendaId)->where('tipo', 'receber')->first();
        $this->assertSame(30.0, (float) $receber->valor);
        $this->assertSame('pago', $receber->status);
    }

    public function test_servico_nao_volta_ao_estoque(): void
    {
        $token = $this->loginAndGetToken($this->createAdmin()->email);

        $serviceId = $this->createServiceRecord(['nome' => 'Instalação', 'valor' => 25.00, 'custo_direto_padrao' => 0]);

        $venda = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', [
                'itens' => [['tipo_item' => 'servico', 'referencia_id' => $serviceId, 'quantidade' => 1, 'valor_unitario' => 25.00]],
                'pagamentos' => [['forma_pagamento' => 'dinheiro', 'valor' => 25.00]],
            ])->assertCreated();

        $vendaId = (int) $venda->json('data.venda.id');
        $itemId = (int) $venda->json('data.venda.itens.0.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/vendas/{$vendaId}/devolvivel")
            ->assertOk()
            ->assertJsonPath('data.itens.0.retorna_estoque', false);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/vendas/{$vendaId}/devolucoes", [
                'motivo' => 'Serviço não executado',
                'itens' => [['venda_item_id' => $itemId, 'quantidade' => 1]],
            ])->assertCreated();

        // Serviço executado não volta à prateleira: nenhuma movimentação.
        $this->assertSame(0, DB::table('movimentacoes')->where('tipo', 'entrada')->count());
    }

    public function test_devolucao_em_dinheiro_sai_do_caixa_aberto_e_entra_na_conferencia(): void
    {
        $token = $this->loginAndGetToken($this->createAdmin()->email);

        $partId = $this->createPecaRecord(['codigo' => 'PC00084', 'preco_venda' => 60.00, 'quantidade_atual' => 10]);

        $sessionId = (int) $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/caixa/abrir', ['valor_abertura' => 100.00])
            ->assertCreated()
            ->json('data.sessao.id');

        $venda = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', [
                'itens' => [['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => 1, 'valor_unitario' => 60.00]],
                'pagamentos' => [['forma_pagamento' => 'dinheiro', 'valor' => 60.00]],
            ])->assertCreated();

        $vendaId = (int) $venda->json('data.venda.id');
        $itemId = (int) $venda->json('data.venda.itens.0.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/vendas/{$vendaId}/devolucoes", [
                'motivo' => 'Produto com defeito',
                'itens' => [['venda_item_id' => $itemId, 'quantidade' => 1]],
            ])->assertCreated();

        // A devolução saiu da gaveta do turno aberto.
        $this->assertSame(
            $sessionId,
            (int) DB::table('venda_devolucoes')->where('venda_id', $vendaId)->value('caixa_sessao_id')
        );

        // Esperado: 100 (abertura) + 60 (venda) − 60 (devolução) = 100.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/caixa/{$sessionId}/fechar", ['valor_informado' => 100.00])
            ->assertOk()
            ->assertJsonPath('data.sessao.valor_esperado', 100.0)
            ->assertJsonPath('data.sessao.diferenca', 0.0);
    }

    public function test_venda_antiga_exige_credencial_de_administrador(): void
    {
        $admin = $this->createAdmin();
        $token = $this->loginAndGetToken($admin->email);

        $partId = $this->createPecaRecord(['codigo' => 'PC00085', 'preco_venda' => 40.00, 'quantidade_atual' => 10]);

        $venda = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', [
                'data_venda' => now()->subDays(30)->toDateString(),
                'itens' => [['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => 1, 'valor_unitario' => 40.00]],
                'pagamentos' => [['forma_pagamento' => 'dinheiro', 'valor' => 40.00]],
            ])->assertCreated();

        $vendaId = (int) $venda->json('data.venda.id');
        $itemId = (int) $venda->json('data.venda.itens.0.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/vendas/{$vendaId}/devolvivel")
            ->assertOk()
            ->assertJsonPath('data.exige_autorizacao', true);

        $payload = [
            'motivo' => 'Devolução tardia',
            'itens' => [['venda_item_id' => $itemId, 'quantidade' => 1]],
        ];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/vendas/{$vendaId}/devolucoes", $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DEVOLUCAO_ADMIN_AUTH_REQUIRED');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/vendas/{$vendaId}/devolucoes", $payload + [
                'admin_email' => $admin->email,
                'admin_password' => 'Senha@123',
            ])
            ->assertCreated();

        $this->assertNotNull(
            DB::table('venda_devolucoes')->where('venda_id', $vendaId)->value('autorizado_por')
        );
    }

    public function test_troca_vincula_a_venda_nova(): void
    {
        $token = $this->loginAndGetToken($this->createAdmin()->email);

        $partId = $this->createPecaRecord(['codigo' => 'PC00086', 'preco_venda' => 30.00, 'quantidade_atual' => 20]);

        $original = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', [
                'itens' => [['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => 1, 'valor_unitario' => 30.00]],
                'pagamentos' => [['forma_pagamento' => 'dinheiro', 'valor' => 30.00]],
            ])->assertCreated();

        $nova = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', [
                'itens' => [['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => 2, 'valor_unitario' => 30.00]],
                'pagamentos' => [['forma_pagamento' => 'dinheiro', 'valor' => 60.00]],
            ])->assertCreated();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas/'.$original->json('data.venda.id').'/devolucoes', [
                'motivo' => 'Troca por modelo maior',
                'itens' => [['venda_item_id' => (int) $original->json('data.venda.itens.0.id'), 'quantidade' => 1]],
                'venda_troca_id' => (int) $nova->json('data.venda.id'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.devolucao.venda_troca_id', (int) $nova->json('data.venda.id'));
    }

    public function test_venda_cancelada_nao_aceita_devolucao(): void
    {
        $token = $this->loginAndGetToken($this->createAdmin()->email);

        $partId = $this->createPecaRecord(['codigo' => 'PC00087', 'preco_venda' => 30.00, 'quantidade_atual' => 10]);

        $venda = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', [
                'itens' => [['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => 1, 'valor_unitario' => 30.00]],
                'pagamentos' => [['forma_pagamento' => 'dinheiro', 'valor' => 30.00]],
            ])->assertCreated();

        $vendaId = (int) $venda->json('data.venda.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/vendas/{$vendaId}/cancelar", ['motivo' => 'Cliente desistiu da compra'])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/vendas/{$vendaId}/devolucoes", [
                'motivo' => 'Tentativa após cancelamento',
                'itens' => [['venda_item_id' => (int) $venda->json('data.venda.itens.0.id'), 'quantidade' => 1]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DEVOLUCAO_INVALIDA');
    }

    public function test_usuario_sem_permissao_de_criar_recebe_403(): void
    {
        $viewer = $this->createUserRecord([
            'nome' => 'Atendente', 'email' => 'atendente.dev@example.com',
            'perfil' => 'atendente', 'grupo_id' => 3,
        ]);
        $token = $this->loginAndGetToken($viewer->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas/1/devolucoes', [
                'motivo' => 'Sem permissão',
                'itens' => [['venda_item_id' => 1, 'quantidade' => 1]],
            ])
            ->assertForbidden();
    }

    private function createAdmin(): object
    {
        return $this->createUserRecord([
            'nome' => 'Administrador', 'email' => 'admin.devolucao@example.com',
            'perfil' => 'admin', 'grupo_id' => 1,
        ]);
    }

    private function createBankAccount(): int
    {
        $existing = (int) DB::table('financeiro_contas')->where('nome', 'Banco Teste')->value('id');

        if ($existing > 0) {
            return $existing;
        }

        return (int) DB::table('financeiro_contas')->insertGetId([
            'nome' => 'Banco Teste', 'tipo' => 'banco',
            'data_inicio_controle' => now()->subYear()->toDateString(),
            'considera_disponivel' => 1, 'ativo' => 1, 'cor' => '#3868B0',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedCartaoCatalog(): void
    {
        DB::table('financeiro_cartao_operadoras')->insert([
            'id' => 1, 'nome' => 'Stone', 'ordem_exibicao' => 1,
            'prazo_padrao_dias' => 30, 'ativo' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('financeiro_cartao_bandeiras')->insert([
            'id' => 1, 'nome' => 'Visa', 'ordem_exibicao' => 1, 'ativo' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('financeiro_cartao_taxas')->insert([
            'operadora_id' => 1, 'bandeira_id' => 1, 'modalidade' => 'credito',
            'parcelas_inicial' => 1, 'parcelas_final' => 6,
            'taxa_percentual' => 3.19, 'taxa_fixa' => 0.00,
            'prazo_recebimento_dias' => 30, 'ativo' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedFinanceCategories(): void
    {
        $receita = (int) DB::table('financeiro_dre_grupos')->where('nome', 'Receita Operacional')->value('id');
        $despesa = (int) DB::table('financeiro_dre_grupos')->where('nome', 'Despesas Operacionais')->value('id');

        if ($receita > 0 && ! DB::table('financeiro_categorias')->where('nome', 'Venda de balcão')->exists()) {
            $sub = (int) DB::table('financeiro_dre_subgrupos')->insertGetId([
                'grupo_id' => $receita, 'nome' => 'Venda de balcão', 'ordem_exibicao' => 20,
                'ativo' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('financeiro_categorias')->insert([
                'nome' => 'Venda de balcão', 'tipo' => 'receber',
                'dre_grupo_id' => $receita, 'dre_subgrupo_id' => $sub,
                'impacta_dre_padrao' => 1, 'impacta_fluxo_caixa_padrao' => 1,
                'dre_fixo_mensal_padrao' => 0, 'ordem_exibicao' => 25, 'ativo' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if ($despesa > 0 && ! DB::table('financeiro_categorias')->where('nome', 'Devolução de venda')->exists()) {
            $sub = (int) DB::table('financeiro_dre_subgrupos')->insertGetId([
                'grupo_id' => $despesa, 'nome' => 'Devolução de venda', 'ordem_exibicao' => 85,
                'ativo' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('financeiro_categorias')->insert([
                'nome' => 'Devolução de venda', 'tipo' => 'pagar',
                'dre_grupo_id' => $despesa, 'dre_subgrupo_id' => $sub,
                'impacta_dre_padrao' => 1, 'impacta_fluxo_caixa_padrao' => 1,
                'dre_fixo_mensal_padrao' => 0, 'ordem_exibicao' => 95, 'ativo' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function loginAndGetToken(string $email): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => 'Senha@123', 'device_name' => 'desktop-devolucao',
        ])->json('data.access_token');
    }
}
