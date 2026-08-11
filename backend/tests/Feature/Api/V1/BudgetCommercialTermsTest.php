<?php

namespace Tests\Feature\Api\V1;

use App\Models\Budget;
use App\Services\Budgets\BudgetCommercialTermsService;
use App\Services\Pdf\Contexts\BudgetPdfContextFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Condições comerciais do orçamento: formas de pagamento aceitas, chave Pix,
 * parcelamento sem juros e garantia — os dados que antes eram digitados à mão
 * no campo livre e por isso ficavam em branco.
 */
class BudgetCommercialTermsTest extends TestCase
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
            'financeiro' => ['visualizar', 'editar', 'excluir'],
        ]);
        $this->seedOrderCatalog();
        $this->seedOrderNumberConfiguration();
    }

    public function test_budget_stores_selected_payment_methods_warranty_and_installments(): void
    {
        [$token, $clientId] = $this->authenticatedAdminWithClient();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos', [
                'cliente_id' => $clientId,
                'titulo' => 'Troca de tela',
                'formas_pagamento' => ['pix', 'cartao_credito', 'dinheiro'],
                'parcelas_sem_juros' => 6,
                'garantia_dias' => 180,
                'itens' => [
                    ['descricao' => 'Troca de tela', 'quantidade' => 1, 'valor_unitario' => 400, 'total' => 400],
                ],
            ]);

        $response->assertCreated();

        $budgetId = (int) $response->json('data.budget.id');
        $terms = $response->json('data.budget.condicoes_comerciais');

        // A ordem segue o catálogo (ordem_exibicao), não a ordem digitada.
        $this->assertSame('Dinheiro, Pix, Cartão de crédito', $terms['formas_pagamento_texto']);
        $this->assertSame(180, $terms['garantia_dias']);
        $this->assertSame('180 dias', $terms['garantia_label']);
        $this->assertSame(6, $terms['parcelas_sem_juros']);
        $this->assertSame('Cartão de crédito em até 6x sem juros.', $terms['parcelamento_texto']);
        $this->assertTrue($terms['aceita_pix']);
        $this->assertTrue($terms['tem_conteudo']);

        $this->assertDatabaseHas('orcamentos', [
            'id' => $budgetId,
            'garantia_dias' => 180,
            'parcelas_sem_juros' => 6,
        ]);
        $this->assertSame(
            3,
            DB::table('orcamento_formas_pagamento')->where('orcamento_id', $budgetId)->count()
        );
    }

    public function test_registered_pix_keys_are_shown_only_when_pix_is_accepted(): void
    {
        [$token, $clientId] = $this->authenticatedAdminWithClient();
        $this->createPixKey();

        $comPix = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos', [
                'cliente_id' => $clientId,
                'formas_pagamento' => ['pix'],
                'itens' => [['descricao' => 'Serviço', 'quantidade' => 1, 'valor_unitario' => 100, 'total' => 100]],
            ])->assertCreated();

        $chaves = $comPix->json('data.budget.condicoes_comerciais.chaves_pix');
        $this->assertCount(1, $chaves);
        $this->assertSame('CNPJ 12.345.678/0001-90 — Jovem Tech — Banco Inter', $chaves[0]['rotulo']);

        $semPix = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos', [
                'cliente_id' => $clientId,
                'formas_pagamento' => ['dinheiro'],
                'itens' => [['descricao' => 'Serviço', 'quantidade' => 1, 'valor_unitario' => 100, 'total' => 100]],
            ])->assertCreated();

        $this->assertSame([], $semPix->json('data.budget.condicoes_comerciais.chaves_pix'));
    }

    public function test_installments_are_discarded_without_an_installment_card(): void
    {
        [$token, $clientId] = $this->authenticatedAdminWithClient();

        // Débito é cartão, mas não parcela.
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos', [
                'cliente_id' => $clientId,
                'formas_pagamento' => ['pix', 'cartao_debito'],
                'parcelas_sem_juros' => 10,
                'itens' => [['descricao' => 'Serviço', 'quantidade' => 1, 'valor_unitario' => 100, 'total' => 100]],
            ])->assertCreated();

        $this->assertNull($response->json('data.budget.condicoes_comerciais.parcelas_sem_juros'));
        $this->assertSame('', $response->json('data.budget.condicoes_comerciais.parcelamento_texto'));
        $this->assertDatabaseHas('orcamentos', [
            'id' => (int) $response->json('data.budget.id'),
            'parcelas_sem_juros' => null,
        ]);
    }

    public function test_partial_update_keeps_terms_that_were_not_sent(): void
    {
        [$token, $clientId] = $this->authenticatedAdminWithClient();

        $budgetId = (int) $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos', [
                'cliente_id' => $clientId,
                'formas_pagamento' => ['pix'],
                'garantia_dias' => 365,
                'itens' => [['descricao' => 'Serviço', 'quantidade' => 1, 'valor_unitario' => 100, 'total' => 100]],
            ])->assertCreated()->json('data.budget.id');

        // Payload sem as condições: nada do que já foi acordado pode sumir.
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/orcamentos/'.$budgetId, ['titulo' => 'Outro título'])
            ->assertOk();

        $terms = $response->json('data.budget.condicoes_comerciais');
        $this->assertSame('Pix', $terms['formas_pagamento_texto']);
        $this->assertSame(365, $terms['garantia_dias']);
        $this->assertSame('1 ano', $terms['garantia_label']);
    }

    public function test_clearing_payment_methods_removes_them(): void
    {
        [$token, $clientId] = $this->authenticatedAdminWithClient();

        $budgetId = (int) $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos', [
                'cliente_id' => $clientId,
                'formas_pagamento' => ['pix', 'dinheiro'],
                'itens' => [['descricao' => 'Serviço', 'quantidade' => 1, 'valor_unitario' => 100, 'total' => 100]],
            ])->assertCreated()->json('data.budget.id');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/orcamentos/'.$budgetId, ['formas_pagamento' => []])
            ->assertOk();

        $this->assertSame('', $response->json('data.budget.condicoes_comerciais.formas_pagamento_texto'));
        $this->assertSame(
            0,
            DB::table('orcamento_formas_pagamento')->where('orcamento_id', $budgetId)->count()
        );
    }

    public function test_unknown_payment_codes_are_ignored(): void
    {
        [$token, $clientId] = $this->authenticatedAdminWithClient();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos', [
                'cliente_id' => $clientId,
                'formas_pagamento' => ['pix', 'forma_inexistente'],
                'itens' => [['descricao' => 'Serviço', 'quantidade' => 1, 'valor_unitario' => 100, 'total' => 100]],
            ])->assertCreated();

        $this->assertSame('Pix', $response->json('data.budget.condicoes_comerciais.formas_pagamento_texto'));
    }

    public function test_form_data_exposes_payment_catalog_and_warranty_options(): void
    {
        [$token] = $this->authenticatedAdminWithClient();
        $this->createPixKey();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/orcamentos/form-data')
            ->assertOk();

        $catalog = $response->json('data.form.condicoes_comerciais_catalogo');

        $this->assertCount(6, $catalog['formas_pagamento']);
        $this->assertCount(1, $catalog['chaves_pix']);
        $this->assertSame(
            [90, 180, 365, 730],
            array_column($catalog['garantia_options'], 'value')
        );

        $credito = collect($catalog['formas_pagamento'])->firstWhere('codigo', 'cartao_credito');
        $debito = collect($catalog['formas_pagamento'])->firstWhere('codigo', 'cartao_debito');
        $this->assertTrue($credito['aceita_parcelamento']);
        $this->assertFalse($debito['aceita_parcelamento']);
    }

    public function test_pdf_context_exposes_commercial_terms(): void
    {
        [$token, $clientId] = $this->authenticatedAdminWithClient();
        $this->createPixKey();

        $budgetId = (int) $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos', [
                'cliente_id' => $clientId,
                'formas_pagamento' => ['pix', 'cartao_credito'],
                'parcelas_sem_juros' => 3,
                'garantia_dias' => 90,
                'itens' => [['descricao' => 'Serviço', 'quantidade' => 1, 'valor_unitario' => 100, 'total' => 100]],
            ])->assertCreated()->json('data.budget.id');

        $context = app(BudgetPdfContextFactory::class)->build(['budget_id' => $budgetId]);

        $this->assertSame('Pix, Cartão de crédito', $context['orcamento']['formas_pagamento']);
        $this->assertSame('Cartão de crédito em até 3x sem juros.', $context['orcamento']['parcelamento']);
        $this->assertSame('90 dias', $context['orcamento']['garantia_prazo']);
        $this->assertStringContainsString('Garantia de 90 dias', $context['orcamento']['garantia_texto']);
        $this->assertStringContainsString('12.345.678/0001-90', $context['orcamento']['chaves_pix']);

        // Coleções tabulares do motor de PDF.
        $this->assertSame(
            ['Pix', 'Cartão de crédito'],
            array_column($context['formas_pagamento'], 'nome')
        );
        $this->assertSame('CNPJ', $context['chaves_pix'][0]['tipo']);
    }

    public function test_approval_link_disappears_from_the_pdf_when_the_budget_expires(): void
    {
        [$token, $clientId] = $this->authenticatedAdminWithClient();

        $budgetId = (int) $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos', [
                'cliente_id' => $clientId,
                'itens' => [['descricao' => 'Serviço', 'quantidade' => 1, 'valor_unitario' => 100, 'total' => 100]],
            ])->assertCreated()->json('data.budget.id');

        $link = 'https://erp.example.com/orcamento/abc123';
        $factory = app(BudgetPdfContextFactory::class);

        // Dentro do prazo: o botão tem destino.
        DB::table('orcamentos')->where('id', $budgetId)->update([
            'validade_data' => now()->addDays(5)->toDateString(),
            'token_expira_em' => now()->addDays(5),
        ]);
        $vigente = $factory->build(['budget_id' => $budgetId], ['approval_link' => $link]);
        $this->assertSame($link, $vigente['orcamento']['link_aprovacao']);

        // Vencido: o link já devolve 410, então o documento não pode convidar o
        // cliente a clicar. O condicional do modelo esconde a seção inteira.
        DB::table('orcamentos')->where('id', $budgetId)->update([
            'validade_data' => now()->subDays(3)->toDateString(),
            'token_expira_em' => now()->subDay(),
        ]);
        $vencido = $factory->build(['budget_id' => $budgetId], ['approval_link' => $link]);
        $this->assertSame('', $vencido['orcamento']['link_aprovacao']);
    }

    public function test_public_link_deadline_is_the_single_rule_shared_by_pdf_and_public_page(): void
    {
        $budget = new Budget;

        // Sem prazo nenhum: nada a expirar.
        $this->assertNull($budget->publicLinkDeadline());
        $this->assertFalse($budget->publicLinkExpired());

        // Só validade comercial: vale até o fim daquele dia.
        $budget->validade_data = now()->addDay()->startOfDay();
        $this->assertSame(
            now()->addDay()->endOfDay()->format('Y-m-d H:i'),
            $budget->publicLinkDeadline()?->format('Y-m-d H:i')
        );
        $this->assertFalse($budget->publicLinkExpired());

        // Prazo do token vence a validade comercial.
        $budget->token_expira_em = now()->subHour();
        $this->assertTrue($budget->publicLinkExpired());
    }

    public function test_public_approval_page_presents_the_terms_in_structured_blocks(): void
    {
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Público']);
        $budgetId = $this->createBudgetRecord([
            'numero' => 'ORC-2608-000200',
            'cliente_id' => $clientId,
            'status' => 'aguardando_resposta',
            'token_publico' => 'token-condicoes-publicas',
            'token_expira_em' => now()->addDays(5),
            'validade_data' => now()->addDays(5)->toDateString(),
            'garantia_dias' => 365,
            'parcelas_sem_juros' => 6,
            'subtotal' => 100.00,
            'total' => 100.00,
        ]);
        $this->createBudgetItemRecord($budgetId, ['descricao' => 'Serviço', 'valor_unitario' => 100.00, 'total' => 100.00]);
        $this->createPixKey();

        app(BudgetCommercialTermsService::class)->syncPaymentMethods(
            Budget::query()->findOrFail($budgetId),
            ['dinheiro', 'pix', 'cartao_credito']
        );

        $response = $this->get('/orcamento/token-condicoes-publicas')->assertOk();

        // Blocos estruturados, não um parágrafo corrido: o cliente precisa
        // achar como paga e a garantia de relance.
        $response->assertSee('Condições comerciais');
        $response->assertSee('terms-grid', false);
        $response->assertSee('Formas de pagamento aceitas');
        // Cada forma vira um chip próprio.
        $response->assertSee('<span class="chip">Pix</span>', false);
        $response->assertSee('<span class="chip">Cartão de crédito</span>', false);
        $response->assertSee('Cartão de crédito em até 6x sem juros.');
        // Garantia em destaque.
        $response->assertSee('1 ano');
        // A chave Pix é o dado que o cliente copia: sai isolada e selecionável.
        $response->assertSee('12.345.678/0001-90');
        $response->assertSee('pix-valor', false);
        $response->assertSee('Jovem Tech · Banco Inter');
    }

    public function test_each_pix_key_gets_its_own_copy_button(): void
    {
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Público']);
        $budgetId = $this->createBudgetRecord([
            'numero' => 'ORC-2608-000201',
            'cliente_id' => $clientId,
            'status' => 'aguardando_resposta',
            'token_publico' => 'token-copiar-pix',
            'token_expira_em' => now()->addDays(5),
            'validade_data' => now()->addDays(5)->toDateString(),
            'subtotal' => 100.00,
            'total' => 100.00,
        ]);
        $this->createBudgetItemRecord($budgetId, ['descricao' => 'Serviço', 'valor_unitario' => 100.00, 'total' => 100.00]);

        $this->createPixKey();
        DB::table('financeiro_chaves_pix')->insert([
            'tipo' => 'email',
            'chave' => 'financeiro@jovemtech.com.br',
            'titular' => 'Jovem Tech',
            'principal' => false,
            'ativo' => true,
            'ordem_exibicao' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(BudgetCommercialTermsService::class)->syncPaymentMethods(
            Budget::query()->findOrFail($budgetId),
            ['pix']
        );

        $html = $this->get('/orcamento/token-copiar-pix')->assertOk()->getContent();

        // Um botão por chave, cada um apontando para a sua.
        $this->assertSame(2, substr_count($html, 'class="btn-copy"'));
        $this->assertStringContainsString('data-copy="12.345.678/0001-90"', $html);
        $this->assertStringContainsString('data-copy="financeiro@jovemtech.com.br"', $html);

        // Cada botão precisa apontar para o id do SEU valor, senão copiaria a
        // chave errada quando a API de clipboard não está disponível.
        $this->assertStringContainsString('id="pix-chave-0"', $html);
        $this->assertStringContainsString('data-copy-target="pix-chave-0"', $html);
        $this->assertStringContainsString('id="pix-chave-1"', $html);
        $this->assertStringContainsString('data-copy-target="pix-chave-1"', $html);
    }

    public function test_warranty_label_falls_back_to_days_for_legacy_terms(): void
    {
        $this->assertSame('2 anos', Budget::warrantyLabel(730));
        $this->assertSame('45 dias', Budget::warrantyLabel(45));
        $this->assertSame('', Budget::warrantyLabel(0));
        $this->assertSame('', Budget::warrantyLabel(null));
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function authenticatedAdminWithClient(): array
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.terms@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Condições',
            'cpf_cnpj' => '11.222.333/0001-44',
        ]);

        return [$this->loginAndGetToken($admin->email), $clientId];
    }

    private function loginAndGetToken(string $email): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Senha@123',
            'device_name' => 'desktop-budget-terms',
        ]);

        return (string) $response->json('data.access_token');
    }

    private function createPixKey(): void
    {
        DB::table('financeiro_chaves_pix')->insert([
            'tipo' => 'cnpj',
            'chave' => '12.345.678/0001-90',
            'titular' => 'Jovem Tech',
            'instituicao' => 'Banco Inter',
            'principal' => true,
            'ativo' => true,
            'ordem_exibicao' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
