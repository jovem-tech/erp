<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Vendas de balcão (PDV) — specs/027-vendas-balcao-pdv/spec.md.
 */
class SaleFlowTest extends TestCase
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
        // Atendente só enxerga: cobre o caso negativo de autorização.
        $this->grantGroupPermissions(3, [
            'vendas' => ['visualizar'],
        ]);

        $this->seedFinanceCategory();
    }

    public function test_venda_de_balcao_baixa_estoque_e_gera_titulo_financeiro(): void
    {
        $admin = $this->createAdmin();
        $token = $this->loginAndGetToken($admin->email);

        $partId = $this->createPecaRecord([
            'codigo' => 'PC00010',
            'nome' => 'Película 3D',
            'preco_custo' => 8.00,
            'preco_venda' => 25.00,
            'quantidade_atual' => 10,
        ]);
        $serviceId = $this->createServiceRecord([
            'nome' => 'Aplicação de película',
            'valor' => 15.00,
            'custo_direto_padrao' => 5.00,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', [
                'itens' => [
                    [
                        'tipo_item' => 'peca',
                        'referencia_id' => $partId,
                        'quantidade' => 2,
                        'valor_unitario' => 25.00,
                    ],
                    [
                        'tipo_item' => 'servico',
                        'referencia_id' => $serviceId,
                        'quantidade' => 1,
                        'valor_unitario' => 15.00,
                    ],
                ],
                'pagamentos' => [
                    ['forma_pagamento' => 'dinheiro', 'valor' => 65.00, 'valor_recebido' => 100.00],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.venda.subtotal', 65.0)
            ->assertJsonPath('data.venda.total', 65.0)
            // Custo: 2 × 8,00 da peça + 5,00 de custo direto do serviço.
            ->assertJsonPath('data.venda.custo_total', 21.0)
            ->assertJsonPath('data.venda.margem_valor', 44.0)
            ->assertJsonPath('data.venda.status_pagamento', 'pago')
            ->assertJsonPath('data.venda.estoque_divergente', false);

        $numero = (string) $response->json('data.venda.numero');
        $this->assertStringStartsWith('VD-', $numero);

        $saleId = (int) $response->json('data.venda.id');

        // Estoque debitado e movimentação rastreável até a venda.
        $this->assertSame(8, (int) DB::table('pecas')->where('id', $partId)->value('quantidade_atual'));
        $this->assertDatabaseHas('movimentacoes', [
            'peca_id' => $partId,
            'venda_id' => $saleId,
            'tipo' => 'saida',
            'quantidade' => 2,
            'motivo' => 'Venda '.$numero,
        ]);
        // Serviço não movimenta estoque.
        $this->assertSame(1, DB::table('movimentacoes')->where('venda_id', $saleId)->count());

        // Título a receber quitado, na categoria de balcão.
        $this->assertDatabaseHas('financeiro', [
            'venda_id' => $saleId,
            'tipo' => 'receber',
            'categoria' => 'Venda de balcão',
            'descricao' => 'Venda '.$numero,
            'status' => 'pago',
        ]);

        // Troco é conferência de gaveta: fica na venda, não vira movimento.
        $this->assertSame(35.0, (float) $response->json('data.venda.pagamentos.0.troco'));
        $titleId = (int) DB::table('financeiro')->where('venda_id', $saleId)->value('id');
        $this->assertSame(65.0, (float) DB::table('financeiro_movimentos')->where('financeiro_id', $titleId)->sum('valor_movimento'));
    }

    public function test_venda_para_consumidor_final_cria_titulo_avulso(): void
    {
        $admin = $this->createAdmin();
        $token = $this->loginAndGetToken($admin->email);

        $partId = $this->createPecaRecord(['codigo' => 'PC00011', 'preco_venda' => 30.00, 'quantidade_atual' => 5]);

        // Sem cliente_id: sem `avulso => true` o FinanceiroService recusaria o
        // título com "Selecione o cliente desta cobrança ou vincule uma OS".
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', [
                'itens' => [
                    ['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => 1, 'valor_unitario' => 30.00],
                ],
                'pagamentos' => [['forma_pagamento' => 'pix', 'valor' => 30.00]],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.venda.cliente_id', null)
            ->assertJsonPath('data.venda.cliente_nome', 'Consumidor final');

        $saleId = (int) $response->json('data.venda.id');

        $this->assertDatabaseHas('financeiro', [
            'venda_id' => $saleId,
            'cliente_id' => null,
            'avulso' => 1,
            'os_id' => null,
        ]);
    }

    public function test_pagamento_misto_com_cartao_gera_uma_unica_despesa_de_taxa(): void
    {
        $admin = $this->createAdmin();
        $token = $this->loginAndGetToken($admin->email);

        $this->seedCartaoCatalog();
        $partId = $this->createPecaRecord(['codigo' => 'PC00012', 'preco_venda' => 100.00, 'quantidade_atual' => 3]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', [
                'itens' => [
                    ['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => 1, 'valor_unitario' => 100.00],
                ],
                'pagamentos' => [
                    ['forma_pagamento' => 'dinheiro', 'valor' => 40.00, 'valor_recebido' => 50.00],
                    [
                        'forma_pagamento' => 'cartao_credito',
                        'valor' => 60.00,
                        'operadora_id' => 1,
                        'bandeira_id' => 1,
                        'modalidade' => 'credito',
                        'parcelas' => 2,
                    ],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.venda.status_pagamento', 'pago')
            ->assertJsonPath('data.venda.valor_pago', 100.0);

        $saleId = (int) $response->json('data.venda.id');
        $titleId = (int) DB::table('financeiro')->where('venda_id', $saleId)->value('id');

        $this->assertSame(2, DB::table('financeiro_movimentos')->where('financeiro_id', $titleId)->count());

        // A meta de cartão é gravada pelo FinanceiroService porque repassamos
        // operadora_id. Se o SalePaymentService também gravasse, sairia 2 —
        // e a despesa da taxa entraria duas vezes no DRE.
        $this->assertSame(1, DB::table('financeiro_movimentos_cartao')->count());
        $this->assertSame(
            1,
            DB::table('financeiro')->where('tipo', 'pagar')->where('origem_tipo', 'financeiro_movimento_cartao')->count()
        );

        $this->assertSame(60.0, (float) DB::table('venda_pagamentos')
            ->where('venda_id', $saleId)
            ->where('forma_pagamento', 'cartao_credito')
            ->value('valor'));
        $this->assertGreaterThan(0.0, (float) DB::table('venda_pagamentos')
            ->where('venda_id', $saleId)
            ->where('forma_pagamento', 'cartao_credito')
            ->value('valor_taxa'));
    }

    public function test_venda_fiada_deixa_titulo_parcial(): void
    {
        $admin = $this->createAdmin();
        $token = $this->loginAndGetToken($admin->email);

        $clientId = $this->createClientRecord(['nome_razao' => 'Maria Souza']);
        $partId = $this->createPecaRecord(['codigo' => 'PC00013', 'preco_venda' => 80.00, 'quantidade_atual' => 4]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', [
                'cliente_id' => $clientId,
                'itens' => [
                    ['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => 1, 'valor_unitario' => 80.00],
                ],
                'pagamentos' => [['forma_pagamento' => 'dinheiro', 'valor' => 30.00]],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.venda.status_pagamento', 'parcial')
            ->assertJsonPath('data.venda.valor_pago', 30.0)
            ->assertJsonPath('data.venda.valor_aberto', 50.0);

        $saleId = (int) $response->json('data.venda.id');

        $this->assertDatabaseHas('financeiro', [
            'venda_id' => $saleId,
            'cliente_id' => $clientId,
            'status' => 'parcial',
        ]);

        // Mesmo fiado, o produto saiu da prateleira.
        $this->assertSame(3, (int) DB::table('pecas')->where('id', $partId)->value('quantidade_atual'));
    }

    public function test_estoque_insuficiente_bloqueia_ate_confirmacao_explicita(): void
    {
        $admin = $this->createAdmin();
        $token = $this->loginAndGetToken($admin->email);

        $partId = $this->createPecaRecord([
            'codigo' => 'PC00014',
            'nome' => 'Cabo USB-C',
            'preco_venda' => 20.00,
            'quantidade_atual' => 1,
        ]);

        $payload = [
            'itens' => [
                ['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => 3, 'valor_unitario' => 20.00],
            ],
            'pagamentos' => [['forma_pagamento' => 'dinheiro', 'valor' => 60.00]],
        ];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VENDA_ESTOQUE_INSUFICIENTE')
            ->assertJsonPath('error.details.itens.0.peca_id', $partId)
            ->assertJsonPath('error.details.itens.0.disponivel', 1.0)
            ->assertJsonPath('error.details.itens.0.solicitado', 3.0);

        $this->assertSame(0, DB::table('vendas')->count());

        // O operador confirma: a venda passa, marcada como divergente, e o
        // saldo fica negativo — sinal de que o inventário precisa de acerto.
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', $payload + ['confirmar_estoque_insuficiente' => true]);

        $response->assertCreated()
            ->assertJsonPath('data.venda.estoque_divergente', true);

        $this->assertSame(-2, (int) DB::table('pecas')->where('id', $partId)->value('quantidade_atual'));

        $numero = (string) $response->json('data.venda.numero');
        $this->assertDatabaseHas('movimentacoes', [
            'venda_id' => (int) $response->json('data.venda.id'),
            'motivo' => 'Venda '.$numero.' (saldo insuficiente)',
        ]);
    }

    public function test_mesma_chave_de_criacao_nao_duplica_a_venda(): void
    {
        $admin = $this->createAdmin();
        $token = $this->loginAndGetToken($admin->email);

        $partId = $this->createPecaRecord(['codigo' => 'PC00015', 'preco_venda' => 50.00, 'quantidade_atual' => 10]);

        $payload = [
            'creation_request_id' => (string) Str::uuid(),
            'itens' => [
                ['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => 1, 'valor_unitario' => 50.00],
            ],
            'pagamentos' => [['forma_pagamento' => 'dinheiro', 'valor' => 50.00]],
        ];

        $first = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', $payload)
            ->assertCreated();

        $second = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', $payload)
            ->assertCreated()
            ->assertJsonPath('data.idempotent_replay', true);

        $this->assertSame(
            (int) $first->json('data.venda.id'),
            (int) $second->json('data.venda.id')
        );

        $this->assertSame(1, DB::table('vendas')->count());
        // O replay não pode baixar o estoque de novo.
        $this->assertSame(9, (int) DB::table('pecas')->where('id', $partId)->value('quantidade_atual'));
    }

    public function test_cancelamento_estorna_estoque_e_titulo(): void
    {
        $admin = $this->createAdmin();
        $token = $this->loginAndGetToken($admin->email);

        $partId = $this->createPecaRecord(['codigo' => 'PC00016', 'preco_venda' => 40.00, 'quantidade_atual' => 6]);

        $sale = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', [
                'itens' => [
                    ['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => 2, 'valor_unitario' => 40.00],
                ],
                'pagamentos' => [['forma_pagamento' => 'dinheiro', 'valor' => 80.00]],
            ])->assertCreated();

        $saleId = (int) $sale->json('data.venda.id');
        $numero = (string) $sale->json('data.venda.numero');
        $titleId = (int) DB::table('financeiro')->where('venda_id', $saleId)->value('id');

        $this->assertSame(4, (int) DB::table('pecas')->where('id', $partId)->value('quantidade_atual'));

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/vendas/{$saleId}/cancelar", ['motivo' => 'Cliente desistiu da compra'])
            ->assertOk()
            ->assertJsonPath('data.venda.status', 'cancelada');

        // Estoque volta ao original...
        $this->assertSame(6, (int) DB::table('pecas')->where('id', $partId)->value('quantidade_atual'));

        // ...mas a saída original permanece: o histórico precisa mostrar
        // saída e entrada para conciliar com a contagem física.
        $this->assertSame(1, DB::table('movimentacoes')->where('venda_id', $saleId)->where('tipo', 'saida')->count());
        $this->assertDatabaseHas('movimentacoes', [
            'venda_id' => $saleId,
            'tipo' => 'entrada',
            'quantidade' => 2,
            'motivo' => 'Estorno da venda '.$numero,
        ]);

        $this->assertDatabaseHas('financeiro', ['id' => $titleId, 'status' => 'cancelado']);
        $this->assertSame(0, DB::table('financeiro_movimentos')->where('financeiro_id', $titleId)->count());
    }

    public function test_usuario_sem_permissao_de_criar_recebe_403(): void
    {
        $viewer = $this->createUserRecord([
            'nome' => 'Atendente',
            'email' => 'atendente.vendas@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 3,
        ]);
        $token = $this->loginAndGetToken($viewer->email);

        $partId = $this->createPecaRecord(['codigo' => 'PC00017', 'preco_venda' => 10.00, 'quantidade_atual' => 5]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', [
                'itens' => [
                    ['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => 1, 'valor_unitario' => 10.00],
                ],
            ])
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/vendas')
            ->assertOk();
    }

    public function test_listagem_traz_totais_do_periodo(): void
    {
        $admin = $this->createAdmin();
        $token = $this->loginAndGetToken($admin->email);

        $partId = $this->createPecaRecord(['codigo' => 'PC00018', 'preco_custo' => 10.00, 'preco_venda' => 30.00, 'quantidade_atual' => 20]);

        foreach ([1, 2] as $quantity) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->postJson('/api/v1/vendas', [
                    'itens' => [
                        ['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => $quantity, 'valor_unitario' => 30.00],
                    ],
                    'pagamentos' => [['forma_pagamento' => 'dinheiro', 'valor' => 30.00 * $quantity]],
                ])->assertCreated();
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/vendas')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('data.summary.total_vendas', 2)
            ->assertJsonPath('data.summary.total_vendido', 90.0)
            ->assertJsonPath('data.summary.ticket_medio', 45.0)
            // 90 de receita, 30 de custo (3 unidades × 10).
            ->assertJsonPath('data.summary.total_margem', 60.0);
    }

    private function createAdmin(): object
    {
        return $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.vendas@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);
    }

    /**
     * A categoria vem da migration de seed do módulo, que não roda contra o
     * schema recriado pelo trait. Sem ela o título nasceria sem DRE.
     */
    private function seedFinanceCategory(): void
    {
        $groupId = (int) DB::table('financeiro_dre_grupos')->where('nome', 'Receita Operacional')->value('id');

        if ($groupId <= 0) {
            return;
        }

        if (DB::table('financeiro_categorias')->where('nome', 'Venda de balcão')->exists()) {
            return;
        }

        $subgroupId = (int) DB::table('financeiro_dre_subgrupos')->insertGetId([
            'grupo_id' => $groupId,
            'nome' => 'Venda de balcão',
            'ordem_exibicao' => 20,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('financeiro_categorias')->insert([
            'nome' => 'Venda de balcão',
            'tipo' => 'receber',
            'dre_grupo_id' => $groupId,
            'dre_subgrupo_id' => $subgroupId,
            'impacta_dre_padrao' => 1,
            'impacta_fluxo_caixa_padrao' => 1,
            'dre_fixo_mensal_padrao' => 0,
            'ordem_exibicao' => 25,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedCartaoCatalog(): void
    {
        DB::table('financeiro_cartao_operadoras')->insert([
            'id' => 1,
            'nome' => 'Stone',
            'descricao' => 'Operadora principal',
            'ordem_exibicao' => 1,
            'prazo_padrao_dias' => 30,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('financeiro_cartao_bandeiras')->insert([
            'id' => 1,
            'nome' => 'Visa',
            'ordem_exibicao' => 1,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('financeiro_cartao_taxas')->insert([
            'operadora_id' => 1,
            'bandeira_id' => 1,
            'modalidade' => 'credito',
            'parcelas_inicial' => 1,
            'parcelas_final' => 6,
            'taxa_percentual' => 3.19,
            'taxa_fixa' => 0.00,
            'prazo_recebimento_dias' => 30,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function loginAndGetToken(string $email): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Senha@123',
            'device_name' => 'desktop-vendas',
        ]);

        return (string) $response->json('data.access_token');
    }
}
