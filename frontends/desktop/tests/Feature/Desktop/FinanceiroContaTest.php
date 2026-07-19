<?php

namespace Tests\Feature\Desktop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FinanceiroContaTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_available_pending_and_account_controls(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/contas*' => Http::response($this->dashboardPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar'],
                'contas_saldos' => ['visualizar', 'criar', 'editar'],
            ]))
            ->get('/financeiro/contas?mes=2026-07');

        $response->assertOk()
            ->assertSee('Contas e Saldos')
            ->assertSee('R$ 4.900,00')
            ->assertSee('R$ 96,81')
            ->assertSee('Conta Inter')
            ->assertSee('TOM')
            ->assertSee('Cartões aguardando crédito')
            ->assertSee('Lançamentos')
            ->assertSee('Consolidado geral')
            ->assertSee('Nova conta')
            ->assertSee('Transferir')
            ->assertSee(route('financeiro.contas.extrato', ['conta' => 1]), false);
    }

    public function test_create_normalizes_opening_balance_and_sends_patrimonial_payload(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/contas' => Http::response([
                'status' => 'success',
                'data' => ['conta' => ['id' => 5, 'nome' => 'Caixa físico']],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['contas_saldos' => ['visualizar', 'criar']]))
            ->from('/financeiro/contas')
            ->post('/financeiro/contas', [
                'nome' => 'Caixa físico',
                'tipo' => 'caixa',
                'data_inicio_controle' => '2026-07-18',
                'saldo_inicial' => '3.000,00',
                'considera_disponivel' => '1',
                'cor' => '#3868B0',
                'formas_padrao' => ['dinheiro'],
            ]);

        $response->assertRedirect('/financeiro/contas')
            ->assertSessionHas('success');

        Http::assertSent(static fn ($request): bool => $request->url() === 'http://127.0.0.1:8000/api/v1/financeiro/contas'
            && $request->method() === 'POST'
            && $request['saldo_inicial'] === '3000.00'
            && $request['considera_disponivel'] === true
            && $request['formas_padrao'] === ['dinheiro']);
    }

    public function test_transfer_forwards_normalized_value_without_financial_category(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/contas-transferencias' => Http::response([
                'status' => 'success',
                'data' => ['transferencia' => ['id' => 10]],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $this->withSession($this->desktopSession(['contas_saldos' => ['visualizar', 'editar']]))
            ->from('/financeiro/contas')
            ->post('/financeiro/contas-transferencias', [
                'conta_origem_id' => 1,
                'conta_destino_id' => 2,
                'valor' => '1.250,50',
                'data_transferencia' => '2026-07-18',
                'descricao' => 'Separação do lucro líquido',
            ])
            ->assertRedirect('/financeiro/contas')
            ->assertSessionHas('success');

        Http::assertSent(static fn ($request): bool => $request->url() === 'http://127.0.0.1:8000/api/v1/financeiro/contas-transferencias'
            && $request['valor'] === '1250.50'
            && ! isset($request['categoria'])
            && ! isset($request['impacta_dre']));
    }

    public function test_statement_renders_patrimonial_and_financial_sources(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/contas/1/extrato*' => Http::response([
                'status' => 'success',
                'data' => [
                    'conta' => ['id' => 1, 'nome' => 'Conta Inter', 'saldo_atual' => 2000.0],
                    'periodo' => ['saldo_inicial' => 1900.0, 'entradas' => 100.0, 'saidas' => 0.0, 'saldo_final' => 2000.0],
                    'movimentos' => [[
                        'id' => 9,
                        'origem' => 'financeiro',
                        'data' => '2026-07-18',
                        'natureza' => 'entrada',
                        'valor' => 100.0,
                        'descricao' => 'Recebimento OS26070001',
                        'status' => 'realizado',
                        'subtipo' => 'pix',
                    ]],
                    'paginacao' => ['pagina_atual' => 1, 'por_pagina' => 30, 'total' => 1, 'ultima_pagina' => 1],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $this->withSession($this->desktopSession(['contas_saldos' => ['visualizar']]))
            ->get('/financeiro/contas/1/extrato?data_inicio=2026-07-01&data_fim=2026-07-18')
            ->assertOk()
            ->assertSee('Extrato — Conta Inter')
            ->assertSee('Recebimento OS26070001')
            ->assertSee('R$ 2.000,00');
    }

    public function test_consolidated_report_renders_reconciliation_and_account_closing(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/contas/relatorios/consolidado*' => Http::response($this->consolidatedPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $this->withSession($this->desktopSession([
            'financeiro' => ['visualizar'],
            'contas_saldos' => ['visualizar'],
        ]))
            ->get('/financeiro/contas/relatorios/consolidado?mes=2026-07')
            ->assertOk()
            ->assertSee('Consolidado de Contas e Saldos')
            ->assertSee('Este relatório não é uma DRE')
            ->assertSee('Movimentação consolidada')
            ->assertSee('Transferências conciliadas')
            ->assertSee('Conta Inter')
            ->assertSee('R$ 4.975,00')
            ->assertSee(route('financeiro.contas.index', ['mes' => '2026-07']), false);
    }

    public function test_user_without_accounts_permission_cannot_open_accounts(): void
    {
        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar', 'editar']]))
            ->get('/financeiro/contas');

        $response->assertRedirect();
        $this->withSession($this->desktopSession(['financeiro' => ['visualizar']]))
            ->get('/financeiro/contas/relatorios/consolidado')
            ->assertRedirect();
        Http::assertNothingSent();
    }

    public function test_view_only_permission_hides_account_mutation_controls(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/contas*' => Http::response($this->dashboardPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $this->withSession($this->desktopSession(['contas_saldos' => ['visualizar']]))
            ->get('/financeiro/contas')
            ->assertOk()
            ->assertSee('Extrato')
            ->assertDontSee('Lançamentos')
            ->assertDontSee('Nova conta')
            ->assertDontSee('Transferir')
            ->assertDontSee('Conciliar');
    }

    public function test_create_permission_does_not_expose_edit_or_transfer_controls(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/contas*' => Http::response($this->dashboardPayload(), 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $this->withSession($this->desktopSession(['contas_saldos' => ['visualizar', 'criar']]))
            ->get('/financeiro/contas')
            ->assertOk()
            ->assertSee('Nova conta')
            ->assertDontSee('Transferir')
            ->assertDontSee('Conciliar');
    }

    /** @return array<string, mixed> */
    private function dashboardPayload(): array
    {
        return [
            'status' => 'success',
            'data' => [
                'referencia' => '2026-07',
                'ate' => '2026-07-18',
                'resumo' => [
                    'disponivel_operacional' => 4900.0,
                    'total_em_contas' => 4900.0,
                    'reservado' => 0.0,
                    'cartao_a_receber' => 96.81,
                    'posicao_total' => 4996.81,
                ],
                'contas' => [
                    [
                        'id' => 1,
                        'nome' => 'Conta Inter',
                        'tipo' => 'banco',
                        'instituicao' => 'Banco Inter',
                        'data_inicio_controle' => '2026-07-18',
                        'considera_disponivel' => true,
                        'ativo' => true,
                        'cor' => '#FF7A00',
                        'formas_padrao' => ['pix'],
                        'saldo_disponivel' => 1900.0,
                        'cartao_pendente' => 0.0,
                        'posicao_total' => 1900.0,
                        'mes' => ['saldo_inicial' => 0.0, 'entradas' => 1900.0, 'saidas' => 0.0, 'saldo_final' => 1900.0],
                    ],
                    [
                        'id' => 2,
                        'nome' => 'TOM',
                        'tipo' => 'adquirente',
                        'data_inicio_controle' => '2026-07-18',
                        'considera_disponivel' => true,
                        'ativo' => true,
                        'cor' => '#3868B0',
                        'formas_padrao' => ['cartao_credito'],
                        'saldo_disponivel' => 3000.0,
                        'cartao_pendente' => 96.81,
                        'posicao_total' => 3096.81,
                        'mes' => ['saldo_inicial' => 0.0, 'entradas' => 3000.0, 'saidas' => 0.0, 'saldo_final' => 3000.0],
                    ],
                ],
                'cartoes_pendentes' => [[
                    'id' => 8,
                    'conta_nome' => 'TOM',
                    'descricao' => 'OS26070001',
                    'operadora' => 'TOM',
                    'valor_liquido' => 96.81,
                    'valor_taxa' => 3.19,
                    'data_venda' => '2026-07-18',
                    'data_prevista' => '2026-08-17',
                ]],
                'sem_conta' => ['quantidade' => 0, 'valor' => 0.0],
                'transferencias_recentes' => [],
                'opcoes' => [
                    'tipos' => [
                        ['value' => 'caixa', 'label' => 'Caixa físico'],
                        ['value' => 'banco', 'label' => 'Banco'],
                        ['value' => 'adquirente', 'label' => 'Adquirente / maquininha'],
                    ],
                    'contas_padrao' => ['pix' => 1, 'cartao_credito' => 2],
                ],
            ],
            'error' => null,
            'meta' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function consolidatedPayload(): array
    {
        return [
            'status' => 'success',
            'data' => [
                'referencia' => '2026-07',
                'data_inicio' => '2026-07-01',
                'data_fim' => '2026-07-18',
                'resumo' => [
                    'saldo_anterior' => 0.0,
                    'saldos_iniciais_periodo' => 4900.0,
                    'entradas_operacionais' => 100.0,
                    'saidas_operacionais' => 0.0,
                    'ajustes_entrada' => 0.0,
                    'ajustes_saida' => 25.0,
                    'saldo_antes_transferencias' => 4975.0,
                    'transferencias_entrada' => 900.0,
                    'transferencias_saida' => 900.0,
                    'conferencia_transferencias' => 0.0,
                    'saldo_final' => 4975.0,
                    'disponivel_operacional' => 4075.0,
                    'reservado' => 900.0,
                    'cartao_a_receber' => 0.0,
                    'posicao_total' => 4975.0,
                ],
                'contas' => [[
                    'id' => 1,
                    'nome' => 'Conta Inter',
                    'tipo' => 'banco',
                    'instituicao' => 'Banco Inter',
                    'considera_disponivel' => true,
                    'ativo' => true,
                    'cor' => '#FF7A00',
                    'saldo_anterior' => 0.0,
                    'saldos_iniciais_periodo' => 1900.0,
                    'entradas_operacionais' => 100.0,
                    'saidas_operacionais' => 0.0,
                    'ajustes_entrada' => 0.0,
                    'ajustes_saida' => 0.0,
                    'transferencias_entrada' => 0.0,
                    'transferencias_saida' => 900.0,
                    'saldo_final' => 1100.0,
                    'cartao_a_receber' => 0.0,
                    'posicao_total' => 1100.0,
                ]],
                'sem_conta' => ['quantidade' => 0, 'valor' => 0.0],
            ],
            'error' => null,
            'meta' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function notificationsPayload(): array
    {
        return [
            'status' => 'success',
            'data' => ['items' => [], 'unread_count' => 0],
            'error' => null,
            'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 6, 'total' => 0, 'last_page' => 1]],
        ];
    }

    /** @param array<string, array<int, string>> $permissions @return array<string, mixed> */
    private function desktopSession(array $permissions): array
    {
        return [
            'desktop_auth' => [
                'token' => 'desktop-session-token',
                'synced_at' => time(),
                'user' => [
                    'id' => 1,
                    'nome' => 'Gerente',
                    'email' => 'gerente@example.com',
                    'perfil' => 'gerente',
                    'ativo' => true,
                    'modules' => array_keys($permissions),
                    'permissions' => $permissions,
                ],
            ],
        ];
    }
}
