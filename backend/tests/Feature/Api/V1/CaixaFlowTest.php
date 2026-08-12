<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Turnos de caixa — specs/028-caixa-sessoes/spec.md.
 */
class CaixaFlowTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->grantGroupPermissions(1, [
            'caixa' => ['visualizar', 'criar', 'editar', 'excluir'],
            'vendas' => ['visualizar', 'criar', 'editar', 'excluir'],
        ]);
        $this->grantGroupPermissions(3, [
            'caixa' => ['visualizar'],
            'vendas' => ['visualizar', 'criar'],
        ]);

        $this->seedFinanceCategory();
    }

    public function test_abertura_declarada_registra_o_troco_inicial(): void
    {
        $token = $this->loginAndGetToken($this->createAdmin()->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/caixa/abrir', ['valor_abertura' => 150.00, 'observacoes' => 'Troco do dia'])
            ->assertCreated()
            ->assertJsonPath('data.sessao.status', 'aberta')
            ->assertJsonPath('data.sessao.valor_abertura', 150.0)
            ->assertJsonPath('data.sessao.abertura_automatica', false);

        // Segundo caixa não abre com um turno em andamento.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/caixa/abrir', ['valor_abertura' => 50.00])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CAIXA_ABERTURA_INVALIDA');

        $this->assertSame(1, DB::table('caixa_sessoes')->count());
    }

    public function test_caixa_aberto_nao_revela_o_valor_esperado(): void
    {
        $token = $this->loginAndGetToken($this->createAdmin()->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/caixa/abrir', ['valor_abertura' => 100.00])
            ->assertCreated();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/caixa/atual')
            ->assertOk()
            ->assertJsonPath('data.sessao.status', 'aberta');

        // Conferência cega: mostrar o esperado antes da contagem transformaria
        // o fechamento em "digitar o número que o sistema quer".
        $this->assertArrayNotHasKey('valor_esperado', (array) $response->json('data.sessao'));
    }

    public function test_venda_em_dinheiro_abre_o_caixa_automaticamente(): void
    {
        $admin = $this->createAdmin();
        $token = $this->loginAndGetToken($admin->email);

        $partId = $this->createPecaRecord(['codigo' => 'PC00050', 'preco_venda' => 40.00, 'quantidade_atual' => 10]);

        // A abertura automática vale a partir do segundo turno: o primeiro é
        // sempre explícito, porque é ele que cria a conta de caixa.
        $primeiro = (int) $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/caixa/abrir', ['valor_abertura' => 0])
            ->assertCreated()
            ->json('data.sessao.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/caixa/{$primeiro}/fechar", ['valor_informado' => 0])
            ->assertOk();

        $this->assertSame(0, DB::table('caixa_sessoes')->where('status', 'aberta')->count());

        $sale = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', [
                'itens' => [['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => 1, 'valor_unitario' => 40.00]],
                'pagamentos' => [['forma_pagamento' => 'dinheiro', 'valor' => 40.00, 'valor_recebido' => 50.00]],
            ])->assertCreated();

        $this->assertSame(2, DB::table('caixa_sessoes')->count());

        $session = DB::table('caixa_sessoes')->where('status', 'aberta')->first();
        $this->assertSame('aberta', $session->status);
        $this->assertSame(1, (int) $session->abertura_automatica);
        // Primeira vez de todas: sem fechamento anterior para herdar.
        $this->assertSame(0.0, (float) $session->valor_abertura);

        // A venda ficou amarrada ao turno.
        $this->assertSame(
            (int) $session->id,
            (int) DB::table('vendas')->where('id', (int) $sale->json('data.venda.id'))->value('caixa_sessao_id')
        );

        // E o dinheiro caiu na conta de caixa, sem o operador escolher nada.
        $accountId = (int) DB::table('financeiro_contas')->where('tipo', 'caixa')->value('id');
        $this->assertSame(
            $accountId,
            (int) DB::table('venda_pagamentos')->where('forma_pagamento', 'dinheiro')->value('conta_financeira_id')
        );
    }

    public function test_venda_no_pix_nao_abre_caixa(): void
    {
        $token = $this->loginAndGetToken($this->createAdmin()->email);

        $partId = $this->createPecaRecord(['codigo' => 'PC00051', 'preco_venda' => 60.00, 'quantidade_atual' => 5]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', [
                'itens' => [['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => 1, 'valor_unitario' => 60.00]],
                'pagamentos' => [[
                    'forma_pagamento' => 'pix',
                    'valor' => 60.00,
                    'conta_financeira_id' => $this->createBankAccount(),
                ]],
            ])->assertCreated();

        // Pix não passa pela gaveta.
        $this->assertSame(0, DB::table('caixa_sessoes')->count());
    }

    /**
     * Consequência da adoção do caixa, documentada de propósito.
     *
     * Enquanto nenhuma conta financeira existe, o sistema opera sem rastreio de
     * conta e qualquer forma de pagamento passa. A partir da primeira conta —
     * criada pela primeira abertura de caixa — o motor financeiro passa a
     * exigir conta em toda baixa. Dinheiro fica resolvido pela gaveta do turno;
     * as demais formas dependem do padrão de Financeiro > Contas.
     */
    public function test_apos_adotar_o_caixa_forma_sem_padrao_exige_conta_explicita(): void
    {
        $token = $this->loginAndGetToken($this->createAdmin()->email);

        $partId = $this->createPecaRecord(['codigo' => 'PC00055', 'preco_venda' => 30.00, 'quantidade_atual' => 5]);

        // Antes de adotar o caixa, pix sem conta passa.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', [
                'itens' => [['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => 1, 'valor_unitario' => 30.00]],
                'pagamentos' => [['forma_pagamento' => 'pix', 'valor' => 30.00]],
            ])->assertCreated();

        // A primeira abertura cria a conta de caixa e liga o rastreio.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/caixa/abrir', ['valor_abertura' => 0])
            ->assertCreated();

        $payload = [
            'itens' => [['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => 1, 'valor_unitario' => 30.00]],
            'pagamentos' => [['forma_pagamento' => 'pix', 'valor' => 30.00]],
        ];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VENDA_INVALIDA');

        // A venda recusada não foi gravada; só a primeira, de antes da adoção.
        $this->assertSame(1, DB::table('vendas')->count());

        // Com a conta informada, passa.
        $payload['pagamentos'][0]['conta_financeira_id'] = $this->createBankAccount();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', $payload)
            ->assertCreated();
    }

    public function test_sangria_gera_transferencia_e_respeita_o_dinheiro_disponivel(): void
    {
        $token = $this->loginAndGetToken($this->createAdmin()->email);

        $bankId = $this->createBankAccount();

        $sessionId = (int) $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/caixa/abrir', ['valor_abertura' => 200.00])
            ->assertCreated()
            ->json('data.sessao.id');

        // Sangria maior que o dinheiro em caixa é recusada.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/caixa/{$sessionId}/movimentos", [
                'tipo' => 'sangria',
                'valor' => 500.00,
                'motivo' => 'Depósito bancário',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CAIXA_MOVIMENTO_INVALIDO');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/caixa/{$sessionId}/movimentos", [
                'tipo' => 'sangria',
                'valor' => 120.00,
                'motivo' => 'Depósito bancário',
                'conta_destino_id' => $bankId,
            ])
            ->assertCreated()
            ->assertJsonPath('data.sessao.total_sangrias', 120.0);

        // Sangria com destino é transferência de verdade entre contas.
        $movement = DB::table('caixa_movimentos')->where('caixa_sessao_id', $sessionId)->first();
        $this->assertNotNull($movement->transferencia_id);
        $this->assertDatabaseHas('financeiro_transferencias', [
            'id' => $movement->transferencia_id,
            'conta_destino_id' => $bankId,
            'valor' => 120.00,
        ]);

        // Suprimento devolve troco à gaveta.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/caixa/{$sessionId}/movimentos", [
                'tipo' => 'suprimento',
                'valor' => 30.00,
                'motivo' => 'Reforço de troco',
            ])
            ->assertCreated()
            ->assertJsonPath('data.sessao.total_suprimentos', 30.0);
    }

    public function test_fechamento_calcula_esperado_e_diferenca(): void
    {
        $admin = $this->createAdmin();
        $token = $this->loginAndGetToken($admin->email);

        $partId = $this->createPecaRecord(['codigo' => 'PC00052', 'preco_venda' => 25.00, 'quantidade_atual' => 20]);

        $sessionId = (int) $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/caixa/abrir', ['valor_abertura' => 100.00])
            ->assertCreated()
            ->json('data.sessao.id');

        // Duas vendas em dinheiro: 50 + 25 = 75. O troco não entra na conta —
        // a gaveta ganha o recebido e devolve a diferença.
        foreach ([2, 1] as $quantity) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->postJson('/api/v1/vendas', [
                    'itens' => [['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => $quantity, 'valor_unitario' => 25.00]],
                    'pagamentos' => [[
                        'forma_pagamento' => 'dinheiro',
                        'valor' => 25.00 * $quantity,
                        'valor_recebido' => 100.00,
                    ]],
                ])->assertCreated();
        }

        // Uma venda no Pix, que não deve entrar na conferência da gaveta.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', [
                'itens' => [['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => 1, 'valor_unitario' => 25.00]],
                'pagamentos' => [[
                    'forma_pagamento' => 'pix',
                    'valor' => 25.00,
                    'conta_financeira_id' => $this->createBankAccount(),
                ]],
            ])->assertCreated();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/caixa/{$sessionId}/movimentos", [
                'tipo' => 'sangria',
                'valor' => 50.00,
                'motivo' => 'Retirada para o cofre',
            ])->assertCreated();

        // Esperado: 100 (abertura) + 75 (dinheiro) - 50 (sangria) = 125.
        // Contado: 120 -> falta 5.
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/caixa/{$sessionId}/fechar", [
                'valor_informado' => 120.00,
                'observacoes' => 'Faltou troco',
            ])
            ->assertOk()
            ->assertJsonPath('data.sessao.status', 'fechada')
            ->assertJsonPath('data.sessao.valor_esperado', 125.0)
            ->assertJsonPath('data.sessao.valor_informado', 120.0)
            ->assertJsonPath('data.sessao.diferenca', -5.0);

        // Totais congelados no fechamento.
        $this->assertSame(75.0, (float) $response->json('data.sessao.total_vendas_dinheiro'));
        $this->assertSame(2, (int) $response->json('data.sessao.quantidade_vendas'));

        // Caixa fechado não aceita mais movimento.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/caixa/{$sessionId}/movimentos", [
                'tipo' => 'suprimento',
                'valor' => 10.00,
                'motivo' => 'Tentativa fora do turno',
            ])
            ->assertStatus(422);
    }

    public function test_venda_cancelada_sai_do_esperado(): void
    {
        $token = $this->loginAndGetToken($this->createAdmin()->email);

        $partId = $this->createPecaRecord(['codigo' => 'PC00053', 'preco_venda' => 80.00, 'quantidade_atual' => 10]);

        $sessionId = (int) $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/caixa/abrir', ['valor_abertura' => 0])
            ->assertCreated()
            ->json('data.sessao.id');

        $saleId = (int) $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', [
                'itens' => [['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => 1, 'valor_unitario' => 80.00]],
                'pagamentos' => [['forma_pagamento' => 'dinheiro', 'valor' => 80.00]],
            ])->assertCreated()->json('data.venda.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/vendas/{$saleId}/cancelar", ['motivo' => 'Cliente desistiu da compra'])
            ->assertOk();

        // O dinheiro voltou para o cliente, então não está mais na gaveta.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/caixa/{$sessionId}/fechar", ['valor_informado' => 0])
            ->assertOk()
            ->assertJsonPath('data.sessao.valor_esperado', 0.0)
            ->assertJsonPath('data.sessao.diferenca', 0.0);
    }

    public function test_reabertura_exige_credencial_de_administrador(): void
    {
        $admin = $this->createAdmin();
        $token = $this->loginAndGetToken($admin->email);

        $sessionId = (int) $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/caixa/abrir', ['valor_abertura' => 10.00])
            ->assertCreated()
            ->json('data.sessao.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/caixa/{$sessionId}/fechar", ['valor_informado' => 10.00])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/caixa/{$sessionId}/reabrir", [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CAIXA_ADMIN_AUTH_REQUIRED');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/caixa/{$sessionId}/reabrir", [
                'admin_email' => $admin->email,
                'admin_password' => 'Senha@123',
            ])
            ->assertOk()
            ->assertJsonPath('data.sessao.status', 'aberta')
            // A conferência anterior deixa de valer.
            ->assertJsonPath('data.sessao.valor_informado', null)
            ->assertJsonPath('data.sessao.diferenca', null);
    }

    public function test_correcao_do_valor_de_abertura_limpa_a_marca_de_automatica(): void
    {
        $token = $this->loginAndGetToken($this->createAdmin()->email);

        $partId = $this->createPecaRecord(['codigo' => 'PC00054', 'preco_venda' => 15.00, 'quantidade_atual' => 5]);

        $primeiro = (int) $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/caixa/abrir', ['valor_abertura' => 0])
            ->assertCreated()
            ->json('data.sessao.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/caixa/{$primeiro}/fechar", ['valor_informado' => 0])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vendas', [
                'itens' => [['tipo_item' => 'peca', 'referencia_id' => $partId, 'quantidade' => 1, 'valor_unitario' => 15.00]],
                'pagamentos' => [['forma_pagamento' => 'dinheiro', 'valor' => 15.00]],
            ])->assertCreated();

        $sessionId = (int) DB::table('caixa_sessoes')->where('status', 'aberta')->value('id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/v1/caixa/{$sessionId}/abertura", ['valor_abertura' => 80.00])
            ->assertOk()
            ->assertJsonPath('data.sessao.valor_abertura', 80.0)
            ->assertJsonPath('data.sessao.abertura_automatica', false);
    }

    public function test_usuario_sem_permissao_nao_abre_nem_fecha_caixa(): void
    {
        $viewer = $this->createUserRecord([
            'nome' => 'Atendente',
            'email' => 'atendente.caixa@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 3,
        ]);
        $token = $this->loginAndGetToken($viewer->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/caixa/abrir', ['valor_abertura' => 10.00])
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/caixa/atual')
            ->assertOk();
    }

    /**
     * Conta bancária de apoio, reutilizada entre chamadas do mesmo teste.
     */
    private function createBankAccount(): int
    {
        $existing = (int) DB::table('financeiro_contas')->where('nome', 'Banco Teste')->value('id');

        if ($existing > 0) {
            return $existing;
        }

        return (int) DB::table('financeiro_contas')->insertGetId([
            'nome' => 'Banco Teste',
            'tipo' => 'banco',
            'data_inicio_controle' => now()->subMonth()->toDateString(),
            'considera_disponivel' => 1,
            'ativo' => 1,
            'cor' => '#3868B0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAdmin(): object
    {
        return $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.caixa@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);
    }

    private function seedFinanceCategory(): void
    {
        $groupId = (int) DB::table('financeiro_dre_grupos')->where('nome', 'Receita Operacional')->value('id');

        if ($groupId <= 0 || DB::table('financeiro_categorias')->where('nome', 'Venda de balcão')->exists()) {
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

    private function loginAndGetToken(string $email): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Senha@123',
            'device_name' => 'desktop-caixa',
        ])->json('data.access_token');
    }
}
