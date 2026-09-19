<?php

namespace Tests\Feature\Api\V1;

use App\Models\Budget;
use App\Services\Budgets\BudgetCommercialTermsService;
use App\Services\Budgets\BudgetPdfService;
use App\Services\Company\CompanyProfileService;
use App\Services\Fiscal\AnexoXService;
use App\Services\Integrations\IntegrationSettingsService;
use App\Services\Pdf\Contexts\BudgetPdfContextFactory;
use App\Services\Pdf\PdfDefaultTemplates;
use App\Support\BudgetTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Orçamento em níveis de manutenção (Básica / Avançada / Completa).
 *
 * A lista de itens é única e cada item declara explicitamente em quais
 * níveis entra (`orcamento_itens.niveis`, sem cascata — um item não herda
 * automaticamente os níveis acima do seu); o cliente escolhe a opção na
 * página pública e, na aprovação, o orçamento vira o escopo daquela opção
 * (itens que não pertencem a ela saem, totais recalculados, snapshot na
 * auditoria). Orçamento comum (tudo nível 1) não muda nada.
 */
class BudgetMaintenanceLevelsTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->grantGroupPermissions(1, [
            'orcamentos' => ['visualizar', 'criar', 'editar', 'excluir'],
            'clientes' => ['visualizar'],
            'equipamentos' => ['visualizar'],
            'os' => ['visualizar'],
            'servicos' => ['visualizar'],
            'estoque' => ['visualizar'],
        ]);
    }

    public function test_items_carry_their_level_and_detail_projects_totals_per_level(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Níveis']);
        $token = $this->loginAndGetToken($admin->email);

        $create = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos', [
                'cliente_id' => $clientId,
                'telefone_contato' => '(11) 98888-7777',
                'envolve_equipamento' => false,
                'desconto_tipo' => 'percentual',
                'desconto_percentual' => 10,
                'nivel_recomendado' => 2,
                'itens' => [
                    ['descricao' => 'Fusível', 'quantidade' => 1, 'valor_unitario' => 100, 'niveis' => [1, 2, 3]],
                    ['descricao' => 'Bateria', 'quantidade' => 1, 'valor_unitario' => 200, 'niveis' => [2, 3]],
                    ['descricao' => 'Película e limpeza', 'quantidade' => 1, 'valor_unitario' => 300, 'niveis' => [3]],
                ],
            ]);

        $create->assertCreated();
        $budgetId = (int) $create->json('data.budget.id');

        $this->assertDatabaseHas('orcamento_itens', ['orcamento_id' => $budgetId, 'descricao' => 'Bateria', 'niveis' => json_encode([2, 3])]);
        $this->assertDatabaseHas('orcamentos', ['id' => $budgetId, 'nivel_recomendado' => 2, 'nivel_aprovado' => null]);

        $detail = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/orcamentos/'.$budgetId)
            ->assertOk();

        $detail->assertJsonPath('data.budget.has_tiers', true)
            ->assertJsonPath('data.budget.nivel_maximo', 3)
            ->assertJsonPath('data.budget.nivel_recomendado', 2)
            ->assertJsonPath('data.budget.niveis.0.label', 'Manutenção Básica')
            ->assertJsonPath('data.budget.niveis.0.total', 90.0)
            ->assertJsonPath('data.budget.niveis.1.total', 270.0)
            ->assertJsonPath('data.budget.niveis.1.recomendado', true)
            ->assertJsonPath('data.budget.niveis.2.total', 540.0)
            ->assertJsonPath('data.budget.niveis.2.itens_count', 3)
            // Escopo gravado antes da decisão é o máximo: igual ao total da Completa.
            ->assertJsonPath('data.budget.total', 540.0)
            ->assertJsonPath('data.budget.itens.1.niveis', [2, 3]);
    }

    public function test_recommended_level_is_dropped_when_budget_has_a_single_level(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Simples']);
        $token = $this->loginAndGetToken($admin->email);

        $create = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos', [
                'cliente_id' => $clientId,
                'telefone_contato' => '(11) 98888-7777',
                'envolve_equipamento' => false,
                'nivel_recomendado' => 2,
                'itens' => [
                    ['descricao' => 'Formatação', 'quantidade' => 1, 'valor_unitario' => 120],
                ],
            ]);

        $create->assertCreated();
        $budgetId = (int) $create->json('data.budget.id');

        $this->assertDatabaseHas('orcamentos', ['id' => $budgetId, 'nivel_recomendado' => null]);
        $this->assertDatabaseHas('orcamento_itens', ['orcamento_id' => $budgetId, 'niveis' => json_encode([1])]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/orcamentos/'.$budgetId)
            ->assertOk()
            ->assertJsonPath('data.budget.has_tiers', false)
            ->assertJsonPath('data.budget.niveis', []);
    }

    public function test_tiered_budget_is_sent_as_text_with_link_and_without_pdf(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Envio', 'telefone1' => '(11) 99999-9999']);
        $equipmentId = $this->createEquipmentRecord($clientId, ['resumo_tecnico' => 'Smartphone Galaxy']);
        $budgetId = $this->tieredBudget($clientId, ['telefone_contato' => '(11) 99999-9999', 'equipamento_id' => $equipmentId]);

        $this->mock(BudgetPdfService::class, function ($mock): void {
            $mock->shouldReceive('generate')->never();
        });
        $this->mock(IntegrationSettingsService::class, function ($mock): void {
            $mock->shouldReceive('sendDirectMediaBytes')->never();
            $mock->shouldReceive('sendDirectMessage')
                ->once()
                ->withArgs(static fn (string $phone, string $message): bool => str_contains($message, '3 opções de manutenção') && str_contains($message, '/orcamento/'))
                ->andReturn(['ok' => true, 'provider' => 'evolution', 'message' => 'ok']);
        });
        $this->mock(CompanyProfileService::class, function ($mock): void {
            $mock->shouldReceive('payload')->andReturn(['settings' => ['empresa_nome_fantasia' => 'Jovem Tech']]);
        });

        $token = $this->loginAndGetToken($admin->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos/'.$budgetId.'/send-approval')
            ->assertOk()
            ->assertJsonPath('data.dispatch.status', 'enviado');

        $this->assertDatabaseHas('orcamentos', ['id' => $budgetId, 'status' => 'aguardando_resposta']);
        $this->assertDatabaseHas('orcamento_envios', [
            'orcamento_id' => $budgetId,
            'canal' => 'whatsapp',
            'status' => 'enviado',
            'documento_path' => '',
        ]);
    }

    public function test_public_page_shows_the_options_first_and_then_the_chosen_option(): void
    {
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Landing']);
        $budgetId = $this->tieredBudget($clientId, [
            'status' => 'aguardando_resposta',
            'token_publico' => 'token-niveis',
            'token_expira_em' => now()->addDays(5),
            'nivel_recomendado' => 2,
        ]);

        $landing = $this->get('/orcamento/token-niveis')->assertOk();
        $landing->assertSee('Escolha a opção de manutenção')
            ->assertSee('Manutenção Básica')
            ->assertSee('Manutenção Avançada')
            ->assertSee('Manutenção Completa')
            ->assertSee('Recomendado')
            ->assertSee('opcao=2')
            ->assertDontSee('Aprovar proposta')
            // Hero humano: saudação pelo primeiro nome, sem número no título
            // nem status de sistema; o cadastro completo fica a um toque.
            ->assertSee('Olá, Cliente.')
            ->assertSee('Orçamento ORC-NIVEIS')
            ->assertDontSee('<h1 class="title">', false)
            ->assertDontSee('class="status-badge', false)
            ->assertSee('Ver detalhes do atendimento')
            ->assertSee('Cliente Landing')
            // Lista sempre completa e literal (nada de "inclui tudo da anterior,
            // mais" nem "ver mais" escondendo o resto) — cada cartão mostra tudo
            // que realmente compõe aquela opção.
            ->assertSee('Itens desta opção')
            ->assertDontSee('Inclui tudo da')
            ->assertSee('Escolher esta opção')
            ->assertSee('visually-hidden', false)
            ->assertSee('option-card is-recommended', false)
            // Hierarquia: só o destaque tem botão cheio; os outros, contornado.
            ->assertSee('coverage-segment', false);
        $landingHtml = (string) $landing->getContent();
        $this->assertSame(1, substr_count($landingHtml, 'btn btn-primary option-cta'));
        $this->assertSame(2, substr_count($landingHtml, 'btn btn-outline-primary option-cta'));
        $this->assertSame(6, substr_count($landingHtml, 'coverage-segment is-filled'));
        // Fusível está nos 3 cartões, Bateria em 2 (Avançada/Completa), Película só na Completa.
        $this->assertSame(3, substr_count($landingHtml, '<li>Fusível</li>'));
        $this->assertSame(2, substr_count($landingHtml, '<li>Bateria</li>'));
        $this->assertSame(1, substr_count($landingHtml, '<li>Película e limpeza</li>'));

        $option = $this->get('/orcamento/token-niveis?opcao=2')->assertOk();
        $option->assertSee('Opção escolhida')
            ->assertSee('Manutenção Avançada')
            ->assertSee('Trocar de opção')
            ->assertSee('Aprovar proposta')
            // Passo 2 volta ao cabeçalho de sempre.
            ->assertSee('<h1 class="title">Orçamento ORC-NIVEIS</h1>', false)
            ->assertDontSee('Olá,')
            ->assertSee('name="nivel" value="2"', false)
            ->assertSee('Bateria')
            ->assertDontSee('Película e limpeza')
            ->assertSee('R$ 300,00')
            ->assertSee('pdf?opcao=2')
            // Passo 2 volta ao cabeçalho de sempre (4 caixas abertas).
            ->assertDontSee('Ver detalhes do atendimento')
            ->assertSee('OS vinculada');

        // Opção inexistente cai de volta na escolha.
        $this->get('/orcamento/token-niveis?opcao=9')
            ->assertOk()
            ->assertSee('Escolha a opção de manutenção');

        $levels = BudgetTotals::perLevel(Budget::query()->findOrFail($budgetId));
        $this->assertSame([100.0, 300.0, 600.0], array_map(static fn (array $level): float => $level['total'], $levels));
        $this->assertSame(['Fusível'], $levels[0]['itens']);
        $this->assertSame(['Fusível', 'Bateria'], $levels[1]['itens']);
        $this->assertSame(['Fusível', 'Bateria', 'Película e limpeza'], $levels[2]['itens']);
    }

    public function test_public_landing_suggests_the_middle_level_when_none_is_manually_recommended(): void
    {
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Sem Recomendação']);
        $this->tieredBudget($clientId, [
            'status' => 'aguardando_resposta',
            'token_publico' => 'token-sem-recomendacao',
            'token_expira_em' => now()->addDays(5),
            // nivel_recomendado não informado de propósito.
        ]);

        $this->get('/orcamento/token-sem-recomendacao')
            ->assertOk()
            ->assertSee('Mais escolhida')
            ->assertSee('option-card is-suggested', false)
            ->assertDontSee('Recomendado')
            ->assertDontSee('option-card is-recommended', false);
    }

    public function test_public_landing_hides_a_level_that_adds_nothing_over_the_previous_one(): void
    {
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Nível Vazio']);
        // A Avançada não tem nenhum item próprio (só o que já veio da
        // Básica) — ficaria com o mesmo preço e a mesma lista da Básica.
        $budgetId = $this->createBudgetRecord([
            'numero' => 'ORC-NIVEL-VAZIO',
            'cliente_id' => $clientId,
            'telefone_contato' => '(11) 99999-9999',
            'status' => 'aguardando_resposta',
            'token_publico' => 'token-nivel-vazio',
            'token_expira_em' => now()->addDays(5),
            'subtotal' => 400.00,
            'total' => 400.00,
        ]);
        $this->createBudgetItemRecord($budgetId, ['descricao' => 'Fusível', 'valor_unitario' => 100, 'total' => 100, 'ordem' => 1, 'niveis' => json_encode([1, 2, 3])]);
        $this->createBudgetItemRecord($budgetId, ['descricao' => 'Vidro traseiro', 'valor_unitario' => 300, 'total' => 300, 'ordem' => 2, 'niveis' => json_encode([3])]);

        $response = $this->get('/orcamento/token-nivel-vazio')->assertOk();
        $response
            ->assertSee('Manutenção Básica')
            ->assertSee('Manutenção Completa')
            // A Avançada não aparece como cartão — teria a mesma lista e o
            // mesmo total da Básica (Vidro traseiro só entra na Completa).
            ->assertDontSee('Manutenção Avançada')
            // A lista de cada opção agora é sempre completa e literal.
            ->assertSee('Itens desta opção')
            ->assertSee('Fusível')
            ->assertSee('Vidro traseiro');
    }

    public function test_public_approval_of_tiered_budget_requires_a_level(): void
    {
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Sem Nível']);
        $budgetId = $this->tieredBudget($clientId, [
            'status' => 'aguardando_resposta',
            'token_publico' => 'token-sem-nivel',
            'token_expira_em' => now()->addDays(5),
        ]);

        $this->post('/orcamento/token-sem-nivel/aprovar', ['resposta_cliente' => 'Aprovado pelo cliente.'])
            ->assertRedirect(route('budgets.public.show', ['token' => 'token-sem-nivel']))
            ->assertSessionHas('warning', 'Escolha a opção de manutenção para aprovar esta proposta.');

        $this->assertDatabaseHas('orcamentos', ['id' => $budgetId, 'status' => 'aguardando_resposta', 'nivel_aprovado' => null]);
        $this->assertDatabaseCount('orcamento_aprovacoes', 0);
        $this->assertSame(3, DB::table('orcamento_itens')->where('orcamento_id', $budgetId)->count());
    }

    public function test_public_approval_with_level_prunes_items_recalculates_and_keeps_snapshot(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Aprova N2']);
        $equipmentId = $this->createEquipmentRecord($clientId, ['resumo_tecnico' => 'Notebook']);
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'numero_os' => 'OS26090001',
            'orcamento_aprovado' => 0,
        ]);
        $pecaId = $this->createPecaRecord(['quantidade_atual' => 5]);
        $budgetId = $this->tieredBudget($clientId, [
            'equipamento_id' => $equipmentId,
            'os_id' => $orderId,
            'tipo_orcamento' => 'assistencia',
            'status' => 'aguardando_resposta',
            'token_publico' => 'token-aprova-n2',
            'token_expira_em' => now()->addDays(5),
            'criado_por' => $admin->id,
        ]);
        // Peça exclusiva da Completa: reservada enquanto o cliente decide,
        // liberada quando ele escolhe a Avançada.
        $this->createBudgetItemRecord($budgetId, [
            'tipo_item' => 'peca',
            'referencia_id' => $pecaId,
            'descricao' => 'Tela original',
            'quantidade' => 1,
            'valor_unitario' => 400,
            'total' => 400,
            'ordem' => 4,
            'niveis' => json_encode([3]),
        ]);
        app(\App\Services\Estoque\EstoqueReservaService::class)->sincronizar(Budget::query()->findOrFail($budgetId));
        $this->assertSame(1.0, (float) DB::table('pecas')->where('id', $pecaId)->value('quantidade_reservada'));

        $this->mock(BudgetPdfService::class, function ($mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->withArgs(static fn (Budget $budget): bool => (int) $budget->nivel_aprovado === 2 && $budget->items->count() === 2)
                ->andReturn([
                    'ok' => true,
                    'bytes' => '%PDF-1.4 definitivo',
                    'absolute_path' => '',
                    'relative_path' => 'private/orcamentos/definitivo.pdf',
                    'file_name' => 'Orcamento-definitivo.pdf',
                ]);
        });

        $this->post('/orcamento/token-aprova-n2/aprovar', ['resposta_cliente' => 'Aprovado pelo cliente.', 'nivel' => 2])
            ->assertRedirect(route('budgets.public.show', ['token' => 'token-aprova-n2']))
            ->assertSessionHas('success', 'Orçamento aprovado com sucesso.');

        $this->assertDatabaseHas('orcamentos', [
            'id' => $budgetId,
            'status' => 'aprovado',
            'nivel_aprovado' => 2,
            'subtotal' => 300.00,
            'total' => 300.00,
        ]);
        $this->assertSame(
            ['Fusível', 'Bateria'],
            DB::table('orcamento_itens')->where('orcamento_id', $budgetId)->orderBy('ordem')->pluck('descricao')->all()
        );

        $approval = DB::table('orcamento_aprovacoes')->where('orcamento_id', $budgetId)->first();
        $this->assertNotNull($approval);
        $this->assertSame(2, (int) $approval->nivel);
        $this->assertStringContainsString('Opção escolhida: Manutenção Avançada', (string) $approval->resposta_cliente);
        $snapshot = json_decode((string) $approval->niveis_snapshot, true);
        $this->assertCount(3, $snapshot);
        $this->assertSame(1000.0, (float) $snapshot[2]['total']);

        // OS lê os itens ao vivo: financeiro reflete só o escopo aprovado.
        $this->assertDatabaseHas('os', [
            'id' => $orderId,
            'orcamento_aprovado' => 1,
            'valor_final' => 300.00,
            'orcamento_pdf' => 'private/orcamentos/definitivo.pdf',
        ]);
        // A reserva da peça que ficou fora da opção escolhida foi liberada.
        $this->assertSame(0.0, (float) DB::table('pecas')->where('id', $pecaId)->value('quantidade_reservada'));

        // Notificação e evento anunciam o valor DEPOIS da poda.
        $this->assertDatabaseHas('os_eventos', [
            'os_id' => $orderId,
            'descricao' => 'Cliente aprovou o orçamento ORC-NIVEIS pelo link público (Manutenção Avançada).',
        ]);
        $this->assertDatabaseHas('mobile_notifications', [
            'corpo' => 'O cliente aprovou o orçamento ORC-NIVEIS (R$ 300,00) — Manutenção Avançada.',
        ]);

        // Depois da decisão a página mostra o escopo contratado e a opção aprovada.
        $this->get('/orcamento/token-aprova-n2')
            ->assertOk()
            ->assertSee('Opção aprovada')
            ->assertSee('Manutenção Avançada')
            ->assertDontSee('Escolha a opção de manutenção')
            ->assertDontSee('Tela original');
    }

    /**
     * Regressão: itens que são ALTERNATIVA entre si (ex.: RAM 2GB/4GB/8GB, uma
     * por opção) não podem se somar. Cada item marca só o(s) nível(is) em que
     * realmente deve aparecer — sem cascata, a Completa nunca vê as memórias
     * de 2GB/4GB que só existiam pra oferecer a Básica/Avançada.
     */
    public function test_alternative_items_across_levels_do_not_stack(): void
    {
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Alternativas']);
        $pecaId = $this->createPecaRecord(['quantidade_atual' => 5]);
        $budgetId = $this->createBudgetRecord([
            'numero' => 'ORC-ALTERNATIVAS',
            'cliente_id' => $clientId,
            'telefone_contato' => '(11) 99999-9999',
            'status' => 'aguardando_resposta',
            'token_publico' => 'token-alternativas',
            'token_expira_em' => now()->addDays(5),
            'subtotal' => 950.00,
            'total' => 950.00,
        ]);
        // Diagnóstico entra em qualquer opção escolhida.
        $this->createBudgetItemRecord($budgetId, ['descricao' => 'Diagnóstico', 'valor_unitario' => 50, 'total' => 50, 'ordem' => 1, 'niveis' => json_encode([1, 2, 3])]);
        // Três módulos de RAM concorrentes: só um pode valer por opção.
        $this->createBudgetItemRecord($budgetId, ['descricao' => 'Memória RAM 2GB', 'valor_unitario' => 100, 'total' => 100, 'ordem' => 2, 'niveis' => json_encode([1])]);
        $this->createBudgetItemRecord($budgetId, [
            'tipo_item' => 'peca',
            'referencia_id' => $pecaId,
            'descricao' => 'Memória RAM 4GB',
            'valor_unitario' => 300,
            'total' => 300,
            'ordem' => 3,
            'niveis' => json_encode([2]),
        ]);
        $this->createBudgetItemRecord($budgetId, ['descricao' => 'Memória RAM 8GB', 'valor_unitario' => 500, 'total' => 500, 'ordem' => 4, 'niveis' => json_encode([3])]);
        app(\App\Services\Estoque\EstoqueReservaService::class)->sincronizar(Budget::query()->findOrFail($budgetId));
        $this->assertSame(1.0, (float) DB::table('pecas')->where('id', $pecaId)->value('quantidade_reservada'));

        $budget = Budget::query()->findOrFail($budgetId);

        // Cada opção projeta só a alternativa que lhe pertence — nunca as três juntas.
        $this->assertSame(['Diagnóstico', 'Memória RAM 2GB'], BudgetTotals::itemsForLevel($budget, 1)->pluck('descricao')->all());
        $this->assertSame(['Diagnóstico', 'Memória RAM 4GB'], BudgetTotals::itemsForLevel($budget, 2)->pluck('descricao')->all());
        $this->assertSame(['Diagnóstico', 'Memória RAM 8GB'], BudgetTotals::itemsForLevel($budget, 3)->pluck('descricao')->all());

        $levels = BudgetTotals::perLevel($budget);
        $this->assertSame(150.0, $levels[0]['total']);
        $this->assertSame(350.0, $levels[1]['total']);
        // Antes da correção este total somava as três memórias (950.0).
        $this->assertSame(550.0, $levels[2]['total']);

        $this->mock(BudgetPdfService::class, function ($mock): void {
            $mock->shouldReceive('generate')->once()->andReturn(['ok' => false, 'message' => 'motor indisponível']);
        });

        $this->post('/orcamento/token-alternativas/aprovar', ['resposta_cliente' => 'Aprovado pelo cliente.', 'nivel' => 3])
            ->assertRedirect(route('budgets.public.show', [
                'token' => 'token-alternativas',
                'resultado' => 'sucesso',
                'mensagem' => 'Orçamento aprovado com sucesso.',
            ]));

        // A aprovação na Completa mantém só a RAM de 8GB — as outras duas somem.
        $this->assertSame(
            ['Diagnóstico', 'Memória RAM 8GB'],
            DB::table('orcamento_itens')->where('orcamento_id', $budgetId)->orderBy('ordem')->pluck('descricao')->all()
        );
        $this->assertDatabaseHas('orcamentos', ['id' => $budgetId, 'nivel_aprovado' => 3, 'subtotal' => 550.00, 'total' => 550.00]);
        // A reserva da RAM de 4GB (perdedora da alternativa) foi liberada.
        $this->assertSame(0.0, (float) DB::table('pecas')->where('id', $pecaId)->value('quantidade_reservada'));
    }

    /**
     * Regressão irmã da anterior: o subtotal/total gravados ANTES da
     * aprovação (o "Total final" que o técnico vê montando o orçamento)
     * também não pode somar cegamente toda linha — isso superestimaria o
     * escopo quando há alternativas, mesmo sem nenhuma aprovação envolvida.
     * O valor certo é o do nível mais alto que os itens realmente formam.
     */
    public function test_draft_subtotal_reflects_the_highest_tier_not_a_naive_sum_of_alternatives(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Subtotal']);
        $token = $this->loginAndGetToken($admin->email);

        $create = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos', [
                'cliente_id' => $clientId,
                'telefone_contato' => '(11) 98888-7777',
                'envolve_equipamento' => false,
                'itens' => [
                    ['descricao' => 'Diagnóstico', 'quantidade' => 1, 'valor_unitario' => 50, 'niveis' => [1, 2, 3]],
                    ['descricao' => 'Memória RAM 2GB', 'quantidade' => 1, 'valor_unitario' => 100, 'niveis' => [1]],
                    ['descricao' => 'Memória RAM 4GB', 'quantidade' => 1, 'valor_unitario' => 300, 'niveis' => [2]],
                    ['descricao' => 'Memória RAM 8GB', 'quantidade' => 1, 'valor_unitario' => 500, 'niveis' => [3]],
                ],
            ]);

        $create->assertCreated();
        $budgetId = (int) $create->json('data.budget.id');

        // A soma cega das 4 linhas seria 950; o escopo máximo real (Completa,
        // só com a RAM de 8GB) é 550.
        $create->assertJsonPath('data.budget.total', 550.0);
        $this->assertDatabaseHas('orcamentos', ['id' => $budgetId, 'subtotal' => 550.00, 'total' => 550.00]);
    }

    public function test_single_level_budget_approves_exactly_as_before(): void
    {
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Comum']);
        $budgetId = $this->createBudgetRecord([
            'cliente_id' => $clientId,
            'status' => 'aguardando_resposta',
            'token_publico' => 'token-comum',
            'token_expira_em' => now()->addDays(5),
            'subtotal' => 220.00,
            'total' => 220.00,
        ]);
        $this->createBudgetItemRecord($budgetId, ['descricao' => 'Serviço comum', 'valor_unitario' => 220, 'total' => 220]);

        $this->mock(BudgetPdfService::class, function ($mock): void {
            $mock->shouldReceive('generate')->never();
        });

        $this->get('/orcamento/token-comum')
            ->assertOk()
            ->assertSee('Aprovar proposta')
            ->assertDontSee('Escolha a opção de manutenção')
            ->assertDontSee('Opção escolhida');

        // `nivel` é ignorado em orçamento comum.
        $this->post('/orcamento/token-comum/aprovar', ['resposta_cliente' => 'Aprovado pelo cliente.', 'nivel' => 3])
            ->assertSessionHas('success', 'Orçamento aprovado com sucesso.');

        $this->assertDatabaseHas('orcamentos', ['id' => $budgetId, 'status' => 'pendente_abertura_os', 'nivel_aprovado' => null, 'total' => 220.00]);
        $this->assertDatabaseHas('orcamento_aprovacoes', ['orcamento_id' => $budgetId, 'nivel' => null, 'resposta_cliente' => 'Aprovado pelo cliente.']);
    }

    public function test_staff_approval_requires_and_applies_the_level(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Telefone']);
        $budgetId = $this->tieredBudget($clientId, ['status' => 'aguardando_resposta', 'criado_por' => $admin->id]);
        $token = $this->loginAndGetToken($admin->email);

        $this->mock(BudgetPdfService::class, function ($mock): void {
            $mock->shouldReceive('generate')->once()->andReturn(['ok' => false, 'message' => 'motor indisponível']);
        });

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos/'.$budgetId.'/aprovar', ['observacao' => 'Cliente aprovou pelo telefone.'])
            ->assertStatus(422);

        $this->assertDatabaseHas('orcamentos', ['id' => $budgetId, 'status' => 'aguardando_resposta']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos/'.$budgetId.'/aprovar', ['observacao' => 'Cliente aprovou pelo telefone.', 'nivel' => 1])
            ->assertOk()
            ->assertJsonPath('data.budget.status', 'pendente_abertura_os');

        // Falha ao gerar o PDF definitivo não desfaz a aprovação.
        $this->assertDatabaseHas('orcamentos', ['id' => $budgetId, 'status' => 'pendente_abertura_os', 'nivel_aprovado' => 1, 'total' => 100.00]);
        $this->assertSame(1, DB::table('orcamento_itens')->where('orcamento_id', $budgetId)->count());
        $this->assertDatabaseHas('orcamento_aprovacoes', ['orcamento_id' => $budgetId, 'origem' => 'painel', 'nivel' => 1]);
    }

    /**
     * Depois que o cliente (ou o atendente) já escolheu um nível, editar o
     * orçamento e trazer de volta itens de nível mais alto precisa reabrir a
     * decisão — senão nivel_aprovado fica travado para sempre e o link
     * público nunca mais oferece a escolha, mesmo com níveis novos nos itens.
     * Orçamento avulso (sem OS) vai para pendente_abertura_os na aprovação,
     * não "aprovado" — por isso o cenário cobre justamente esse status.
     */
    public function test_editing_items_after_staff_approval_without_os_reopens_the_level_choice_for_the_client(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Reabertura']);
        $budgetId = $this->tieredBudget($clientId, [
            'status' => 'aguardando_resposta',
            'criado_por' => $admin->id,
            'token_publico' => 'token-reabertura',
            'token_expira_em' => now()->addDays(5),
        ]);
        $token = $this->loginAndGetToken($admin->email);

        $this->mock(BudgetPdfService::class, function ($mock): void {
            $mock->shouldReceive('generate')->once()->andReturn(['ok' => false, 'message' => 'motor indisponível']);
        });

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos/'.$budgetId.'/aprovar', ['observacao' => 'Aprovado pelo cliente.', 'nivel' => 1])
            ->assertOk()
            ->assertJsonPath('data.budget.status', 'pendente_abertura_os');

        $this->assertDatabaseHas('orcamentos', ['id' => $budgetId, 'status' => 'pendente_abertura_os', 'nivel_aprovado' => 1]);
        $this->assertSame(1, DB::table('orcamento_itens')->where('orcamento_id', $budgetId)->count());

        // Cliente pede para incluir a Avançada também: o técnico edita o
        // orçamento já decidido e insere de volta um item de nível 2.
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/orcamentos/'.$budgetId, [
                'tipo_orcamento' => 'previo',
                'cliente_id' => $clientId,
                'itens' => [
                    ['tipo_item' => 'servico', 'descricao' => 'Fusível', 'quantidade' => 1, 'valor_unitario' => 100, 'niveis' => [1, 2]],
                    ['tipo_item' => 'servico', 'descricao' => 'Bateria', 'quantidade' => 1, 'valor_unitario' => 200, 'niveis' => [2]],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.budget.status', 'reenviar_orcamento')
            ->assertJsonPath('data.budget.has_tiers', true)
            ->assertJsonPath('data.budget.nivel_aprovado', null);

        $this->assertDatabaseHas('orcamentos', [
            'id' => $budgetId,
            'status' => 'reenviar_orcamento',
            'nivel_aprovado' => null,
            'total' => 300.00,
        ]);

        // O mesmo link público — sem precisar de um novo — volta a oferecer
        // a escolha de nível ao cliente.
        $this->get('/orcamento/token-reabertura')
            ->assertOk()
            ->assertSee('Escolha a opção de manutenção');
    }

    public function test_pdf_context_projects_the_chosen_option_and_names_the_approved_one(): void
    {
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente PDF']);
        $budgetId = $this->tieredBudget($clientId, [
            'status' => 'aguardando_resposta',
            'token_publico' => 'token-pdf',
            'token_expira_em' => now()->addDays(5),
        ]);
        $budget = Budget::query()->findOrFail($budgetId);
        $factory = app(BudgetPdfContextFactory::class);

        $projected = $factory->build(['budget' => $budget], ['approval_link' => 'https://erp.test/orcamento/token-pdf', 'nivel' => 2]);
        $this->assertSame('Manutenção Avançada', $projected['orcamento']['opcao_texto']);
        $this->assertSame(300.0, $projected['orcamento']['total']);
        $this->assertSame(['Fusível', 'Bateria'], array_column($projected['itens'], 'descricao'));
        $this->assertSame(['1, 2, 3', '2, 3'], array_column($projected['itens'], 'nivel'));
        $this->assertSame('https://erp.test/orcamento/token-pdf?opcao=2', $projected['orcamento']['link_aprovacao']);

        $full = $factory->build(['budget' => $budget->fresh()], ['approval_link' => 'https://erp.test/orcamento/token-pdf']);
        $this->assertSame('', $full['orcamento']['opcao_texto']);
        $this->assertSame(600.0, $full['orcamento']['total']);
        $this->assertCount(3, $full['itens']);

        $budget->forceFill(['status' => Budget::STATUS_APPROVED, 'nivel_aprovado' => 3])->save();
        $approved = $factory->build(['budget' => $budget->fresh()], ['approval_link' => 'https://erp.test/orcamento/token-pdf', 'nivel' => 1]);
        $this->assertSame('Manutenção Completa', $approved['orcamento']['opcao_texto']);
        $this->assertSame('', $approved['orcamento']['link_aprovacao']);

        $corpo = json_encode(PdfDefaultTemplates::all()['os_orcamento']['schema']['corpo'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('orcamento.opcao_texto', (string) $corpo);
    }

    public function test_public_pdf_download_uses_the_chosen_option(): void
    {
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente PDF Público']);
        $this->tieredBudget($clientId, [
            'status' => 'aguardando_resposta',
            'token_publico' => 'token-pdf-publico',
            'token_expira_em' => now()->addDays(5),
        ]);

        $this->mock(BudgetPdfService::class, function ($mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->withArgs(static fn (Budget $budget, string $link, array $options): bool => ($options['nivel'] ?? null) === 2)
                ->andReturn(['ok' => true, 'bytes' => '%PDF-1.4 opcao 2', 'file_name' => 'Orcamento-opcao2.pdf']);
        });

        $this->get('/orcamento/token-pdf-publico/pdf?opcao=2')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    // ------------------------------------------------------------------
    // Condições comerciais por nível (garantia, parcelamento, formas de
    // pagamento, entrega em domicílio e diferenciais livres).
    // ------------------------------------------------------------------

    public function test_level_overrides_are_saved_resolved_per_level_and_inherit_when_absent(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Condições']);
        $token = $this->loginAndGetToken($admin->email);

        $create = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos', [
                'cliente_id' => $clientId,
                'telefone_contato' => '(11) 98888-7777',
                'envolve_equipamento' => false,
                'formas_pagamento' => ['dinheiro', 'pix'],
                'garantia_dias' => 90,
                'entrega_domicilio' => false,
                'itens' => [
                    ['descricao' => 'Fusível', 'quantidade' => 1, 'valor_unitario' => 100, 'niveis' => [1, 2, 3]],
                    ['descricao' => 'Bateria', 'quantidade' => 1, 'valor_unitario' => 200, 'niveis' => [2, 3]],
                    ['descricao' => 'Película e limpeza', 'quantidade' => 1, 'valor_unitario' => 300, 'niveis' => [3]],
                ],
                'niveis_condicoes' => [
                    // Nível 2 só muda a garantia; o resto herda.
                    2 => ['garantia_dias' => 365],
                    // Nível 3: garantia, formas (com cartão), parcelamento,
                    // entrega e diferenciais.
                    3 => [
                        'garantia_dias' => 730,
                        'formas_pagamento' => ['pix', 'cartao_credito'],
                        'parcelas_sem_juros' => 12,
                        'entrega_domicilio' => true,
                        'beneficios' => ['Instalação expressa', ' Película de brinde ', ''],
                    ],
                    // Fora de 1..3: ignorado sem erro.
                    9 => ['garantia_dias' => 180],
                ],
            ]);

        $create->assertCreated();
        $budgetId = (int) $create->json('data.budget.id');

        $this->assertDatabaseHas('orcamentos', ['id' => $budgetId, 'garantia_dias' => 90, 'entrega_domicilio' => 0]);
        $this->assertDatabaseMissing('orcamento_nivel_condicoes', ['orcamento_id' => $budgetId, 'nivel' => 1]);
        $this->assertDatabaseHas('orcamento_nivel_condicoes', ['orcamento_id' => $budgetId, 'nivel' => 2, 'garantia_dias' => 365, 'parcelas_sem_juros' => null, 'entrega_domicilio' => null]);
        $this->assertDatabaseHas('orcamento_nivel_condicoes', ['orcamento_id' => $budgetId, 'nivel' => 3, 'garantia_dias' => 730, 'parcelas_sem_juros' => 12, 'entrega_domicilio' => 1]);
        $this->assertDatabaseMissing('orcamento_nivel_condicoes', ['orcamento_id' => $budgetId, 'nivel' => 9]);
        $this->assertSame(0, DB::table('orcamento_nivel_formas_pagamento')->where('orcamento_id', $budgetId)->where('nivel', 2)->count());
        $this->assertSame(
            ['pix', 'cartao_credito'],
            DB::table('orcamento_nivel_formas_pagamento')->where('orcamento_id', $budgetId)->where('nivel', 3)->orderBy('ordem')->pluck('forma_codigo')->all()
        );

        $service = app(BudgetCommercialTermsService::class);
        $budget = Budget::query()->findOrFail($budgetId);

        $n1 = $service->forBudget($budget, 1);
        $n2 = $service->forBudget($budget, 2);
        $n3 = $service->forBudget($budget, 3);

        $this->assertSame([90, 365, 730], [$n1['garantia_dias'], $n2['garantia_dias'], $n3['garantia_dias']]);
        $this->assertSame('Dinheiro, Pix', $n2['formas_pagamento_texto']);
        $this->assertSame('Pix, Cartão de crédito', $n3['formas_pagamento_texto']);
        $this->assertNull($n2['parcelas_sem_juros']);
        $this->assertSame(12, $n3['parcelas_sem_juros']);
        $this->assertSame('Cartão de crédito em até 12x sem juros.', $n3['parcelamento_texto']);
        $this->assertFalse($n1['entrega_domicilio']);
        $this->assertFalse($n2['entrega_domicilio']);
        $this->assertTrue($n3['entrega_domicilio']);
        $this->assertSame([], $n2['beneficios']);
        $this->assertSame(['Instalação expressa', 'Película de brinde'], $n3['beneficios']);

        // O detalhe devolve o que está GRAVADO por nível, para o formulário.
        $detail = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/orcamentos/'.$budgetId)
            ->assertOk();
        $detail->assertJsonPath('data.budget.entrega_domicilio', false)
            ->assertJsonPath('data.budget.niveis_condicoes.2.garantia_dias', 365)
            ->assertJsonPath('data.budget.niveis_condicoes.2.entrega_domicilio', null)
            ->assertJsonPath('data.budget.niveis_condicoes.2.formas_pagamento', [])
            ->assertJsonPath('data.budget.niveis_condicoes.3.entrega_domicilio', true)
            ->assertJsonPath('data.budget.niveis_condicoes.3.beneficios', ['Instalação expressa', 'Película de brinde'])
            ->assertJsonMissingPath('data.budget.niveis_condicoes.1');
    }

    public function test_level_with_every_field_empty_loses_its_override_row_and_explicit_no_delivery_is_kept(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Limpa Override']);
        $token = $this->loginAndGetToken($admin->email);
        $budgetId = $this->tieredBudget($clientId, ['entrega_domicilio' => 1, 'garantia_dias' => 90]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/orcamentos/'.$budgetId, [
                'niveis_condicoes' => [
                    // Padrão liga a entrega; a Básica desliga explicitamente.
                    1 => ['entrega_domicilio' => '0'],
                    2 => ['garantia_dias' => 365, 'formas_pagamento' => ['pix']],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('orcamento_nivel_condicoes', ['orcamento_id' => $budgetId, 'nivel' => 1, 'entrega_domicilio' => 0]);
        $this->assertDatabaseHas('orcamento_nivel_condicoes', ['orcamento_id' => $budgetId, 'nivel' => 2, 'garantia_dias' => 365]);
        $this->assertSame(1, DB::table('orcamento_nivel_formas_pagamento')->where('orcamento_id', $budgetId)->where('nivel', 2)->count());

        $service = app(BudgetCommercialTermsService::class);
        $budget = Budget::query()->findOrFail($budgetId);
        // O `??` do serviço não pode engolir o false gravado.
        $this->assertFalse($service->forBudget($budget, 1)['entrega_domicilio']);
        $this->assertTrue($service->forBudget($budget, 2)['entrega_domicilio']);

        // Payload parcial (sem a chave) não mexe nas personalizações.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/orcamentos/'.$budgetId, ['observacoes' => 'só uma nota'])
            ->assertOk();
        $this->assertSame(2, DB::table('orcamento_nivel_condicoes')->where('orcamento_id', $budgetId)->count());

        // Tudo vazio num nível = volta a herdar (linha e formas somem).
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/orcamentos/'.$budgetId, [
                'niveis_condicoes' => [
                    1 => ['entrega_domicilio' => ''],
                    2 => ['garantia_dias' => null, 'formas_pagamento' => [], 'parcelas_sem_juros' => null, 'beneficios' => []],
                ],
            ])
            ->assertOk();

        $this->assertSame(0, DB::table('orcamento_nivel_condicoes')->where('orcamento_id', $budgetId)->count());
        $this->assertSame(0, DB::table('orcamento_nivel_formas_pagamento')->where('orcamento_id', $budgetId)->count());
    }

    public function test_public_landing_keeps_the_shared_footer_when_no_level_customizes_terms(): void
    {
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Rodapé Único']);
        $budgetId = $this->tieredBudget($clientId, [
            'status' => 'aguardando_resposta',
            'token_publico' => 'token-rodape-unico',
            'token_expira_em' => now()->addDays(5),
            'garantia_dias' => 180,
            'entrega_domicilio' => 1,
        ]);
        DB::table('orcamento_formas_pagamento')->insert([
            ['orcamento_id' => $budgetId, 'forma_codigo' => 'pix', 'forma_nome' => 'Pix', 'is_cartao' => 0, 'ordem' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->get('/orcamento/token-rodape-unico')
            ->assertOk()
            ->assertSee('Garantia de 180 dias em qualquer opção escolhida.')
            ->assertSee('Entrega do equipamento no seu endereço em qualquer opção.')
            ->assertSee('Formas de pagamento: Pix.')
            ->assertDontSee('Vantagens desta opção');
    }

    public function test_public_landing_moves_terms_into_the_cards_when_levels_differ(): void
    {
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Por Cartão']);
        $budgetId = $this->tieredBudget($clientId, [
            'status' => 'aguardando_resposta',
            'token_publico' => 'token-por-cartao',
            'token_expira_em' => now()->addDays(5),
            'garantia_dias' => 90,
            'entrega_domicilio' => 0,
        ]);
        DB::table('orcamento_formas_pagamento')->insert([
            ['orcamento_id' => $budgetId, 'forma_codigo' => 'pix', 'forma_nome' => 'Pix', 'is_cartao' => 0, 'ordem' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('orcamento_nivel_condicoes')->insert([
            ['orcamento_id' => $budgetId, 'nivel' => 3, 'garantia_dias' => 365, 'parcelas_sem_juros' => null, 'entrega_domicilio' => 1, 'beneficios' => json_encode(['Instalação expressa']), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $response = $this->get('/orcamento/token-por-cartao')->assertOk();

        $response
            // Garantia e entrega variam: saem do rodapé e entram nos cartões.
            ->assertDontSee('em qualquer opção escolhida')
            ->assertDontSee('Entrega do equipamento no seu endereço em qualquer opção.')
            ->assertSee('Vantagens desta opção')
            ->assertSee('Garantia de 90 dias')
            ->assertSee('Garantia de 1 ano')
            ->assertSee('Entrega no seu endereço')
            ->assertSee('Instalação expressa')
            // Formas de pagamento continuam iguais em todas: ficam no rodapé.
            ->assertSee('Formas de pagamento: Pix.');

        // "Entrega no seu endereço" só no cartão da Completa (uma ocorrência).
        $this->assertSame(1, substr_count((string) $response->getContent(), 'Entrega no seu endereço'));

        // Passo 2 da Completa mostra as condições daquela opção.
        $this->get('/orcamento/token-por-cartao?opcao=3')
            ->assertOk()
            ->assertSee('1 ano')
            ->assertSee('No seu endereço')
            ->assertSee('Diferenciais desta opção')
            ->assertSee('Instalação expressa');

        // Passo 2 da Básica: garantia base, sem entrega, sem diferenciais.
        $this->get('/orcamento/token-por-cartao?opcao=1')
            ->assertOk()
            ->assertSee('90 dias')
            ->assertDontSee('No seu endereço')
            ->assertDontSee('Diferenciais desta opção');
    }

    public function test_approval_collapses_the_chosen_level_terms_into_the_budget_and_legacy_callers_resolve_to_it(): void
    {
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Colapso']);
        $budgetId = $this->tieredBudget($clientId, [
            'status' => 'aguardando_resposta',
            'token_publico' => 'token-colapso',
            'token_expira_em' => now()->addDays(5),
            'garantia_dias' => 90,
            'parcelas_sem_juros' => null,
            'entrega_domicilio' => 0,
        ]);
        DB::table('orcamento_formas_pagamento')->insert([
            ['orcamento_id' => $budgetId, 'forma_codigo' => 'dinheiro', 'forma_nome' => 'Dinheiro', 'is_cartao' => 0, 'ordem' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('orcamento_nivel_condicoes')->insert([
            ['orcamento_id' => $budgetId, 'nivel' => 2, 'garantia_dias' => 365, 'parcelas_sem_juros' => 6, 'entrega_domicilio' => 1, 'beneficios' => json_encode(['Película de brinde']), 'created_at' => now(), 'updated_at' => now()],
            ['orcamento_id' => $budgetId, 'nivel' => 3, 'garantia_dias' => 730, 'parcelas_sem_juros' => null, 'entrega_domicilio' => null, 'beneficios' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('orcamento_nivel_formas_pagamento')->insert([
            ['orcamento_id' => $budgetId, 'nivel' => 2, 'forma_codigo' => 'cartao_credito', 'forma_nome' => 'Cartão de crédito', 'is_cartao' => 1, 'ordem' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->mock(BudgetPdfService::class, function ($mock): void {
            $mock->shouldReceive('generate')->once()->andReturn([
                'ok' => true,
                'bytes' => '%PDF-1.4 definitivo',
                'absolute_path' => '',
                'relative_path' => 'private/orcamentos/definitivo.pdf',
                'file_name' => 'Orcamento-definitivo.pdf',
            ]);
        });

        $this->post('/orcamento/token-colapso/aprovar', ['nivel' => 2])->assertRedirect();

        // Colunas base agora refletem a Avançada (OS, baixa e revisão leem daqui).
        $this->assertDatabaseHas('orcamentos', [
            'id' => $budgetId,
            'nivel_aprovado' => 2,
            'garantia_dias' => 365,
            'parcelas_sem_juros' => 6,
            'entrega_domicilio' => 1,
        ]);
        $this->assertSame(
            ['cartao_credito'],
            DB::table('orcamento_formas_pagamento')->where('orcamento_id', $budgetId)->orderBy('ordem')->pluck('forma_codigo')->all()
        );
        // Override da escolhida: campos estruturados zerados (os diferenciais
        // ficam, não têm coluna base); formas do nível somem.
        $this->assertDatabaseHas('orcamento_nivel_condicoes', ['orcamento_id' => $budgetId, 'nivel' => 2, 'garantia_dias' => null, 'parcelas_sem_juros' => null, 'entrega_domicilio' => null]);
        $this->assertSame(0, DB::table('orcamento_nivel_formas_pagamento')->where('orcamento_id', $budgetId)->where('nivel', 2)->count());
        // Nível não escolhido fica como estava (auditoria do que foi oferecido).
        $this->assertDatabaseHas('orcamento_nivel_condicoes', ['orcamento_id' => $budgetId, 'nivel' => 3, 'garantia_dias' => 730]);

        // Chamada legada, sem nível: resolve para o aprovado.
        $budget = Budget::query()->findOrFail($budgetId);
        $terms = app(BudgetCommercialTermsService::class)->forBudget($budget);
        $this->assertSame(2, $terms['nivel']);
        $this->assertSame(365, $terms['garantia_dias']);
        $this->assertSame('Cartão de crédito', $terms['formas_pagamento_texto']);
        $this->assertTrue($terms['entrega_domicilio']);
        $this->assertSame(['Película de brinde'], $terms['beneficios']);

        // Pedir outro nível depois de aprovado também cai no aprovado.
        $this->assertSame(365, app(BudgetCommercialTermsService::class)->forBudget($budget, 3)['garantia_dias']);

        // Página pública pós-decisão mostra as condições da opção aprovada.
        $this->get('/orcamento/token-colapso')
            ->assertOk()
            ->assertSee('1 ano')
            ->assertSee('No seu endereço')
            ->assertSee('Película de brinde');
    }

    public function test_pdf_context_carries_the_projected_level_terms_delivery_and_benefits(): void
    {
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente PDF Condições']);
        $budgetId = $this->tieredBudget($clientId, [
            'status' => 'aguardando_resposta',
            'garantia_dias' => 90,
            'entrega_domicilio' => 0,
        ]);
        DB::table('orcamento_nivel_condicoes')->insert([
            ['orcamento_id' => $budgetId, 'nivel' => 3, 'garantia_dias' => 365, 'parcelas_sem_juros' => null, 'entrega_domicilio' => 1, 'beneficios' => json_encode(['Instalação expressa', 'Película de brinde']), 'created_at' => now(), 'updated_at' => now()],
        ]);
        $budget = Budget::query()->findOrFail($budgetId);

        $this->mock(CompanyProfileService::class, function ($mock): void {
            $mock->shouldReceive('profile')->andReturn(['nome' => 'Assistência Teste']);
        });
        $this->mock(IntegrationSettingsService::class, function ($mock): void {
            $mock->shouldReceive('whatsappSettings')->andReturn([]);
        });

        $factory = app(BudgetPdfContextFactory::class);

        $completa = $factory->build(['budget' => $budget], ['nivel' => 3]);
        $this->assertSame('1 ano', $completa['orcamento']['garantia_prazo']);
        $this->assertSame(BudgetCommercialTermsService::ENTREGA_DOMICILIO_TEXTO, $completa['orcamento']['entrega_domicilio_texto']);
        $this->assertSame("Instalação expressa\nPelícula de brinde", $completa['orcamento']['beneficios_texto']);
        $this->assertSame([['descricao' => 'Instalação expressa'], ['descricao' => 'Película de brinde']], $completa['beneficios']);

        $basica = $factory->build(['budget' => $budget->fresh()], ['nivel' => 1]);
        $this->assertSame('90 dias', $basica['orcamento']['garantia_prazo']);
        $this->assertSame('', $basica['orcamento']['entrega_domicilio_texto']);
        $this->assertSame('', $basica['orcamento']['beneficios_texto']);
        $this->assertSame([], $basica['beneficios']);

        // O modelo padrão valida com as variáveis novas.
        $validator = app(\App\Services\Pdf\PdfSchemaValidator::class);
        $descriptor = app(\App\Services\Pdf\PdfTemplateRegistry::class)->get('os_orcamento');
        $this->assertIsArray($descriptor);
        $this->assertSame([], $validator->validate(PdfDefaultTemplates::all()['os_orcamento']['schema'], $descriptor));
    }

    public function test_single_level_budget_with_delivery_shows_it_like_any_base_condition(): void
    {
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Entrega Simples']);
        $budgetId = $this->createBudgetRecord([
            'numero' => 'ORC-ENTREGA-1',
            'cliente_id' => $clientId,
            'status' => 'aguardando_resposta',
            'token_publico' => 'token-entrega-simples',
            'token_expira_em' => now()->addDays(5),
            'entrega_domicilio' => 1,
            'subtotal' => 100,
            'total' => 100,
        ]);
        $this->createBudgetItemRecord($budgetId, ['descricao' => 'Fusível', 'valor_unitario' => 100, 'total' => 100, 'ordem' => 1, 'niveis' => json_encode([1])]);

        $this->get('/orcamento/token-entrega-simples')
            ->assertOk()
            ->assertSee('Aprovar proposta')
            ->assertSee('No seu endereço')
            ->assertDontSee('Escolha a opção de manutenção');

        $terms = app(BudgetCommercialTermsService::class)->forBudget(Budget::query()->findOrFail($budgetId));
        $this->assertNull($terms['nivel']);
        $this->assertTrue($terms['entrega_domicilio']);
        $this->assertStringContainsString(BudgetCommercialTermsService::ENTREGA_DOMICILIO_TEXTO, $terms['resumo']);
    }

    // ------------------------------------------------------------------
    // Landing de venda: hero humano, argumentos e faixa de confiança.
    // ------------------------------------------------------------------

    public function test_public_landing_shows_greeting_logo_and_whatsapp(): void
    {
        $clientId = $this->createClientRecord(['nome_razao' => 'MARIA JOSÉ DA SILVA']);
        $this->tieredBudget($clientId, [
            'status' => 'aguardando_resposta',
            'token_publico' => 'token-hero',
            'token_expira_em' => now()->addDays(5),
        ]);

        $this->mock(CompanyProfileService::class, function ($mock): void {
            $mock->shouldReceive('payload')->andReturn(['settings' => [
                'empresa_nome_fantasia' => 'Jovem Tech Celulares e Informática',
                'empresa_telefone' => '(22) 99927-4100',
            ]]);
        });
        $this->mock(\App\Services\Pdf\Contexts\CompanyContextProvider::class, function ($mock): void {
            $mock->shouldReceive('logoDataUri')->andReturn('data:image/png;base64,AAAA');
        });

        $this->get('/orcamento/token-hero')
            ->assertOk()
            ->assertSee('Olá, Maria.')
            ->assertDontSee('Quem cuidou da análise')
            ->assertSee('src="data:image/png;base64,AAAA"', false)
            // Nome com 3+ palavras: 2 primeiras como marca, resto como
            // subtítulo menor (ver $heroCompanySecondary no hero-landing).
            ->assertSee('<span class="brand-name">Jovem Tech<span class="brand-name-sub">Celulares e Informática</span></span>', false)
            ->assertSee('https://wa.me/5522999274100?text=', false)
            ->assertSee('<a class="btn-whatsapp" href="https://wa.me/5522999274100', false)
            ->assertSee('Precisa de ajuda?')
            ->assertSee('Falar com a gente no WhatsApp')
            ->assertSee('Ficou com alguma dúvida?')
            ->assertSee('Peças e mão de obra já incluídas no valor de cada opção.')
            ->assertSee('Você aprova só depois de ver o orçamento completo.');
    }

    public function test_public_landing_without_phone_or_logo_falls_back_gracefully(): void
    {
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Sem Contato']);
        $this->tieredBudget($clientId, [
            'status' => 'aguardando_resposta',
            'token_publico' => 'token-sem-contato',
            'token_expira_em' => now()->addDays(5),
        ]);

        $this->mock(CompanyProfileService::class, function ($mock): void {
            $mock->shouldReceive('payload')->andReturn(['settings' => ['empresa_nome_fantasia' => 'Jovem Tech']]);
        });
        $this->mock(\App\Services\Pdf\Contexts\CompanyContextProvider::class, function ($mock): void {
            $mock->shouldReceive('logoDataUri')->andReturn('');
        });

        $response = $this->get('/orcamento/token-sem-contato')->assertOk();
        $response
            ->assertSee('<span class="brand-name">Jovem Tech</span>', false)
            ->assertDontSee('<img class="brand-logo"', false)
            ->assertDontSee('wa.me', false)
            ->assertDontSee('Precisa de ajuda?')
            ->assertDontSee('Falar com a gente no WhatsApp')
            ->assertDontSee('Quem cuidou da análise')
            ->assertSee('Olá, Cliente.');
    }

    public function test_public_landing_shows_installment_and_delta_under_the_price(): void
    {
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Parcela']);
        $budgetId = $this->tieredBudget($clientId, [
            'status' => 'aguardando_resposta',
            'token_publico' => 'token-parcela',
            'token_expira_em' => now()->addDays(5),
            'parcelas_sem_juros' => 3,
        ]);
        DB::table('orcamento_formas_pagamento')->insert([
            ['orcamento_id' => $budgetId, 'forma_codigo' => 'cartao_credito', 'forma_nome' => 'Cartão de crédito', 'is_cartao' => 1, 'ordem' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('orcamento_nivel_condicoes')->insert([
            ['orcamento_id' => $budgetId, 'nivel' => 3, 'garantia_dias' => null, 'parcelas_sem_juros' => 12, 'entrega_domicilio' => null, 'beneficios' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->get('/orcamento/token-parcela')
            ->assertOk()
            // Totais 100 / 300 / 600: parcela por opção e diferença para a anterior.
            ->assertSee('ou 3x de R$ 33,33 sem juros')
            ->assertSee('ou 3x de R$ 100,00 sem juros')
            ->assertSee('ou 12x de R$ 50,00 sem juros')
            ->assertSee('+ R$ 200,00 em relação à Básica')
            ->assertSee('+ R$ 300,00 em relação à Avançada');
    }

    public function test_public_landing_shows_nfse_badge_when_budget_marks_it_and_mei_limit_is_clear(): void
    {
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Nota Fiscal']);
        $this->tieredBudget($clientId, [
            'status' => 'aguardando_resposta',
            'token_publico' => 'token-nfse-ok',
            'token_expira_em' => now()->addDays(5),
            'emite_nota_fiscal' => true,
        ]);

        $this->mock(AnexoXService::class, function ($mock): void {
            $mock->shouldReceive('limiteAnualAtingido')->once()->andReturn(false);
        });

        $this->get('/orcamento/token-nfse-ok')
            ->assertOk()
            ->assertSee('Emissão de nota fiscal de serviço (NFS-e) em qualquer opção escolhida.');
    }

    public function test_public_landing_hides_nfse_badge_once_mei_limit_is_reached(): void
    {
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Limite MEI']);
        $this->tieredBudget($clientId, [
            'status' => 'aguardando_resposta',
            'token_publico' => 'token-nfse-limite',
            'token_expira_em' => now()->addDays(5),
            'emite_nota_fiscal' => true,
        ]);

        // Mesmo com o orçamento marcado, o teto de faturamento do MEI tem a
        // palavra final: a promessa de nota nunca aparece nesse estado.
        $this->mock(AnexoXService::class, function ($mock): void {
            $mock->shouldReceive('limiteAnualAtingido')->once()->andReturn(true);
        });

        $this->get('/orcamento/token-nfse-limite')
            ->assertOk()
            ->assertDontSee('Emissão de nota fiscal de serviço (NFS-e)');
    }

    public function test_public_landing_hides_nfse_badge_when_budget_does_not_mark_it(): void
    {
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Sem Nota']);
        $this->tieredBudget($clientId, [
            'status' => 'aguardando_resposta',
            'token_publico' => 'token-nfse-off',
            'token_expira_em' => now()->addDays(5),
            'emite_nota_fiscal' => false,
        ]);

        // Orçamento não marcou: nem chega a perguntar pelo limite do MEI.
        $this->mock(AnexoXService::class, function ($mock): void {
            $mock->shouldNotReceive('limiteAnualAtingido');
        });

        $this->get('/orcamento/token-nfse-off')
            ->assertOk()
            ->assertDontSee('Emissão de nota fiscal de serviço (NFS-e)');
    }

    /**
     * Três itens, um por nível: Fusível (100, N1), Bateria (200, N2),
     * Película e limpeza (300, N3). Total gravado = escopo máximo (600).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function tieredBudget(int $clientId, array $overrides = []): int
    {
        $budgetId = $this->createBudgetRecord(array_merge([
            'numero' => 'ORC-NIVEIS',
            'cliente_id' => $clientId,
            'telefone_contato' => '(11) 99999-9999',
            'subtotal' => 600.00,
            'total' => 600.00,
        ], $overrides));

        $this->createBudgetItemRecord($budgetId, ['descricao' => 'Fusível', 'valor_unitario' => 100, 'total' => 100, 'ordem' => 1, 'niveis' => json_encode([1, 2, 3])]);
        $this->createBudgetItemRecord($budgetId, ['descricao' => 'Bateria', 'valor_unitario' => 200, 'total' => 200, 'ordem' => 2, 'niveis' => json_encode([2, 3])]);
        $this->createBudgetItemRecord($budgetId, ['descricao' => 'Película e limpeza', 'valor_unitario' => 300, 'total' => 300, 'ordem' => 3, 'niveis' => json_encode([3])]);

        return $budgetId;
    }

    private function admin(): \App\Models\User
    {
        return $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.niveis@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);
    }

    private function loginAndGetToken(string $email): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Senha@123',
            'device_name' => 'desktop-niveis',
        ]);

        $response->assertOk();

        return (string) $response->json('data.access_token');
    }
}
