<?php

namespace Tests\Feature\Desktop;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Modal de detalhes do item no detalhe da OS: cada linha (peça ou serviço)
 * da seção "Peças e serviços do orçamento" ganha um botão que abre a ficha
 * do item — peça: cadastro de estoque, saldo, custo; serviço: catálogo,
 * tempo padrão, custo direto — renderizada a partir do payload da OS. O que
 * o modal mostra depende do que o backend mandou: custo só aparece quando a
 * chave existe no item (specs/037).
 */
class OrderItemDetalheTest extends TestCase
{
    public function test_part_and_service_rows_get_a_details_button_and_modal_with_sheet_and_cost(): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/orders/501' => Http::response($this->orderPayload([
                [
                    'id' => 11,
                    'tipo_item' => 'peca',
                    'referencia_id' => 7,
                    'descricao' => 'Tela LCD 15.6',
                    'quantidade' => 2.0,
                    'valor_unitario' => 120.0,
                    'desconto' => 0.0,
                    'acrescimo' => 0.0,
                    'total' => 240.0,
                    'observacoes' => 'Trocar com o cabo flat',
                    'preco_custo_referencia' => 140.0,
                    'valor_margem' => 25.0,
                    'percentual_margem' => 20.0,
                    'servico' => null,
                    'peca' => [
                        'id' => 7,
                        'codigo' => 'PC00042',
                        'codigo_fabricante' => 'LP156WH4',
                        'nome' => 'Tela LCD 15.6 HD',
                        'tipo_equipamento_efetivo' => 'Notebook',
                        'estoque_categoria_nome' => 'Display',
                        'categoria_efetiva' => 'LCD',
                        'modelos_compativeis' => 'Acer Aspire 5',
                        'fornecedor' => 'Distribuidora Norte',
                        'localizacao' => 'Prateleira B3',
                        'unidade' => 'UN',
                        'preco_venda' => 260.0,
                        'quantidade_atual' => 3.0,
                        'reservado_para_este' => 0.0,
                        'reservado_por_terceiros' => 1.0,
                        'quantidade_disponivel' => 2.0,
                        'falta' => 0.0,
                        'estado' => 'em_estoque',
                        'estoque_minimo' => 1.0,
                        'ativo' => true,
                        'status' => 'ativo',
                        'observacoes' => '',
                        'preco_custo' => 150.0,
                    ],
                ],
                [
                    'id' => 12,
                    'tipo_item' => 'servico',
                    'referencia_id' => 3,
                    'descricao' => 'Formatação e instalação',
                    'quantidade' => 1.0,
                    'valor_unitario' => 130.0,
                    'desconto' => 0.0,
                    'acrescimo' => 0.0,
                    'total' => 130.0,
                    'observacoes' => '',
                    'preco_custo_referencia' => 45.0,
                    'valor_margem' => 85.0,
                    'percentual_margem' => 65.4,
                    'peca' => null,
                    'servico' => [
                        'id' => 3,
                        'nome' => 'Formatação completa',
                        'descricao' => 'Backup, formatação e reinstalação do sistema',
                        'tipo_equipamento' => 'Notebook',
                        'unidade' => '',
                        'valor' => 150.0,
                        'tempo_padrao_horas' => 1.5,
                        'item_lc116' => '14.01',
                        'codigo_tributacao_nacional' => '140101',
                        'aliquota_iss' => 5.0,
                        'status' => 'ativo',
                        'ativo' => true,
                        'custo_direto_padrao' => 60.0,
                    ],
                ],
            ])),
        ]));

        $response = $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
                'os' => ['visualizar'],
                'estoque' => ['visualizar', 'editar'],
                'servicos' => ['visualizar', 'editar'],
                'financeiro' => ['visualizar'],
            ]))
            ->get('/os/501');

        $response
            ->assertOk()
            // Um botão e um modal por linha, peça e serviço
            ->assertSee('data-bs-target="#osItemDetalheModal-11"', false)
            ->assertSee('data-os-item-detalhe-trigger="11"', false)
            ->assertSee('id="osItemDetalheModal-11"', false)
            ->assertSee('data-bs-target="#osItemDetalheModal-12"', false)
            ->assertSee('data-os-item-detalhe-trigger="12"', false)
            ->assertSee('id="osItemDetalheModal-12"', false)
            ->assertSee('Detalhes da peça')
            ->assertSee('Detalhes do serviço')
            // Ficha de estoque da peça
            ->assertSee('Distribuidora Norte')
            ->assertSee('Prateleira B3')
            ->assertSee('PC00042')
            ->assertSee('LP156WH4')
            ->assertSee('Notebook › Display › LCD')
            ->assertSee('Trocar com o cabo flat')
            ->assertSee('Em estoque')
            ->assertSee('Reservado para outros')
            // Ficha de catálogo do serviço
            ->assertSee('No catálogo de serviços')
            ->assertSee('Formatação completa')
            ->assertSee('Backup, formatação e reinstalação do sistema')
            ->assertSee('1,5 h')
            ->assertSee('14.01')
            ->assertSee('5,00%')
            ->assertSee('Custo direto padrão (catálogo)')
            ->assertSee('R$ 60,00')
            // Custo e margem em reais
            ->assertSee('data-os-item-custo', false)
            ->assertSee('R$ 140,00')
            ->assertSee('R$ 280,00')
            ->assertSee('R$ 150,00')
            ->assertSee('Custo mudou desde o orçamento')
            ->assertSee('20,0%')
            ->assertSee('65,4%')
            // Atalhos para o cadastro
            ->assertSee(route('estoque.edit', 7), false)
            ->assertSee(route('servicos.edit', 3), false);
    }

    public function test_modal_hides_cost_and_sheets_when_backend_redacted_them(): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/orders/501' => Http::response($this->orderPayload([
                [
                    'id' => 11,
                    'tipo_item' => 'peca',
                    'referencia_id' => 7,
                    'descricao' => 'Tela LCD 15.6',
                    'quantidade' => 2.0,
                    'valor_unitario' => 120.0,
                    'desconto' => 0.0,
                    'acrescimo' => 0.0,
                    'total' => 240.0,
                    'observacoes' => '',
                    'peca' => null,
                    'servico' => null,
                ],
                [
                    'id' => 13,
                    'tipo_item' => 'peca',
                    'referencia_id' => null,
                    'descricao' => 'Cabo flat avulso',
                    'quantidade' => 1.0,
                    'valor_unitario' => 100.0,
                    'desconto' => 0.0,
                    'acrescimo' => 0.0,
                    'total' => 100.0,
                    'observacoes' => '',
                    'peca' => null,
                    'servico' => null,
                ],
                [
                    'id' => 14,
                    'tipo_item' => 'servico',
                    'referencia_id' => 3,
                    'descricao' => 'Formatação',
                    'quantidade' => 1.0,
                    'valor_unitario' => 130.0,
                    'desconto' => 0.0,
                    'acrescimo' => 0.0,
                    'total' => 130.0,
                    'observacoes' => '',
                    'peca' => null,
                    'servico' => null,
                ],
                [
                    'id' => 15,
                    'tipo_item' => 'servico',
                    'referencia_id' => null,
                    'descricao' => 'teste',
                    'quantidade' => 1.0,
                    'valor_unitario' => 100.0,
                    'desconto' => 0.0,
                    'acrescimo' => 0.0,
                    'total' => 100.0,
                    'observacoes' => '',
                    'peca' => null,
                    'servico' => null,
                ],
            ])),
        ]));

        $response = $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
                'os' => ['visualizar'],
            ]))
            ->get('/os/501');

        $response
            ->assertOk()
            ->assertSee('id="osItemDetalheModal-11"', false)
            ->assertSee('id="osItemDetalheModal-13"', false)
            ->assertSee('id="osItemDetalheModal-14"', false)
            ->assertSee('id="osItemDetalheModal-15"', false)
            ->assertDontSee('data-os-item-custo', false)
            ->assertDontSee('Custo unitário')
            ->assertDontSee('Fornecedor')
            ->assertDontSee('Tempo padrão')
            ->assertSee('A ficha do cadastro de estoque não está disponível para o seu perfil.')
            ->assertSee('Peça digitada à mão no orçamento, sem vínculo com o cadastro de estoque.')
            ->assertSee('A ficha do catálogo de serviços não está disponível para o seu perfil.')
            ->assertSee('Serviço digitado à mão no orçamento, sem vínculo com o catálogo de serviços.')
            ->assertDontSee(route('estoque.edit', 7), false)
            ->assertDontSee(route('servicos.edit', 3), false);
    }

    /**
     * @param  array<int, array<string, mixed>>  $itens
     * @return array<string, mixed>
     */
    private function orderPayload(array $itens): array
    {
        return [
            'status' => 'success',
            'data' => [
                'order' => [
                    'id' => 501,
                    'numero_os' => 'OS26070009',
                    'status' => 'em_execucao',
                    'status_nome' => 'Em execução do serviço',
                    'status_cor' => '#64748b',
                    'is_encerrada' => false,
                    'cliente' => ['id' => 201, 'nome_razao' => 'Cliente Alpha'],
                    'equipamento' => ['id' => 301, 'resumo_tecnico' => 'Notebook Acer Nitro 5'],
                    'tecnico' => ['id' => 51, 'nome' => 'Tecnico Banco'],
                    'fotos' => [],
                    'documentos' => [],
                    'status_disponiveis' => [],
                    'proximas_etapas' => [],
                    'orcamento' => [
                        'id' => 27,
                        'numero' => 'ORC-2607-000008',
                        'versao' => 1,
                        'status' => 'aprovado',
                        'status_label' => 'Aprovado',
                        'aprovado' => true,
                        'subtotal' => '370.00',
                        'desconto' => '0.00',
                        'total' => '370.00',
                        'validade_data' => '2026-09-26',
                        'enviado_em' => '',
                        'aprovado_em' => '2026-09-16 11:42',
                        'itens' => $itens,
                    ],
                ],
            ],
            'error' => null,
            'meta' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationsFixture(): array
    {
        return [
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
                'status' => 'success',
                'data' => ['items' => [], 'unread_count' => 0],
                'error' => null,
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 6, 'total' => 0, 'last_page' => 1, 'from' => 0, 'to' => 0]],
            ]),
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
                'user' => [
                    'id' => 99,
                    'nome' => 'Usuário de Teste',
                    'email' => 'usuario@teste.local',
                    'perfil' => 'admin',
                    'group' => ['id' => 1, 'nome' => 'Administrador', 'descricao' => 'Grupo completo', 'sistema' => true],
                    'modules' => array_keys($permissions),
                    'permissions' => $permissions,
                    'foto' => '',
                    'ativo' => true,
                ],
            ],
        ];
    }
}
