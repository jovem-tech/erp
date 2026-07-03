<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class DashboardSummaryTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->grantGroupPermissions(2, [
            'dashboard' => ['visualizar'],
            'os' => ['visualizar'],
        ]);
        $this->grantGroupPermissions(3, [
            'dashboard' => ['visualizar'],
            'os' => ['visualizar'],
            'clientes' => ['visualizar'],
            'equipamentos' => ['visualizar'],
            'usuarios' => ['visualizar'],
            'grupos' => ['visualizar'],
            'financeiro' => ['visualizar'],
        ]);
        $this->grantGroupPermissions(4, [
            'dashboard' => ['visualizar'],
            'os' => ['visualizar'],
            'clientes' => ['visualizar'],
            'equipamentos' => ['visualizar'],
        ]);
        $this->seedOrderCatalog();
    }

    public function test_dashboard_summary_returns_expanded_visible_dashboard_payload(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-20 10:00:00'));

        try {
            $clientId = $this->createClientRecord([
                'nome_razao' => 'Ana Comércio LTDA',
                'email' => 'ana@empresa.com',
            ]);

            $equipmentId = $this->createEquipmentRecord($clientId, [
                'desktop_modalidade' => 'Desktop',
                'resumo_tecnico' => 'Notebook Acer Nitro',
                'numero_serie' => 'SN-12345',
                'imei' => 'IMEI-12345',
            ]);

            $this->createOrderRecord([
                'numero_os' => 'OS25120099',
                'cliente_id' => $clientId,
                'equipamento_id' => $equipmentId,
                'status' => 'entregue_reparado',
                'estado_fluxo' => 'encerrado',
                'data_abertura' => Carbon::parse('2025-12-05 09:00:00'),
                'data_entrada' => Carbon::parse('2025-12-05 09:05:00'),
                'data_conclusao' => Carbon::parse('2025-12-06 12:30:00'),
                'data_entrega' => Carbon::parse('2025-12-06 13:00:00'),
                'relato_cliente' => 'Pré-histórico para comparativo.',
                'valor_final' => 350,
            ]);

            $this->createOrderRecord([
                'numero_os' => 'OS26010001',
                'cliente_id' => $clientId,
                'equipamento_id' => $equipmentId,
                'status' => 'triagem',
                'estado_fluxo' => 'em_atendimento',
                'data_abertura' => Carbon::parse('2026-01-10 09:00:00'),
                'data_entrada' => Carbon::parse('2026-01-10 09:10:00'),
                'data_previsao' => Carbon::parse('2026-01-15')->toDateString(),
                'relato_cliente' => 'O notebook nao liga.',
                'valor_final' => 0,
                'valor_total' => 250,
                'orcamento_aprovado' => 0,
            ]);

            $this->createOrderRecord([
                'numero_os' => 'OS26010002',
                'cliente_id' => $clientId,
                'equipamento_id' => $equipmentId,
                'status' => 'entregue_reparado',
                'estado_fluxo' => 'encerrado',
                'data_abertura' => Carbon::parse('2026-01-12 08:30:00'),
                'data_entrada' => Carbon::parse('2026-01-12 08:40:00'),
                'data_conclusao' => Carbon::parse('2026-01-12 15:30:00'),
                'data_entrega' => Carbon::parse('2026-01-12 16:00:00'),
                'data_previsao' => Carbon::parse('2026-01-18')->toDateString(),
                'relato_cliente' => 'Tela sem imagem.',
                'valor_final' => 660,
            ]);

            $user = $this->createUserRecord([
                'nome' => 'Usuario do Dashboard',
                'email' => 'dashboard@example.com',
                'perfil' => 'gerente',
                'grupo_id' => 3,
            ]);

            Sanctum::actingAs($user, ['*']);

            $response = $this->getJson('/api/v1/dashboard/summary?ano=2026&equip_mes=1&equip_ano=2026');

            $response->assertOk()
                ->assertJsonPath('status', 'success')
                ->assertJsonPath('data.access.profile', 'gerente')
                ->assertJsonPath('data.access.has_financial_access', true)
                ->assertJsonPath('data.access.is_technician', false)
                ->assertJsonPath('data.stats.orders', 1)
                ->assertJsonPath('data.stats.total_abertas', 1)
                ->assertJsonPath('data.stats.clients', 1)
                ->assertJsonPath('data.stats.equipments', 1)
                ->assertJsonPath('data.stats.users', 1)
                ->assertJsonPath('data.stats.groups', 4)
                ->assertJsonPath('data.stats.total_os', 3)
                ->assertJsonPath('data.stats.equipamento_entregue_total', 2)
                ->assertJsonPath('data.stats.equipamento_entregue_mes_atual', 1)
                ->assertJsonPath('data.stats.faturamento_mes', 660.0)
                ->assertJsonPath('data.stats.faturamento_mes_anterior', 350.0)
                ->assertJsonPath('data.hero_card.type', 'financial')
                ->assertJsonPath('data.hero_card.label', 'Faturamento mês')
                ->assertJsonPath('data.context_card.type', 'financial')
                ->assertJsonPath('data.filters.year', 2026)
                ->assertJsonPath('data.filters.equipment_month', 1)
                ->assertJsonPath('data.filters.equipment_year', 2026)
                ->assertJsonPath('data.charts.monthly.year', 2026)
                ->assertJsonPath('data.charts.monthly.labels.0', 'Jan')
                ->assertJsonPath('data.charts.monthly.labels.11', 'Dez')
                ->assertJsonPath('data.charts.monthly.points.0.total', 2)
                ->assertJsonPath('data.charts.monthly.points.0.label', 'Jan')
                ->assertJsonPath('data.charts.monthly.points.0.entregues_reparadas', 1)
                ->assertJsonPath('data.charts.monthly.series.0.key', 'abertas')
                ->assertJsonPath('data.charts.monthly.series.1.key', 'entregues_reparadas')
                ->assertJsonPath('data.charts.status.total', 1)
                ->assertJsonPath('data.charts.status.items.0.cor', '#6b7280')
                ->assertJsonPath('data.charts.equipment_types.period.mes', 1)
                ->assertJsonPath('data.charts.equipment_types.period.ano', 2026)
                ->assertJsonPath('data.charts.equipment_types.items.0.tipo_nome', 'Desktop')
                ->assertJsonPath('data.charts.equipment_types.items.0.total', 2)
                ->assertJsonPath('data.alerts.os_paradas', 0)
                ->assertJsonPath('data.alerts.orcamentos_pendentes', 1)
                ->assertJsonPath('data.alerts.prontos_retirada', 0)
                ->assertJsonPath('data.charts.financial.previous_month_revenue', 350.0)
                ->assertJsonPath('data.recent_orders.0.numero_os', 'OS26010002')
                ->assertJsonPath('data.recent_orders.0.dias_em_aberto', 8)
                ->assertJsonPath('data.recent_clients.0.nome_razao', 'Ana Comércio LTDA')
                ->assertJsonPath('data.recent_equipments.0.resumo_tecnico', 'Notebook Acer Nitro')
                ->assertJsonPath('data.low_stock', []);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dashboard_summary_returns_technician_context_when_financial_access_is_missing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-20 10:00:00'));

        try {
            $clientId = $this->createClientRecord([
                'nome_razao' => 'Cliente Técnico LTDA',
            ]);

            $equipmentId = $this->createEquipmentRecord($clientId, [
                'desktop_modalidade' => 'Desktop',
                'resumo_tecnico' => 'Desktop Dell OptiPlex',
            ]);

            $technician = $this->createUserRecord([
                'nome' => 'Tecnico Campo',
                'email' => 'tecnico@example.com',
                'perfil' => 'tecnico',
                'grupo_id' => 2,
            ]);

            $this->createOrderRecord([
                'numero_os' => 'OS26010003',
                'cliente_id' => $clientId,
                'equipamento_id' => $equipmentId,
                'tecnico_id' => $technician->id,
                'status' => 'triagem',
                'estado_fluxo' => 'em_atendimento',
                'data_abertura' => Carbon::parse('2026-01-10 09:00:00'),
                'data_entrada' => Carbon::parse('2026-01-10 09:10:00'),
                'relato_cliente' => 'Sem energia.',
                'valor_final' => 0,
            ]);

            $this->createOrderRecord([
                'numero_os' => 'OS26010004',
                'cliente_id' => $clientId,
                'equipamento_id' => $equipmentId,
                'tecnico_id' => $technician->id,
                'status' => 'entregue_reparado',
                'estado_fluxo' => 'encerrado',
                'data_abertura' => Carbon::parse('2026-01-12 08:30:00'),
                'data_entrada' => Carbon::parse('2026-01-12 08:40:00'),
                'data_conclusao' => Carbon::parse('2026-01-12 15:30:00'),
                'data_entrega' => Carbon::parse('2026-01-12 16:00:00'),
                'relato_cliente' => 'Falha intermitente.',
                'valor_final' => 100,
            ]);

            Sanctum::actingAs($technician, ['*']);

            $response = $this->getJson('/api/v1/dashboard/summary?ano=2026&equip_mes=1&equip_ano=2026');

            $response->assertOk()
                ->assertJsonPath('status', 'success')
                ->assertJsonPath('data.access.profile', 'tecnico')
                ->assertJsonPath('data.access.has_financial_access', false)
                ->assertJsonPath('data.access.is_technician', true)
                ->assertJsonPath('data.hero_card.type', 'technician')
                ->assertJsonPath('data.hero_card.label', 'Comissões acumuladas')
                ->assertJsonPath('data.context_card.type', 'technician')
                ->assertJsonPath('data.stats.comissao_acumulada', 10.0)
                ->assertJsonPath('data.charts.technician.commission_total', 10.0);
        } finally {
            Carbon::setTestNow();
        }
    }
}
