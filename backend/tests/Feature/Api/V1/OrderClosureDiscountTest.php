<?php

namespace Tests\Feature\Api\V1;

use App\Models\Order;
use App\Services\Fiscal\DiscriminacaoNfseBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Desconto concedido ao cliente no fechamento da OS (% ou valor fixo) — ver
 * OrderClosureService::resolveClosureDiscount().
 */
class OrderClosureDiscountTest extends TestCase
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
    }

    public function test_fixed_value_discount_reduces_valor_final_and_receivable_title(): void
    {
        $this->grantGroupPermissions(1, [
            'os' => ['visualizar', 'criar', 'editar', 'administrar'],
            'clientes' => ['visualizar'],
            'equipamentos' => ['visualizar'],
        ]);

        [$token, $orderId] = $this->seedOrderForClosure(150.00);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-10',
                'desconto_tipo' => 'valor',
                'desconto_valor' => 20.00,
                'desconto_motivo' => 'Cliente fidelizado, negociado no balcão.',
                'recebimentos' => [
                    ['valor' => 130.00, 'forma_pagamento' => 'pix'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.order.valor_final', 130.0)
            ->assertJsonPath('data.order.desconto_baixa', 20.0);

        $order = DB::table('os')->where('id', $orderId)->first();
        $this->assertSame(130.0, (float) $order->valor_final);
        $this->assertSame(20.0, (float) $order->desconto_baixa);
        $this->assertSame('valor', $order->desconto_baixa_tipo);
        $this->assertSame('Cliente fidelizado, negociado no balcão.', $order->desconto_baixa_motivo);
        // os.desconto soma o desconto da baixa (Anexo X/DRE já leem esta
        // coluna) — não substitui o que já viesse do orçamento (aqui, zero).
        $this->assertSame(20.0, (float) $order->desconto);

        $titulo = DB::table('financeiro')->where('os_id', $orderId)->where('tipo', 'receber')->first();
        $this->assertSame(130.0, (float) $titulo->valor);
    }

    public function test_percentage_discount_is_calculated_over_the_current_valor_final(): void
    {
        $this->grantGroupPermissions(1, [
            'os' => ['visualizar', 'criar', 'editar', 'administrar'],
            'clientes' => ['visualizar'],
            'equipamentos' => ['visualizar'],
        ]);

        [$token, $orderId] = $this->seedOrderForClosure(200.00);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-10',
                'desconto_tipo' => 'percentual',
                'desconto_percentual' => 10,
                'desconto_motivo' => 'Promoção de aniversário da loja.',
                'recebimentos' => [
                    ['valor' => 180.00, 'forma_pagamento' => 'pix'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.order.valor_final', 180.0)
            ->assertJsonPath('data.order.desconto_baixa', 20.0);

        $order = DB::table('os')->where('id', $orderId)->first();
        $this->assertSame('percentual', $order->desconto_baixa_tipo);
        $this->assertEqualsWithDelta(10.0, (float) $order->desconto_baixa_percentual, 0.0001);
    }

    public function test_discount_requires_a_reason(): void
    {
        $this->grantGroupPermissions(1, [
            'os' => ['visualizar', 'criar', 'editar', 'administrar'],
            'clientes' => ['visualizar'],
            'equipamentos' => ['visualizar'],
        ]);

        [$token, $orderId] = $this->seedOrderForClosure(150.00);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-10',
                'desconto_tipo' => 'valor',
                'desconto_valor' => 20.00,
                'recebimentos' => [
                    ['valor' => 130.00, 'forma_pagamento' => 'pix'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ORDER_CLOSURE_DISCOUNT_REQUIRES_REASON');

        $this->assertSame('triagem', DB::table('os')->where('id', $orderId)->value('status'));
    }

    public function test_discount_exceeding_open_balance_is_rejected_and_nothing_changes(): void
    {
        $this->grantGroupPermissions(1, [
            'os' => ['visualizar', 'criar', 'editar', 'administrar'],
            'clientes' => ['visualizar'],
            'equipamentos' => ['visualizar'],
        ]);

        [$token, $orderId] = $this->seedOrderForClosure(150.00);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-10',
                'desconto_tipo' => 'valor',
                'desconto_valor' => 200.00,
                'desconto_motivo' => 'Desconto exagerado de propósito.',
                'recebimentos' => [
                    ['valor' => 130.00, 'forma_pagamento' => 'pix'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ORDER_CLOSURE_DISCOUNT_EXCEEDS_BALANCE');

        $order = DB::table('os')->where('id', $orderId)->first();
        $this->assertSame(150.0, (float) $order->valor_final);
        $this->assertSame('triagem', $order->status);
        $this->assertSame(0, DB::table('financeiro')->where('os_id', $orderId)->count());
    }

    public function test_discount_is_blocked_when_the_order_was_fully_advanced_before_this_closure(): void
    {
        $this->grantGroupPermissions(1, [
            'os' => ['visualizar', 'criar', 'editar', 'administrar'],
            'clientes' => ['visualizar'],
            'equipamentos' => ['visualizar'],
        ]);

        [$token, $orderId] = $this->seedOrderForClosure(150.00);

        // Adiantamento TOTAL antes desta baixa: saldo em aberto vira zero.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'classificacao_baixa' => 'adiantamento',
                'recebimentos' => [
                    ['valor' => 150.00, 'forma_pagamento' => 'pix'],
                ],
            ])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-10',
                'desconto_tipo' => 'valor',
                'desconto_valor' => 10.00,
                'desconto_motivo' => 'Tarde demais para este desconto.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ORDER_CLOSURE_DISCOUNT_REQUIRES_OPEN_BALANCE');
    }

    public function test_actor_without_administrar_permission_cannot_grant_a_discount(): void
    {
        // Sem 'administrar' — só o suficiente para fechar a OS normalmente.
        $this->grantGroupPermissions(1, [
            'os' => ['visualizar', 'criar', 'editar'],
            'clientes' => ['visualizar'],
            'equipamentos' => ['visualizar'],
        ]);

        [$token, $orderId] = $this->seedOrderForClosure(150.00);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-10',
                'desconto_tipo' => 'valor',
                'desconto_valor' => 20.00,
                'desconto_motivo' => 'Deveria ser barrado antes de chegar aqui.',
                'recebimentos' => [
                    ['valor' => 130.00, 'forma_pagamento' => 'pix'],
                ],
            ])
            ->assertStatus(403);

        $this->assertSame(150.0, (float) DB::table('os')->where('id', $orderId)->value('valor_final'));
    }

    public function test_discount_reduces_an_existing_receivable_title_from_a_prior_partial_advance(): void
    {
        $this->grantGroupPermissions(1, [
            'os' => ['visualizar', 'criar', 'editar', 'administrar'],
            'clientes' => ['visualizar'],
            'equipamentos' => ['visualizar'],
        ]);

        [$token, $orderId] = $this->seedOrderForClosure(100.00);

        // Adiantamento PARCIAL antes desta baixa: R$ 50 de R$ 100 — saldo em
        // aberto fica em R$ 50.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'classificacao_baixa' => 'adiantamento',
                'recebimentos' => [
                    ['valor' => 50.00, 'forma_pagamento' => 'pix'],
                ],
            ])
            ->assertOk();

        // Desconto de 10% sobre os R$ 100 cheios (= R$ 10) concedido nesta
        // baixa: dentro do saldo em aberto (R$ 50), então não mexe nos R$ 50
        // já recebidos — só reduz o que falta pagar.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-10',
                'desconto_tipo' => 'percentual',
                'desconto_percentual' => 10,
                'desconto_motivo' => 'Negociado com o cliente na entrega.',
                'recebimentos' => [
                    ['valor' => 40.00, 'forma_pagamento' => 'pix'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.order.valor_final', 90.0);

        $titulo = DB::table('financeiro')->where('os_id', $orderId)->where('tipo', 'receber')->first();
        $this->assertSame(90.0, (float) $titulo->valor);

        $totalMovimentado = (float) DB::table('financeiro_movimentos')
            ->where('financeiro_id', $titulo->id)
            ->where('tipo_movimento', 'entrada')
            ->sum('valor_movimento');
        // R$ 50 do adiantamento + R$ 40 lançados agora = R$ 90 = quitado.
        $this->assertSame(90.0, $totalMovimentado);
    }

    public function test_no_billed_closure_never_applies_a_discount_even_if_the_payload_sends_one(): void
    {
        $this->grantGroupPermissions(1, [
            'os' => ['visualizar', 'criar', 'editar', 'administrar'],
            'clientes' => ['visualizar'],
            'equipamentos' => ['visualizar'],
        ]);

        [$token, $orderId] = $this->seedOrderForClosure(150.00);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'devolvido_sem_reparo',
                'data_entrega' => '2026-09-10',
                'desconto_tipo' => 'valor',
                'desconto_valor' => 20.00,
                'desconto_motivo' => 'Não deveria contar, encerramento sem cobrança.',
            ])
            ->assertOk();

        $order = DB::table('os')->where('id', $orderId)->first();
        $this->assertSame(0.0, (float) $order->desconto_baixa);
        $this->assertSame(150.0, (float) $order->valor_final);
    }

    public function test_discrimination_text_mentions_the_discount_when_it_affects_the_invoice_value(): void
    {
        $clientId = $this->createClientRecord();
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $this->createEquipmentRecord($clientId),
        ]);
        DB::table('os')->where('id', $orderId)->update([
            'valor_final' => 130.00,
            'desconto_baixa' => 20.00,
        ]);

        $texto = app(DiscriminacaoNfseBuilder::class)->montar(Order::query()->findOrFail($orderId));

        $this->assertStringContainsString('Desconto concedido: R$ 20,00.', $texto);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function seedOrderForClosure(float $valorFinal): array
    {
        $manager = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.desconto.'.uniqid().'@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Desconto',
            'cpf_cnpj' => '44.444.444/0001-44',
        ]);
        $equipmentId = $this->createEquipmentRecord($clientId, [
            'resumo_tecnico' => 'Notebook Dell',
        ]);

        $orderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'status' => 'triagem',
            'estado_fluxo' => 'em_atendimento',
        ]);

        DB::table('os')->where('id', $orderId)->update(['valor_final' => $valorFinal]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $manager->email,
            'password' => 'Senha@123',
            'device_name' => 'desktop-desconto',
        ]);

        return [(string) $response->json('data.access_token'), $orderId];
    }
}
