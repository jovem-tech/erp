<?php

namespace Tests\Feature\Desktop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Vendas de balcão no desktop — specs/027-vendas-balcao-pdv.
 */
class VendaTest extends TestCase
{
    use RefreshDatabase;

    public function test_listagem_exibe_vendas_e_totais_do_periodo(): void
    {
        Http::fake($this->fixtures());

        $this->withSession($this->desktopSession(['vendas' => ['visualizar', 'criar']]))
            ->get('/vendas')
            ->assertOk()
            ->assertSee('VD-2608-000001')
            ->assertSee('Maria Souza')
            ->assertSee('Total vendido')
            ->assertSee('Ticket médio');
    }

    public function test_pdv_carrega_catalogos_e_o_modelo_de_linha(): void
    {
        Http::fake($this->fixtures());

        $this->withSession($this->desktopSession(['vendas' => ['visualizar', 'criar']]))
            ->get('/vendas/nova')
            ->assertOk()
            ->assertSee('Buscar produto ou serviço')
            ->assertSee('Finalizar venda (F2)')
            // A chave de idempotência precisa ir no HTML: é ela que impede
            // que duplo clique em "Finalizar" vire duas vendas.
            ->assertSee('name="creation_request_id"', false)
            ->assertSee('id="pdvModeloItem"', false)
            ->assertSee('id="pdvModeloPagamento"', false)
            ->assertSee('vendas-pdv.js', false);
    }

    /**
     * Varredura de visibilidade — specs/037, Fase 5.
     *
     * Custo e margem apareciam na listagem e no detalhe da venda para qualquer
     * um com `vendas:visualizar` — inclusive o balconista. É o mesmo dado que o
     * dono acabou de decidir proteger no PDV e no orçamento.
     */
    public function test_listagem_esconde_o_card_de_margem_de_quem_nao_ve_financeiro(): void
    {
        Http::fake($this->fixtures());

        // Sem `financeiro:visualizar`.
        $this->withSession($this->desktopSession(['vendas' => ['visualizar']]))
            ->get('/vendas')
            ->assertOk()
            ->assertSee('Total vendido')
            ->assertDontSee('Margem');

        // Com permissão financeira o card volta.
        $this->withSession($this->desktopSession([
            'vendas' => ['visualizar'],
            'financeiro' => ['visualizar'],
        ]))
            ->get('/vendas')
            ->assertOk()
            ->assertSee('Margem');
    }

    /**
     * Margem no PDV — specs/037-precificacao-integrada-ao-fluxo.
     *
     * O backend sempre calculou custo e margem e mandava para o navegador; o JS
     * descartava (`grep custo vendas-pdv.js` nao devolvia nada). O operador
     * dava desconto as cegas. Estes sao os ganchos que passaram a existir.
     */
    public function test_pdv_tem_os_ganchos_de_margem_e_aviso_de_piso(): void
    {
        Http::fake($this->fixtures());

        $this->withSession($this->desktopSession(['vendas' => ['visualizar', 'criar']]))
            ->get('/vendas/nova')
            ->assertOk()
            // Margem por linha vive DENTRO da celula de total: o PDV roda em
            // tela cheia e uma setima coluna nao caberia.
            ->assertSee('pdv-item-margem', false)
            ->assertSee('pdv-item-total', false)
            // Resumo de custo/margem no rodape, escondido ate o backend
            // confirmar que este usuario pode ver numero.
            ->assertSee('id="pdvCustoTotal"', false)
            ->assertSee('id="pdvMargemTotal"', false)
            // O aviso de piso vale para todos, inclusive quem nao ve o numero.
            ->assertSee('id="pdvAvisoPiso"', false);
    }

    public function test_pdv_ocupa_a_tela_inteira_com_a_sidebar_recolhida(): void
    {
        Http::fake($this->fixtures());

        $this->withSession($this->desktopSession(['vendas' => ['visualizar', 'criar']]))
            ->get('/vendas/nova')
            ->assertOk()
            // Sidebar em modo sanduíche, como na listagem de OS: o PDV precisa
            // da largura inteira e não pode exigir rolagem.
            ->assertSee('desktop-main is-full', false)
            ->assertSee('is-hidden', false)
            // Três colunas: cliente/busca, carrinho ao centro, fechamento.
            ->assertSee('pdv-grid', false)
            ->assertSee('pdv-col-esquerda', false)
            ->assertSee('pdv-col-centro', false)
            ->assertSee('pdv-col-lateral', false)
            // Só o carrinho rola por dentro.
            ->assertSee('pdv-itens-scroll', false);
    }

    public function test_pdv_oferece_modo_terminal_em_tela_cheia(): void
    {
        Http::fake($this->fixtures());

        $this->withSession($this->desktopSession(['vendas' => ['visualizar', 'criar']]))
            ->get('/vendas/nova')
            ->assertOk()
            // F3 é o atalho; o botão existe porque em tablet não há tecla e a
            // Fullscreen API exige um gesto do usuário.
            ->assertSee('id="pdvTelaCheia"', false)
            ->assertSee('Tela cheia (F3)', false)
            ->assertSee('F3 tela cheia')
            // O CSS do modo terminal precisa esconder topbar e rodapé.
            ->assertSee('.pdv-modo-terminal .desktop-topbar', false)
            ->assertSee('.pdv-modo-terminal .desktop-system-footer', false);
    }

    public function test_modo_terminal_traz_marca_calendario_e_relogio(): void
    {
        Http::fake($this->fixtures());

        // Os três elementos só existem para o CSS do modo terminal mostrar
        // (.pdv-modo-terminal ...); fora dele ficam ocultos por padrão.
        $this->withSession($this->desktopSession(['vendas' => ['visualizar', 'criar']]))
            ->get('/vendas/nova')
            ->assertOk()
            ->assertSee('pdv-terminal-header', false)
            ->assertSee('pdv-terminal-empresa', false)
            ->assertSee('id="pdvTerminalCalendario"', false)
            ->assertSee('id="pdvTerminalRelogio"', false);
    }

    public function test_carrinho_fica_na_coluna_central(): void
    {
        Http::fake($this->fixtures());

        $html = $this->withSession($this->desktopSession(['vendas' => ['visualizar', 'criar']]))
            ->get('/vendas/nova')
            ->assertOk()
            ->getContent();

        // Busca pelo atributo, não pelo nome da classe: o nome também aparece
        // no bloco <style> no topo da página e daria posição errada.
        $centro = strpos($html, 'class="pdv-col-centro"');
        $lateral = strpos($html, 'class="pdv-col-lateral"');
        $tabela = strpos($html, 'id="pdvTabelaItens"');

        $this->assertNotFalse($centro, 'Coluna central ausente.');
        $this->assertNotFalse($lateral, 'Coluna de fechamento ausente.');

        // A tabela de itens precisa nascer dentro da coluna central — é ela que
        // cresce conforme a venda acontece.
        $this->assertGreaterThan($centro, $tabela, 'A tabela de itens deve vir depois da abertura da coluna central.');
        $this->assertLessThan($lateral, $tabela, 'A tabela de itens não pode cair na coluna de fechamento.');
    }

    public function test_busca_fica_na_coluna_central_acima_dos_itens(): void
    {
        Http::fake($this->fixtures());

        $html = $this->withSession($this->desktopSession(['vendas' => ['visualizar', 'criar']]))
            ->get('/vendas/nova')
            ->assertOk()
            ->getContent();

        $centro = strpos($html, 'class="pdv-col-centro"');
        $busca = strpos($html, 'id="pdvBusca"');
        $tabela = strpos($html, 'id="pdvTabelaItens"');

        $this->assertNotFalse($centro, 'Coluna central ausente.');

        // A busca nasce dentro da coluna central, não mais na esquerda: é lá
        // que o operador olha o item cair na lista assim que o adiciona.
        $this->assertGreaterThan($centro, $busca, 'A busca deve estar dentro da coluna central.');
        $this->assertLessThan($tabela, $busca, 'A busca deve vir antes da lista de itens.');
    }

    public function test_pdv_agrupa_cliente_e_vendedor_acima_da_busca(): void
    {
        Http::fake($this->fixtures());

        $html = $this->withSession($this->desktopSession(['vendas' => ['visualizar', 'criar']]))
            ->get('/vendas/nova')
            ->assertOk()
            ->getContent();

        // A ordem importa: quem compra e quem vende ficam no topo, a busca
        // logo abaixo e o carrinho por último.
        $posCliente = strpos($html, 'id="pdvCliente"');
        $posVendedor = strpos($html, 'id="pdvVendedor"');
        $posBusca = strpos($html, 'id="pdvBusca"');
        $posItens = strpos($html, 'id="pdvTabelaItens"');

        $this->assertLessThan($posVendedor, $posCliente);
        $this->assertLessThan($posBusca, $posVendedor);
        $this->assertLessThan($posItens, $posBusca);
    }

    public function test_pagamento_so_aparece_dentro_do_modal_de_finalizacao(): void
    {
        Http::fake($this->fixtures());

        $html = $this->withSession($this->desktopSession(['vendas' => ['visualizar', 'criar']]))
            ->get('/vendas/nova')
            ->assertOk()
            // O botão da coluna lateral só abre o modal — não é mais o submit.
            ->assertSee('id="pdvAbrirPagamento"', false)
            ->assertSee('data-bs-toggle="modal"', false)
            ->assertSee('data-bs-target="#pdvPagamentoModal"', false)
            // O submit de verdade mora dentro do modal.
            ->assertSee('id="pdvConfirmarFinalizar"', false)
            ->getContent();

        // A antiga seção "Pagamento" fora do modal não existe mais.
        $this->assertStringNotContainsString('pdv-bloco-pagamento', $html);

        // A lista de pagamentos precisa nascer DENTRO do modal, não solta na
        // coluna lateral — é isso que a mantém escondida até o clique.
        $posModal = strpos($html, 'id="pdvPagamentoModal"');
        $posPagamentos = strpos($html, 'id="pdvPagamentos"');
        $posFimModal = strpos($html, 'id="pdvConfirmarFinalizar"');

        $this->assertNotFalse($posModal);
        $this->assertGreaterThan($posModal, $posPagamentos, 'A lista de pagamentos precisa estar dentro do modal.');
        $this->assertLessThan($posFimModal, $posPagamentos, 'A lista de pagamentos precisa vir antes do botão de confirmar, dentro do modal.');
    }

    public function test_dropdown_de_busca_usa_posicionamento_fixo(): void
    {
        Http::fake($this->fixtures());

        // position: fixed é o que impede a lista de resultados de ficar
        // cortada pelo overflow:auto da coluna esquerda (válvula de segurança
        // de janela baixa) — position: absolute era clipado por ela.
        $this->withSession($this->desktopSession(['vendas' => ['visualizar', 'criar']]))
            ->get('/vendas/nova')
            ->assertOk()
            ->assertSee('.pdv-resultados {', false)
            ->assertSee('position: fixed', false);
    }

    public function test_finalizar_venda_envia_payload_normalizado_para_a_api(): void
    {
        Http::fake($this->fixtures());

        $this->withSession($this->desktopSession(['vendas' => ['visualizar', 'criar']]))
            ->from('/vendas/nova')
            ->post('/vendas', [
                'itens' => [
                    [
                        'tipo_item' => 'peca',
                        'referencia_id' => 9,
                        'quantidade' => '2',
                        // Máscara pt-BR: o backend valida como numeric, então o
                        // desktop precisa converter antes de enviar.
                        'valor_unitario' => '1.234,56',
                        'baixa_estoque' => '1',
                    ],
                ],
                'pagamentos' => [
                    ['forma_pagamento' => 'dinheiro', 'valor' => '2.469,12', 'valor_recebido' => '2.500,00'],
                ],
            ])
            ->assertRedirect('/vendas/77');

        Http::assertSent(static function ($request): bool {
            if ($request->url() !== 'http://127.0.0.1:8000/api/v1/vendas' || $request->method() !== 'POST') {
                return false;
            }

            return $request['itens'][0]['valor_unitario'] === '1234.56'
                && $request['pagamentos'][0]['valor'] === '2469.12'
                && $request['pagamentos'][0]['valor_recebido'] === '2500.00';
        });
    }

    public function test_usuario_sem_permissao_de_criar_nao_acessa_o_pdv(): void
    {
        Http::fake($this->fixtures());

        // EnsureRoutePermission redireciona para a primeira rota permitida com
        // aviso, em vez de devolver 403 — convenção do desktop.
        $this->withSession($this->desktopSession(['vendas' => ['visualizar']]))
            ->get('/vendas/nova')
            ->assertRedirect('/vendas')
            ->assertSessionHas('error');
    }

    /** @return array<string, mixed> */
    private function fixtures(): array
    {
        return [
            'http://127.0.0.1:8000/api/v1/vendas/form-data' => Http::response([
                'status' => 'success',
                'data' => [
                    'form' => [
                        'formas_pagamento' => [
                            ['value' => 'dinheiro', 'label' => 'Dinheiro', 'is_cartao' => false],
                            ['value' => 'cartao_credito', 'label' => 'Cartão de crédito', 'is_cartao' => true],
                        ],
                        'contas' => [['id' => 1, 'nome' => 'Caixa da loja', 'tipo' => 'caixa']],
                        'cartoes' => ['operadoras' => [], 'bandeiras' => [], 'taxas' => []],
                        'vendedores' => [['id' => 1, 'nome' => 'Administrador']],
                        'usuario_id' => 1,
                        'data_hoje' => '2026-08-12',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/vendas*' => Http::response([
                'status' => 'success',
                'data' => [
                    'vendas' => [[
                        'id' => 77,
                        'numero' => 'VD-2608-000001',
                        'status' => 'concluida',
                        'status_label' => 'Concluída',
                        'status_color' => '#16a34a',
                        'status_pagamento' => 'pago',
                        'status_pagamento_label' => 'Pago',
                        'cliente_nome' => 'Maria Souza',
                        'vendedor_nome' => 'Administrador',
                        'data_venda' => '2026-08-12',
                        'total' => 120.0,
                        'valor_pago' => 120.0,
                        'valor_aberto' => 0.0,
                        'estoque_divergente' => false,
                    ]],
                    'venda' => ['id' => 77, 'numero' => 'VD-2608-000001'],
                    'summary' => [
                        'total_vendas' => 1,
                        'total_vendido' => 120.0,
                        'total_margem' => 60.0,
                        'ticket_medio' => 120.0,
                        'margem_percentual' => 50.0,
                    ],
                    'status_options' => [['value' => 'concluida', 'label' => 'Concluída', 'color' => '#16a34a']],
                    'status_pagamento_options' => [['value' => 'pago', 'label' => 'Pago', 'color' => '#16a34a']],
                ],
                'error' => null,
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 15, 'total' => 1, 'last_page' => 1]],
            ]),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
                'status' => 'success',
                'data' => ['items' => [], 'unread_count' => 0],
                'error' => null,
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 6, 'total' => 0, 'last_page' => 1]],
            ]),
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
                'user' => [
                    'id' => 1,
                    'nome' => 'Administrador',
                    'email' => 'admin@example.com',
                    'perfil' => 'admin',
                    'ativo' => true,
                    'modules' => array_keys($permissions),
                    'permissions' => $permissions,
                ],
            ],
        ];
    }
}
