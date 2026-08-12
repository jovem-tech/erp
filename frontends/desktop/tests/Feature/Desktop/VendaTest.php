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
