<?php

namespace Tests\Feature\Desktop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Devolução e troca no desktop — specs/029-devolucao-troca.
 */
class DevolucaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_tela_de_devolucao_mostra_saldo_e_efeito_no_estoque(): void
    {
        Http::fake($this->fixtures());

        $this->withSession($this->desktopSession(['vendas' => ['visualizar', 'criar']]))
            ->get('/vendas/77/devolver')
            ->assertOk()
            ->assertSee('Devolver venda VD-2608-000001')
            ->assertSee('Película 3D')
            ->assertSee('volta ao estoque')
            // Serviço não movimenta estoque: precisa ficar explícito na tela.
            ->assertSee('não movimenta estoque')
            ->assertSee('name="creation_request_id"', false);
    }

    public function test_venda_fora_do_prazo_pede_credencial_de_administrador(): void
    {
        Http::fake($this->fixtures(exigeAutorizacao: true));

        $this->withSession($this->desktopSession(['vendas' => ['visualizar', 'criar']]))
            ->get('/vendas/77/devolver')
            ->assertOk()
            ->assertSee('exige')
            ->assertSee('name="admin_email"', false)
            ->assertSee('name="admin_password"', false);
    }

    public function test_linhas_com_quantidade_zero_nao_sao_enviadas(): void
    {
        Http::fake($this->fixtures());

        $this->withSession($this->desktopSession(['vendas' => ['visualizar', 'criar']]))
            ->from('/vendas/77/devolver')
            ->post('/vendas/77/devolucoes', [
                'motivo' => 'Cliente devolveu uma unidade',
                'itens' => [
                    ['venda_item_id' => 10, 'quantidade' => '1'],
                    // Checkbox não marcado: não deve virar item da devolução.
                    ['venda_item_id' => 11, 'quantidade' => '0'],
                ],
            ])
            ->assertRedirect('/devolucoes/55');

        Http::assertSent(static function ($request): bool {
            if ($request->url() !== 'http://127.0.0.1:8000/api/v1/vendas/77/devolucoes') {
                return false;
            }

            return count($request['itens']) === 1
                && (int) $request['itens'][0]['venda_item_id'] === 10;
        });
    }

    public function test_devolucao_sem_nenhuma_quantidade_e_recusada_antes_da_api(): void
    {
        Http::fake($this->fixtures());

        $this->withSession($this->desktopSession(['vendas' => ['visualizar', 'criar']]))
            ->from('/vendas/77/devolver')
            ->post('/vendas/77/devolucoes', [
                'motivo' => 'Nada selecionado',
                'itens' => [['venda_item_id' => 10, 'quantidade' => '0']],
            ])
            ->assertRedirect('/vendas/77/devolver')
            ->assertSessionHas('error');

        Http::assertNotSent(static fn ($request): bool => $request->url() === 'http://127.0.0.1:8000/api/v1/vendas/77/devolucoes');
    }

    public function test_detalhe_mostra_taxa_nao_estornada(): void
    {
        Http::fake($this->fixtures());

        $this->withSession($this->desktopSession(['vendas' => ['visualizar']]))
            ->get('/devolucoes/55')
            ->assertOk()
            ->assertSee('Devolução DV-2608-000001')
            ->assertSee('não devolve a taxa');
    }

    public function test_usuario_sem_permissao_de_criar_nao_abre_a_devolucao(): void
    {
        Http::fake($this->fixtures());

        $this->withSession($this->desktopSession(['vendas' => ['visualizar']]))
            ->get('/vendas/77/devolver')
            ->assertRedirect('/vendas')
            ->assertSessionHas('error');
    }

    /** @return array<string, mixed> */
    private function fixtures(bool $exigeAutorizacao = false): array
    {
        return [
            'http://127.0.0.1:8000/api/v1/vendas/77/devolvivel' => Http::response([
                'status' => 'success',
                'data' => [
                    'venda' => [
                        'id' => 77, 'numero' => 'VD-2608-000001',
                        'total' => 90.0, 'valor_pago' => 90.0, 'cancelada' => false,
                    ],
                    'itens' => [
                        [
                            'venda_item_id' => 10, 'descricao' => 'Película 3D', 'codigo' => 'PC00010',
                            'tipo_item' => 'peca', 'tipo_item_label' => 'Produto',
                            'quantidade_vendida' => 2.0, 'quantidade_devolvida' => 0.0,
                            'quantidade_disponivel' => 2.0, 'valor_unitario' => 50.0,
                            'reembolso_unitario' => 45.0, 'retorna_estoque' => true, 'referencia_id' => 9,
                        ],
                        [
                            'venda_item_id' => 11, 'descricao' => 'Instalação', 'codigo' => '',
                            'tipo_item' => 'servico', 'tipo_item_label' => 'Serviço',
                            'quantidade_vendida' => 1.0, 'quantidade_devolvida' => 0.0,
                            'quantidade_disponivel' => 1.0, 'valor_unitario' => 25.0,
                            'reembolso_unitario' => 22.5, 'retorna_estoque' => false, 'referencia_id' => 3,
                        ],
                    ],
                    'exige_autorizacao' => $exigeAutorizacao,
                    'prazo_livre_dias' => 7,
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/vendas/77/devolucoes' => Http::response([
                'status' => 'success',
                'data' => ['devolucao' => ['id' => 55, 'numero' => 'DV-2608-000001']],
                'error' => null,
                'meta' => [],
            ], 201),
            'http://127.0.0.1:8000/api/v1/devolucoes/55' => Http::response([
                'status' => 'success',
                'data' => ['devolucao' => [
                    'id' => 55, 'numero' => 'DV-2608-000001',
                    'venda_id' => 77, 'venda_numero' => 'VD-2608-000001',
                    'cliente_nome' => 'Consumidor final',
                    'data_devolucao' => '2026-08-14',
                    'motivo' => 'Produto com defeito',
                    'valor_devolvido' => 45.0, 'valor_reembolsado' => 45.0,
                    'valor_abatido' => 0.0, 'valor_taxa_nao_estornada' => 1.44,
                    'criado_por_nome' => 'Administrador',
                    'itens' => [[
                        'id' => 1, 'venda_item_id' => 10, 'descricao' => 'Película 3D',
                        'codigo' => 'PC00010', 'quantidade' => 1.0, 'valor_unitario' => 50.0,
                        'valor_total' => 50.0, 'valor_reembolsado' => 45.0, 'retorna_estoque' => true,
                    ]],
                    'pagamentos' => [[
                        'id' => 1, 'forma_pagamento' => 'cartao_credito',
                        'valor' => 45.0, 'valor_taxa_nao_estornada' => 1.44,
                    ]],
                ]],
                'error' => null,
                'meta' => [],
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
                    'id' => 1, 'nome' => 'Administrador', 'email' => 'admin@example.com',
                    'perfil' => 'admin', 'ativo' => true,
                    'modules' => array_keys($permissions),
                    'permissions' => $permissions,
                ],
            ],
        ];
    }
}
