<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Garantia da OS: o prazo prometido no orçamento acompanha a OS e, na baixa
 * que entrega um equipamento reparado, ganha data de término contada a partir
 * da entrega.
 */
class OrderWarrantyClosureTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->seedOrderCatalog();
        $this->seedOrderNumberConfiguration();

        $this->grantGroupPermissions(1, [
            'os' => ['visualizar', 'criar', 'editar', 'excluir'],
            'clientes' => ['visualizar'],
            'equipamentos' => ['visualizar'],
        ]);
    }

    public function test_closure_as_delivered_and_paid_records_warranty_end_date(): void
    {
        [$token, $orderId] = $this->seedOrderForClosure();
        $dataEntrega = '2026-08-10';

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => $dataEntrega,
                'garantia_dias' => 180,
                'recebimentos' => [
                    ['valor' => 150.00, 'forma_pagamento' => 'pix'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.order.status', 'entregue_reparado_pago');

        $order = DB::table('os')->where('id', $orderId)->first();

        $this->assertSame(180, (int) $order->garantia_dias);
        $this->assertSame(
            Carbon::parse($dataEntrega)->addDays(180)->toDateString(),
            Carbon::parse((string) $order->garantia_validade)->toDateString()
        );
    }

    public function test_closure_without_repair_does_not_grant_warranty(): void
    {
        [$token, $orderId] = $this->seedOrderForClosure();
        DB::table('os')->where('id', $orderId)->update([
            'garantia_dias' => 0,
            'garantia_validade' => null,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'devolvido_sem_reparo',
                'data_entrega' => '2026-08-10',
                'garantia_dias' => 365,
            ])
            ->assertOk();

        $order = DB::table('os')->where('id', $orderId)->first();

        // Devolução sem reparo não tem serviço a garantir.
        $this->assertNull($order->garantia_validade);
    }

    public function test_closure_falls_back_to_the_warranty_already_on_the_order(): void
    {
        [$token, $orderId] = $this->seedOrderForClosure();
        DB::table('os')->where('id', $orderId)->update(['garantia_dias' => 90]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-08-10',
                'recebimentos' => [
                    ['valor' => 150.00, 'forma_pagamento' => 'pix'],
                ],
            ])
            ->assertOk();

        $order = DB::table('os')->where('id', $orderId)->first();

        $this->assertSame(90, (int) $order->garantia_dias);
        $this->assertSame(
            Carbon::parse('2026-08-10')->addDays(90)->toDateString(),
            Carbon::parse((string) $order->garantia_validade)->toDateString()
        );
    }

    public function test_closure_metadata_suggests_the_warranty_promised_by_the_budget(): void
    {
        [$token, $orderId, $clientId] = $this->seedOrderForClosure();
        // Sem garantia própria, a OS herda a que o orçamento prometeu.
        DB::table('os')->where('id', $orderId)->update(['garantia_dias' => 0]);

        $this->createBudgetRecord([
            'numero' => 'ORC-2608-000099',
            'cliente_id' => $clientId,
            'os_id' => $orderId,
            'status' => 'aprovado',
            'garantia_dias' => 365,
            'aprovado_em' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/orders/{$orderId}/closure")
            ->assertOk();

        $this->assertSame(365, $response->json('data.garantia.dias_sugerido'));
        $this->assertSame(
            [90, 180, 365, 730],
            array_column($response->json('data.garantia.opcoes'), 'value')
        );
    }

    public function test_warranty_already_on_the_order_wins_over_the_budget(): void
    {
        [$token, $orderId, $clientId] = $this->seedOrderForClosure();
        DB::table('os')->where('id', $orderId)->update(['garantia_dias' => 730]);

        $this->createBudgetRecord([
            'numero' => 'ORC-2608-000098',
            'cliente_id' => $clientId,
            'os_id' => $orderId,
            'status' => 'aprovado',
            'garantia_dias' => 90,
            'aprovado_em' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/orders/{$orderId}/closure")
            ->assertOk();

        $this->assertSame(730, $response->json('data.garantia.dias_sugerido'));
    }

    public function test_invalid_warranty_term_is_rejected(): void
    {
        [$token, $orderId] = $this->seedOrderForClosure();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-08-10',
                'garantia_dias' => 45,
                'recebimentos' => [
                    ['valor' => 150.00, 'forma_pagamento' => 'pix'],
                ],
            ])
            ->assertStatus(422);
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    private function seedOrderForClosure(): array
    {
        $manager = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.garantia@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Garantia',
            'cpf_cnpj' => '33.333.333/0001-33',
        ]);
        $equipmentId = $this->createEquipmentRecord($clientId, [
            'resumo_tecnico' => 'Notebook Acer',
        ]);

        $orderId = $this->createOrderRecord([
            'numero_os' => 'OS26080011',
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'status' => 'triagem',
            'estado_fluxo' => 'em_atendimento',
        ]);

        DB::table('os')->where('id', $orderId)->update(['valor_final' => 150.00]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $manager->email,
            'password' => 'Senha@123',
            'device_name' => 'desktop-garantia',
        ]);

        return [(string) $response->json('data.access_token'), $orderId, $clientId];
    }
}
