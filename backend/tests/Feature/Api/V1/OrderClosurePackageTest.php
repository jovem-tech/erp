<?php

namespace Tests\Feature\Api\V1;

use App\Models\OrderEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Ratificação do pacote de manutenção contratado na baixa da OS.
 *
 * Quando o cliente aprova um nível (Básica/Avançada/Completa), o orçamento
 * prometeu garantia, formas de pagamento, parcelamento sem juros e entrega em
 * domicílio daquele nível. A baixa passou a comparar o que está entregando com
 * o que foi vendido: sair do combinado exige assumir e justificar.
 */
class OrderClosurePackageTest extends TestCase
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

    public function test_closure_metadata_exposes_the_contracted_package_terms(): void
    {
        [$token, $orderId, $clientId] = $this->seedOrderForClosure();
        $this->seedContractedPackage($orderId, $clientId);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/orders/{$orderId}/closure")
            ->assertOk();

        $this->assertTrue($response->json('data.pacote.tem_pacote'));
        $this->assertSame(2, $response->json('data.pacote.nivel'));
        $this->assertSame('Manutenção Avançada', $response->json('data.pacote.nivel_label'));
        $this->assertSame(180, $response->json('data.pacote.garantia_dias'));
        $this->assertSame(3, $response->json('data.pacote.parcelas_sem_juros'));
        $this->assertTrue($response->json('data.pacote.entrega_domicilio'));
        $this->assertSame(
            ['dinheiro', 'pix', 'cartao_credito'],
            $response->json('data.pacote.formas_pagamento_codigos')
        );

        // A garantia abre no prazo prometido, mas a lista de prazos continua
        // inteira: dar mais que o prometido é cortesia, não desvio.
        $this->assertSame(180, $response->json('data.garantia.dias_sugerido'));
        $this->assertSame(180, $response->json('data.garantia.dias_prometido'));
        $this->assertSame([90, 180, 365, 730], array_column($response->json('data.garantia.opcoes'), 'value'));
    }

    public function test_closure_metadata_has_no_package_without_approved_budget(): void
    {
        [$token, $orderId] = $this->seedOrderForClosure();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/orders/{$orderId}/closure")
            ->assertOk();

        $this->assertFalse($response->json('data.pacote.tem_pacote'));
        $this->assertNull($response->json('data.pacote.garantia_dias'));
        $this->assertSame([], $response->json('data.pacote.formas_pagamento_codigos'));

        // O catálogo global de formas de pagamento segue inteiro — a baixa
        // sem pacote não pode perder opção nenhuma.
        $this->assertContains(
            'boleto',
            array_column($response->json('data.formas_pagamento'), 'codigo')
        );
    }

    public function test_payment_method_outside_the_package_requires_reason(): void
    {
        [$token, $orderId, $clientId] = $this->seedOrderForClosure();
        $this->seedContractedPackage($orderId, $clientId);

        // Boleto não estava entre as formas oferecidas nesta opção.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-20',
                'garantia_dias' => 180,
                'entrega_domicilio_cumprida' => true,
                'recebimentos' => [
                    ['valor' => 150.00, 'forma_pagamento' => 'boleto'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ORDER_CLOSURE_OUTSIDE_PACKAGE_REQUIRES_REASON')
            ->assertJsonPath('error.details.desvios', ['forma_pagamento']);

        $this->assertSame('aguardando_reparo', (string) DB::table('os')->where('id', $orderId)->value('status'));
    }

    public function test_payment_method_inside_the_package_closes_normally(): void
    {
        [$token, $orderId, $clientId] = $this->seedOrderForClosure();
        $this->seedContractedPackage($orderId, $clientId);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-20',
                'garantia_dias' => 180,
                'entrega_domicilio_cumprida' => true,
                'recebimentos' => [
                    ['valor' => 150.00, 'forma_pagamento' => 'pix'],
                ],
            ])
            ->assertOk();

        $order = DB::table('os')->where('id', $orderId)->first();
        $this->assertNull($order->pacote_desvios);
        $this->assertNull($order->pacote_desvio_motivo);
    }

    public function test_installments_above_the_interest_free_limit_require_reason(): void
    {
        [$token, $orderId, $clientId] = $this->seedOrderForClosure();
        $this->seedContractedPackage($orderId, $clientId);
        $operadoraId = $this->seedCardRates();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-20',
                'garantia_dias' => 180,
                'entrega_domicilio_cumprida' => true,
                'recebimentos' => [
                    [
                        'valor' => 150.00,
                        'forma_pagamento' => 'cartao_credito',
                        'operadora_id' => $operadoraId,
                        'modalidade' => 'credito',
                        'parcelas' => 6,
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.details.desvios', ['parcelas']);
    }

    public function test_installments_within_the_promised_limit_close_normally(): void
    {
        [$token, $orderId, $clientId] = $this->seedOrderForClosure();
        $this->seedContractedPackage($orderId, $clientId);
        $operadoraId = $this->seedCardRates();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-20',
                'garantia_dias' => 180,
                'entrega_domicilio_cumprida' => true,
                'recebimentos' => [
                    [
                        'valor' => 150.00,
                        'forma_pagamento' => 'cartao_credito',
                        'operadora_id' => $operadoraId,
                        'modalidade' => 'credito',
                        'parcelas' => 3,
                    ],
                ],
            ])
            ->assertOk();

        $this->assertNull(DB::table('os')->where('id', $orderId)->value('pacote_desvios'));
    }

    public function test_debit_card_is_never_an_installment_deviation(): void
    {
        [$token, $orderId, $clientId] = $this->seedOrderForClosure();
        // Débito entra no pacote desta vez, mas continua não parcelando.
        $this->seedContractedPackage($orderId, $clientId, [
            'formas' => ['dinheiro', 'pix', 'cartao_credito', 'cartao_debito'],
            'parcelas_sem_juros' => 2,
        ]);
        $operadoraId = $this->seedCardRates();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-20',
                'garantia_dias' => 180,
                'entrega_domicilio_cumprida' => true,
                'recebimentos' => [
                    [
                        'valor' => 150.00,
                        'forma_pagamento' => 'cartao_debito',
                        'operadora_id' => $operadoraId,
                        'modalidade' => 'debito',
                        'parcelas' => 1,
                    ],
                ],
            ])
            ->assertOk();

        $this->assertNull(DB::table('os')->where('id', $orderId)->value('pacote_desvios'));
    }

    public function test_home_delivery_promise_not_confirmed_requires_reason(): void
    {
        [$token, $orderId, $clientId] = $this->seedOrderForClosure();
        $this->seedContractedPackage($orderId, $clientId);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-20',
                'garantia_dias' => 180,
                'entrega_domicilio_cumprida' => false,
                'recebimentos' => [
                    ['valor' => 150.00, 'forma_pagamento' => 'pix'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.details.desvios', ['entrega_domicilio']);
    }

    public function test_ratified_closure_persists_reason_and_records_the_timeline_event(): void
    {
        [$token, $orderId, $clientId] = $this->seedOrderForClosure();
        $this->seedContractedPackage($orderId, $clientId);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-20',
                'garantia_dias' => 90,
                'entrega_domicilio_cumprida' => false,
                'fora_pacote' => true,
                'fora_pacote_motivo' => 'Cliente preferiu retirar na loja e aceitou garantia menor por escrito.',
                'recebimentos' => [
                    ['valor' => 150.00, 'forma_pagamento' => 'boleto'],
                ],
            ])
            ->assertOk();

        $order = DB::table('os')->where('id', $orderId)->first();

        $this->assertSame('garantia,forma_pagamento,entrega_domicilio', (string) $order->pacote_desvios);
        $this->assertStringContainsString('retirar na loja', (string) $order->pacote_desvio_motivo);
        $this->assertNotNull($order->pacote_desvio_por);
        $this->assertNotNull($order->pacote_desvio_em);

        $evento = DB::table('os_eventos')
            ->where('os_id', $orderId)
            ->where('tipo', OrderEvent::TIPO_BAIXA_FORA_PACOTE)
            ->first();

        $this->assertNotNull($evento, 'A timeline da OS precisa registrar a baixa fora do pacote.');
        $this->assertStringContainsString('garantia menor que a prometida', (string) $evento->descricao);
        $this->assertStringContainsString('Manutenção Avançada', (string) $evento->descricao);
    }

    public function test_warranty_longer_than_promised_is_accepted_without_reason(): void
    {
        [$token, $orderId, $clientId] = $this->seedOrderForClosure();
        $this->seedContractedPackage($orderId, $clientId);

        // Prometeu 180 dias, entregou 1 ano: cortesia, passa livre.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-20',
                'garantia_dias' => 365,
                'entrega_domicilio_cumprida' => true,
                'recebimentos' => [
                    ['valor' => 150.00, 'forma_pagamento' => 'pix'],
                ],
            ])
            ->assertOk();

        $order = DB::table('os')->where('id', $orderId)->first();
        $this->assertNull($order->pacote_desvios);
        $this->assertSame(365, (int) $order->garantia_dias);
    }

    public function test_warranty_shorter_than_promised_requires_reason(): void
    {
        [$token, $orderId, $clientId] = $this->seedOrderForClosure();
        $this->seedContractedPackage($orderId, $clientId);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-20',
                'garantia_dias' => 90,
                'entrega_domicilio_cumprida' => true,
                'recebimentos' => [
                    ['valor' => 150.00, 'forma_pagamento' => 'pix'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.details.desvios', ['garantia']);
    }

    public function test_explicit_no_warranty_zeroes_the_order_when_justified(): void
    {
        [$token, $orderId, $clientId] = $this->seedOrderForClosure();
        DB::table('os')->where('id', $orderId)->update(['garantia_dias' => 90]);
        $this->seedContractedPackage($orderId, $clientId);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-20',
                'garantia_dias' => '',
                'entrega_domicilio_cumprida' => true,
                'fora_pacote' => true,
                'fora_pacote_motivo' => 'Equipamento fora de linha, cliente ciente de que sai sem garantia.',
                'recebimentos' => [
                    ['valor' => 150.00, 'forma_pagamento' => 'pix'],
                ],
            ])
            ->assertOk();

        $order = DB::table('os')->where('id', $orderId)->first();

        // "Sem garantia" escolhido e justificado zera de fato — antes a OS
        // seguia exibindo o prazo herdado, contradizendo a própria baixa.
        $this->assertSame(0, (int) $order->garantia_dias);
        $this->assertNull($order->garantia_validade);
        $this->assertSame('garantia', (string) $order->pacote_desvios);
    }

    public function test_warranty_is_adopted_from_the_package_when_payload_omits_it(): void
    {
        [$token, $orderId, $clientId] = $this->seedOrderForClosure();
        DB::table('os')->where('id', $orderId)->update(['garantia_dias' => 0]);
        $this->seedContractedPackage($orderId, $clientId);

        // Caminho da API pura / baixa em lote: não manda garantia nenhuma.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado_sem_custo',
                'data_entrega' => '2026-09-20',
            ])
            ->assertOk();

        $order = DB::table('os')->where('id', $orderId)->first();

        $this->assertSame(180, (int) $order->garantia_dias);
        $this->assertNull($order->pacote_desvios, 'Omitir a garantia adota a do pacote, não vira desvio.');
    }

    public function test_batch_closure_of_order_with_package_still_succeeds(): void
    {
        [$token, $orderId, $clientId] = $this->seedOrderForClosure();
        DB::table('os')->where('id', $orderId)->update(['garantia_dias' => 0]);
        $this->seedContractedPackage($orderId, $clientId);

        // A baixa em lote só aceita OS que já saíram do fluxo
        // (OrderStatus::FLOW_EXIT_MACRO_GROUPS), e o catálogo de teste não
        // semeia nenhum status desses — sem isto a rota devolveria 200 sem
        // encerrar nada e o teste passaria sem testar.
        DB::table('os_status')->insert([
            'codigo' => 'abandonado',
            'nome' => 'Abandonado pelo cliente',
            'grupo_macro' => 'finalizado_sem_reparo',
            'ordem_fluxo' => 90,
            'status_final' => false,
            'ativo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('os')->where('id', $orderId)->update(['status' => 'abandonado']);

        // O lote manda só encerrar_como/data_entrega/observacao e nunca
        // pergunta garantia ou entrega. Não pode virar 422 por isso.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orders/close-batch', [
                'order_ids' => [$orderId],
                'encerrar_como' => 'entregue_reparado_garantia',
                'data_entrega' => '2026-09-20',
            ])
            ->assertOk()
            ->assertJsonPath('data.failed', [])
            ->assertJsonPath('data.succeeded_count', 1);

        $order = DB::table('os')->where('id', $orderId)->first();

        $this->assertSame('entregue_reparado_garantia', (string) $order->status);
        $this->assertNull($order->pacote_desvios);
        // Reparo em garantia mantém o prazo anterior: não adota o do pacote
        // nem zera o que a OS tinha.
        $this->assertSame(180, (int) $order->garantia_dias);
    }

    public function test_order_without_package_ignores_out_of_package_fields(): void
    {
        [$token, $orderId] = $this->seedOrderForClosure();

        // Sem pacote contratado, boleto e 12x não são desvio de nada.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-20',
                'garantia_dias' => 90,
                'recebimentos' => [
                    ['valor' => 150.00, 'forma_pagamento' => 'boleto'],
                ],
            ])
            ->assertOk();

        $this->assertNull(DB::table('os')->where('id', $orderId)->value('pacote_desvios'));
    }

    public function test_advance_with_payment_method_outside_the_package_requires_reason(): void
    {
        [$token, $orderId, $clientId] = $this->seedOrderForClosure();
        $this->seedContractedPackage($orderId, $clientId);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'classificacao_baixa' => 'sinal',
                'recebimentos' => [
                    ['valor' => 50.00, 'forma_pagamento' => 'boleto'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.details.desvios', ['forma_pagamento']);
    }

    /**
     * Pacote contratado: orçamento aprovado no nível 2 com garantia de 180
     * dias, 3x sem juros, entrega em domicílio e três formas aceitas.
     *
     * Grava as colunas base de `orcamentos` porque é exatamente o que
     * BudgetCommercialTermsService::collapseApprovedLevel() faz na aprovação —
     * o nível escolhido vira o padrão do orçamento.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function seedContractedPackage(int $orderId, int $clientId, array $overrides = []): int
    {
        $formas = $overrides['formas'] ?? ['dinheiro', 'pix', 'cartao_credito'];

        $budgetId = $this->createBudgetRecord([
            'numero' => 'ORC-2609-000501',
            'cliente_id' => $clientId,
            'os_id' => $orderId,
            'status' => 'aprovado',
            'aprovado_em' => now(),
            'nivel_aprovado' => $overrides['nivel_aprovado'] ?? 2,
            'garantia_dias' => $overrides['garantia_dias'] ?? 180,
            'parcelas_sem_juros' => $overrides['parcelas_sem_juros'] ?? 3,
            'entrega_domicilio' => $overrides['entrega_domicilio'] ?? true,
        ]);

        $catalogo = DB::table('financeiro_formas_pagamento')->get()->keyBy('codigo');

        foreach (array_values($formas) as $ordem => $codigo) {
            DB::table('orcamento_formas_pagamento')->insert([
                'orcamento_id' => $budgetId,
                'forma_pagamento_id' => (int) ($catalogo[$codigo]->id ?? 0) ?: null,
                'forma_codigo' => $codigo,
                'forma_nome' => (string) ($catalogo[$codigo]->nome ?? $codigo),
                'is_cartao' => (bool) ($catalogo[$codigo]->is_cartao ?? false),
                'ordem' => $ordem,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $budgetId;
    }

    /**
     * Operadora + faixas de taxa ativas, senão a simulação de cartão recusa o
     * recebimento antes mesmo de a ratificação do pacote ser avaliada.
     */
    private function seedCardRates(): int
    {
        $operadoraId = (int) DB::table('financeiro_cartao_operadoras')->insertGetId([
            'nome' => 'Operadora Teste',
            'ordem_exibicao' => 1,
            'prazo_padrao_dias' => 30,
            'ativo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('financeiro_cartao_taxas')->insert([
            [
                'operadora_id' => $operadoraId,
                'bandeira_id' => null,
                'modalidade' => 'credito',
                'parcelas_inicial' => 1,
                'parcelas_final' => 12,
                'taxa_percentual' => 3.5,
                'taxa_fixa' => 0,
                'prazo_recebimento_dias' => 30,
                'ativo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'operadora_id' => $operadoraId,
                'bandeira_id' => null,
                'modalidade' => 'debito',
                'parcelas_inicial' => 1,
                'parcelas_final' => 1,
                'taxa_percentual' => 1.5,
                'taxa_fixa' => 0,
                'prazo_recebimento_dias' => 1,
                'ativo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        return $operadoraId;
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    private function seedOrderForClosure(): array
    {
        $manager = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.pacote@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Pacote',
            'cpf_cnpj' => '44.444.444/0001-44',
        ]);
        $equipmentId = $this->createEquipmentRecord($clientId, [
            'resumo_tecnico' => 'Notebook Dell',
        ]);

        $orderId = $this->createOrderRecord([
            'numero_os' => 'OS26090501',
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'status' => 'aguardando_reparo',
            'estado_fluxo' => 'em_atendimento',
        ]);

        DB::table('os')->where('id', $orderId)->update(['valor_final' => 150.00]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $manager->email,
            'password' => 'Senha@123',
            'device_name' => 'desktop-pacote',
        ]);

        return [(string) $response->json('data.access_token'), $orderId, $clientId];
    }
}
