<?php

namespace Tests\Feature\Desktop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Entrada de estoque no lancamento — specs/039, lado desktop.
 *
 * A regra de negocio (so "pagar", so criacao, soma <= valor, atomicidade) vive
 * no backend e e testada la. Aqui se prova o que e responsabilidade da tela:
 * quem ve a secao, o que viaja no POST e o que nao viaja.
 */
class FinanceiroEntradaEstoqueTest extends TestCase
{
    use RefreshDatabase;

    private function fakeCatalogo(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response([
                'status' => 'success',
                'data' => ['categorias' => [
                    ['nome' => 'Compra de peças', 'tipo' => 'pagar', 'dre_grupo' => ['nome' => 'Custo Direto (OS)']],
                    ['nome' => 'Energia', 'tipo' => 'pagar', 'dre_grupo' => ['nome' => 'Despesas Operacionais']],
                ]],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
        ]);
    }

    /**
     * @param array<string, array<int, string>> $permissoes
     * @param array<string, string> $old
     */
    private function abrirCriacao(array $permissoes, array $old = []): \Illuminate\Testing\TestResponse
    {
        $this->fakeCatalogo();

        return $this
            ->withSession(array_merge(
                $this->desktopSession($permissoes),
                $old ? ['_old_input' => $old] : []
            ))
            ->get('/financeiro/novo');
    }

    private const PERMISSOES_COMPLETAS = [
        'financeiro' => ['visualizar', 'criar'],
        'estoque' => ['visualizar', 'editar', 'criar'],
    ];

    /**
     * A seção acompanha a CATEGORIA, não só o tipo: imposto, aluguel e folha
     * são "a pagar" e nunca dão entrada em estoque.
     */
    public function test_secao_aparece_na_categoria_de_compra_de_peca(): void
    {
        $response = $this->abrirCriacao(self::PERMISSOES_COMPLETAS, [
            'tipo' => 'pagar',
            'categoria' => 'Compra de peças',
        ]);

        $response->assertOk()->assertSee('ENTRADA NO ESTOQUE');
        $this->assertDoesNotMatchRegularExpression(
            '/id="financeiroEntradaEstoqueSection"[^>]*\bd-none\b/',
            $response->getContent()
        );
    }

    public function test_secao_fica_escondida_em_despesa_que_nao_e_peca(): void
    {
        $response = $this->abrirCriacao(self::PERMISSOES_COMPLETAS, [
            'tipo' => 'pagar',
            'categoria' => 'Energia',
        ]);

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/id="financeiroEntradaEstoqueSection"[^>]*\bd-none\b/',
            $response->getContent()
        );
    }

    public function test_secao_fica_escondida_em_recebimento(): void
    {
        $response = $this->abrirCriacao(self::PERMISSOES_COMPLETAS);

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/id="financeiroEntradaEstoqueSection"[^>]*\bd-none\b/',
            $response->getContent()
        );
    }

    /**
     * O botão "Entrada por compra" da tela de Estoque precisa cair numa tela
     * onde a seção prometida está à vista — logo, já com a categoria escolhida.
     */
    public function test_atalho_de_entrada_por_compra_ja_escolhe_a_categoria(): void
    {
        $this->fakeCatalogo();

        $response = $this
            ->withSession($this->desktopSession(self::PERMISSOES_COMPLETAS))
            ->get('/financeiro/novo?tipo=pagar&entrada_estoque=1');

        $response->assertOk();
        $content = $response->getContent();

        // A <option> é multilinha no Blade, então `value` e `selected` não são
        // adjacentes — casar o bloco inteiro.
        $this->assertMatchesRegularExpression(
            '/<option\s+value="Compra de peças".*?\bselected\b/s',
            $content
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="financeiroEntradaEstoqueSection"[^>]*\bd-none\b/',
            $content
        );
    }

    /**
     * Degradar escondendo, nao desabilitando: bloco desabilitado anuncia uma
     * capacidade que o operador nao consegue destravar sozinho.
     */
    public function test_secao_nao_aparece_para_quem_so_tem_financeiro(): void
    {
        $this->fakeCatalogo();

        $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar']]))
            ->get('/financeiro/novo')
            ->assertOk()
            ->assertDontSee('ENTRADA NO ESTOQUE');
    }

    /**
     * Busca bate em GET /estoque e gravacao exige editar: com so um dos dois a
     * tela renderizaria um campo que so devolve 403.
     */
    public function test_secao_nao_aparece_sem_permissao_de_editar_estoque(): void
    {
        $this->fakeCatalogo();

        $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar', 'criar'],
                'estoque' => ['visualizar'],
            ]))
            ->get('/financeiro/novo')
            ->assertOk()
            ->assertDontSee('ENTRADA NO ESTOQUE');
    }

    public function test_botao_de_cadastro_rapido_some_sem_permissao_de_criar_peca(): void
    {
        $this->fakeCatalogo();

        $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar', 'criar'],
                'estoque' => ['visualizar', 'editar'],
            ]))
            ->get('/financeiro/novo')
            ->assertOk()
            ->assertSee('ENTRADA NO ESTOQUE')
            ->assertDontSee('Cadastrar peça nova');
    }

    public function test_store_encaminha_itens_estoque_para_a_api(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro' => Http::response([
                'status' => 'success',
                'data' => ['lancamento' => ['id' => 1, 'tipo' => 'pagar', 'status' => 'pendente']],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar', 'criar'],
                'estoque' => ['visualizar', 'editar'],
            ]))
            ->post('/financeiro', [
                'tipo' => 'pagar',
                'categoria' => 'Compra de peças',
                'descricao' => 'Nota do fornecedor',
                'valor' => 500.00,
                'data_vencimento' => now()->toDateString(),
                'entrada_estoque' => '1',
                'itens_estoque' => [
                    ['peca_id' => '7', 'quantidade' => '3', 'custo_unitario' => '110.00'],
                ],
            ])
            ->assertRedirect(route('financeiro.index'));

        Http::assertSent(static function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/api/v1/financeiro'
                && $request->method() === 'POST'
                && is_array($request['itens_estoque'])
                && count($request['itens_estoque']) === 1
                && (int) $request['itens_estoque'][0]['peca_id'] === 7;
        });
    }

    /**
     * O switch desligado tem de desligar de verdade. A secao fica montada na
     * tela mesmo desmarcada — sem a guarda no controller, desmarcar nao
     * desmarcaria nada e a compra entraria no estoque assim mesmo.
     */
    public function test_store_nao_encaminha_itens_com_o_switch_desligado(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro' => Http::response([
                'status' => 'success',
                'data' => ['lancamento' => ['id' => 2, 'tipo' => 'pagar', 'status' => 'pendente']],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar', 'criar'],
                'estoque' => ['visualizar', 'editar'],
            ]))
            ->post('/financeiro', [
                'tipo' => 'pagar',
                'categoria' => 'Energia',
                'descricao' => 'Conta de luz',
                'valor' => 300.00,
                'data_vencimento' => now()->toDateString(),
                'entrada_estoque' => '0',
                'itens_estoque' => [
                    ['peca_id' => '7', 'quantidade' => '3', 'custo_unitario' => '10.00'],
                ],
            ])
            ->assertRedirect(route('financeiro.index'));

        Http::assertSent(static fn ($request): bool => ! isset($request['itens_estoque']));
    }

    /**
     * Linha em branco e a que o operador adicionou e nao preencheu — nao pode
     * virar erro de validacao do backend.
     */
    public function test_store_descarta_linhas_em_branco(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro' => Http::response([
                'status' => 'success',
                'data' => ['lancamento' => ['id' => 3, 'tipo' => 'pagar', 'status' => 'pendente']],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar', 'criar'],
                'estoque' => ['visualizar', 'editar'],
            ]))
            ->post('/financeiro', [
                'tipo' => 'pagar',
                'categoria' => 'Compra de peças',
                'descricao' => 'Nota',
                'valor' => 100.00,
                'data_vencimento' => now()->toDateString(),
                'entrada_estoque' => '1',
                'itens_estoque' => [
                    ['peca_id' => '7', 'quantidade' => '1', 'custo_unitario' => '10.00'],
                    ['peca_id' => '0', 'quantidade' => '0'],
                ],
            ])
            ->assertRedirect(route('financeiro.index'));

        Http::assertSent(static fn ($request): bool => count($request['itens_estoque']) === 1);
    }

    public function test_busca_de_pecas_devolve_o_formato_do_select2(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/estoque*' => Http::response([
                'status' => 'success',
                'data' => ['pecas' => [[
                    'id' => 9,
                    'codigo' => 'PC0009',
                    'nome' => 'DISPLAY IPHONE 11',
                    'quantidade_atual' => 4,
                    'preco_custo' => 100.5,
                    'preco_venda' => 220.0,
                    'unidade' => 'UN',
                ]]],
                'error' => null,
                'meta' => ['pagination' => ['has_more' => false]],
            ], 200),
        ]);

        $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar', 'criar'],
                'estoque' => ['visualizar', 'editar'],
            ]))
            ->getJson('/financeiro/pecas/buscar?q=display')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('parts.0.id', 9)
            ->assertJsonPath('parts.0.text', 'PC0009 — DISPLAY IPHONE 11')
            ->assertJsonPath('parts.0.saldo', 4)
            ->assertJsonPath('parts.0.preco_custo', 100.5);
    }

    /**
     * Sem `estoque:visualizar` a rota nem responde.
     *
     * O middleware da casa REDIRECIONA (302) para a primeira rota permitida em
     * vez de devolver 403 — só usa 403 quando a rota negada já é o destino do
     * redirect. Vale para todas as rotas de busca do financeiro
     * (clientes/ordens/fornecedores), não é particularidade desta.
     */
    public function test_busca_de_pecas_exige_permissao_de_estoque(): void
    {
        $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar']]))
            ->getJson('/financeiro/pecas/buscar?q=display')
            ->assertRedirect();
    }

    /**
     * O botao "Entrada por compra" na tela de Estoque e o que faz a
     * funcionalidade ser encontrada: quem pensa "chegou peca" esta la, nao no
     * Financeiro.
     */
    public function test_estoque_mostra_atalho_de_entrada_por_compra(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/estoque*' => Http::response([
                'status' => 'success',
                'data' => ['pecas' => []],
                'error' => null,
                'meta' => ['pagination' => ['total' => 0]],
            ], 200),
        ]);

        $this
            ->withSession($this->desktopSession([
                'estoque' => ['visualizar', 'editar'],
                'financeiro' => ['visualizar', 'criar'],
            ]))
            ->get('/estoque')
            ->assertOk()
            ->assertSee('Entrada por compra');
    }

    public function test_estoque_esconde_atalho_sem_permissao_de_financeiro(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/estoque*' => Http::response([
                'status' => 'success',
                'data' => ['pecas' => []],
                'error' => null,
                'meta' => ['pagination' => ['total' => 0]],
            ], 200),
        ]);

        $this
            ->withSession($this->desktopSession(['estoque' => ['visualizar', 'editar']]))
            ->get('/estoque')
            ->assertOk()
            ->assertDontSee('Entrada por compra');
    }

    // Helpers privados, no mesmo formato de FinanceiroTest — a casa repete o
    // trio em cada classe de teste em vez de extrair trait.

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
     * @param  array<string, array<int, string>>  $permissions
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
     * @param  array<string, mixed>  $overrides
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
