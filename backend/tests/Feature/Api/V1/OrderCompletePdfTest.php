<?php

namespace Tests\Feature\Api\V1;

use App\Models\Order;
use App\Models\OrderEvent;
use App\Services\Orders\OrderPrintService;
use App\Services\Pdf\Contexts\OrderCompletePdfContextFactory;
use App\Services\Pdf\PdfDefaultTemplates;
use App\Services\Pdf\PdfTemplateRegistry;
use App\Services\Pdf\PdfTemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * "Ordem de serviço completa" (os_completa) — o documento do botão Imprimir
 * da tela da OS: contexto, corte do cupom de 80 mm e endpoint.
 */
class OrderCompletePdfTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    private const IMAGE_TOKENS = ['logo_empresa', 'foto_equipamento_principal', 'fotos_entrada'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->seedOrderCatalog();
        $this->seedPdfEngineTemplates();
        $this->seedCompanyProfile();
    }

    public function test_context_carries_linked_budget_items_and_history(): void
    {
        $orderId = $this->buildOrderFixture();
        $this->attachBudget($orderId);
        $this->recordEvent($orderId, OrderEvent::TIPO_OS_CRIADA, 'OS criada', '2026-07-01 09:00:00');
        $this->recordEvent($orderId, OrderEvent::TIPO_STATUS_ALTERADO, 'Status alterado', '2026-07-02 10:00:00');

        $context = $this->buildContext($orderId);

        $this->assertSame('ORC-2607-000777', $context['orcamento']['numero']);
        $this->assertSame('Aprovado', $context['orcamento']['status']);
        $this->assertSame(150.0, $context['orcamento']['total']);
        $this->assertCount(1, $context['orcamento_itens']);
        $this->assertSame('Limpeza interna completa', $context['orcamento_itens'][0]['descricao']);

        $this->assertCount(2, $context['historico']);
        // Ordem cronológica: o papel se lê de cima para baixo.
        $this->assertSame('OS criada', $context['historico'][0]['evento']);
        $this->assertSame('Status alterado', $context['historico'][1]['evento']);
        $this->assertSame('Técnico PDF', $context['historico'][0]['autor']);
    }

    public function test_context_without_budget_keeps_the_document_usable(): void
    {
        $orderId = $this->buildOrderFixture();

        $context = $this->buildContext($orderId);

        $this->assertSame('', $context['orcamento']['numero']);
        $this->assertSame([], $context['orcamento_itens']);
        $this->assertSame([], $context['historico']);

        // O documento continua gerando: o bloco condicional some sozinho.
        $result = $this->generate($orderId, 'a4');
        $this->assertTrue((bool) ($result['ok'] ?? false), (string) ($result['message'] ?? ''));
    }

    public function test_history_keeps_only_milestones_and_caps_the_list(): void
    {
        $orderId = $this->buildOrderFixture();

        for ($i = 1; $i <= 30; $i++) {
            $this->recordEvent(
                $orderId,
                OrderEvent::TIPO_STATUS_ALTERADO,
                'Marco '.$i,
                Carbon::parse('2026-07-01 08:00:00')->addMinutes($i)->toDateTimeString()
            );
        }

        // Ruído de automação que não pode entrar no impresso.
        $this->recordEvent(
            $orderId,
            OrderEvent::TIPO_WHATSAPP_ENVIADO,
            'WhatsApp enviado ao cliente',
            '2026-07-01 09:00:00',
            OrderEvent::CATEGORIA_MENSAGEM
        );

        $historico = $this->buildContext($orderId)['historico'];

        $this->assertCount(25, $historico);
        $this->assertSame('Marco 6', $historico[0]['evento']);
        $this->assertSame('Marco 30', $historico[24]['evento']);
        $this->assertNotContains(
            'WhatsApp enviado ao cliente',
            array_column($historico, 'evento')
        );
    }

    public function test_a4_prints_the_full_sheet_and_80mm_stays_short(): void
    {
        $orderId = $this->buildOrderFixture();
        $this->attachBudget($orderId);
        $this->recordEvent($orderId, OrderEvent::TIPO_OS_CRIADA, 'OS criada', '2026-07-01 09:00:00');

        $a4 = $this->renderHtml($orderId, 'a4');
        $thermal = $this->renderHtml($orderId, '80mm');

        foreach (['Dados do atendimento', 'Cliente', 'Equipamento', 'Defeito relatado', 'Acessórios recebidos', 'Itens e serviços', 'Orçamento vinculado'] as $secao) {
            $this->assertStringContainsString($secao, $a4, $secao.' deveria estar no A4');
            $this->assertStringContainsString($secao, $thermal, $secao.' deveria estar no cupom');
        }

        // Só no A4: em bobina térmica viram metros de papel sem serventia.
        foreach (['Checklist de entrada', 'Histórico do atendimento'] as $secao) {
            $this->assertStringContainsString($secao, $a4, $secao.' deveria estar no A4');
            $this->assertStringNotContainsString($secao, $thermal, $secao.' não deveria sair no cupom');
        }

        $this->assertStringContainsString('Técnico responsável', $a4);
        $this->assertStringContainsString('Data:', $a4, 'A assinatura com linha de data sai no A4');
        $this->assertStringNotContainsString('Data:', $thermal, 'O cupom não leva assinatura');
    }

    public function test_photos_section_appears_only_with_entry_photos_and_never_on_80mm(): void
    {
        Storage::fake('local');
        $orderId = $this->buildOrderFixture();

        $semFoto = $this->renderHtml($orderId, 'a4');
        $this->assertStringNotContainsString('Fotos da OS', $semFoto, 'Sem foto o título não pode ficar órfão na folha');

        $path = 'os/'.$orderId.'/entrada.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nS8AAAAASUVORK5CYII=', true);
        $this->assertIsString($png);
        Storage::disk('local')->put($path, $png);

        DB::table('os_fotos')->insert([
            'os_id' => $orderId,
            'arquivo' => $path,
            'tipo' => 'recepcao',
            'created_at' => now(),
        ]);

        $this->assertSame(1, $this->buildContext($orderId, 'a4')['os']['fotos_quantidade']);
        $this->assertStringContainsString('Fotos da OS', $this->renderHtml($orderId, 'a4'));

        // No cupom as fotos nem chegam a ser lidas do disco.
        $this->assertSame(0, $this->buildContext($orderId, '80mm')['os']['fotos_quantidade']);
        $this->assertStringNotContainsString('Fotos da OS', $this->renderHtml($orderId, '80mm'));
    }

    public function test_photo_gallery_keeps_a_fixed_two_column_grid(): void
    {
        Storage::fake('local');
        $orderId = $this->buildOrderFixture();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nS8AAAAASUVORK5CYII=', true);
        $this->assertIsString($png);

        for ($i = 1; $i <= 3; $i++) {
            $path = 'os/'.$orderId.'/entrada-'.$i.'.png';
            Storage::disk('local')->put($path, $png);
            DB::table('os_fotos')->insert([
                'os_id' => $orderId,
                'arquivo' => $path,
                'tipo' => 'recepcao',
                'created_at' => now(),
            ]);
        }

        $html = $this->renderHtml($orderId, 'a4');

        // Grade fixa de 2 colunas: 3 fotos = 2 linhas, e a foto sozinha da
        // última linha continua com meia largura. Até 2026-09-10 a largura era
        // 100/nº de fotos, então uma OS com uma foto só imprimia uma faixa da
        // largura da página inteira.
        $galeria = $this->extractGallery($html);

        $this->assertSame(2, substr_count($galeria, '<tr>'), 'Três fotos devem ocupar duas linhas');
        $this->assertSame(4, substr_count($galeria, 'width: 50.0000%'), 'Quatro células de meia largura (3 fotos + 1 vazia)');
        $this->assertSame(3, substr_count($galeria, 'pdfe-galeria-fotos-item'));
        $this->assertStringNotContainsString('width: 33.3333%', $galeria);
        $this->assertStringNotContainsString('width: 100.0000%', $galeria);
    }

    public function test_print_endpoint_streams_the_pdf_inline_in_both_formats(): void
    {
        $orderId = $this->buildOrderFixture();
        $this->grantGroupPermissions(1, ['os' => ['visualizar']]);
        $user = $this->createUserRecord(['nome' => 'Admin Impressão', 'perfil' => 'admin', 'grupo_id' => 1]);
        $token = $this->loginAndGetToken($user->email);

        foreach (['a4', '80mm'] as $formato) {
            $response = $this->withHeader('Authorization', 'Bearer '.$token)
                ->get('/api/v1/orders/'.$orderId.'/imprimir?formato='.$formato);

            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'), $formato);
            $this->assertStringContainsString('inline;', (string) $response->headers->get('Content-Disposition'), $formato);
            $this->assertStringContainsString('OS26070777', (string) $response->headers->get('Content-Disposition'), $formato);
            $this->assertStringStartsWith('%PDF', $response->getContent(), $formato);
        }
    }

    public function test_print_endpoint_blocks_who_cannot_view_orders(): void
    {
        $orderId = $this->buildOrderFixture();
        $user = $this->createUserRecord(['nome' => 'Sem Acesso', 'perfil' => 'atendente', 'grupo_id' => 3]);
        $token = $this->loginAndGetToken($user->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/v1/orders/'.$orderId.'/imprimir')
            ->assertForbidden();
    }

    public function test_print_endpoint_returns_404_for_unknown_order(): void
    {
        $this->grantGroupPermissions(1, ['os' => ['visualizar']]);
        $user = $this->createUserRecord(['nome' => 'Admin 404', 'perfil' => 'admin', 'grupo_id' => 1]);
        $token = $this->loginAndGetToken($user->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/v1/orders/999999/imprimir')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
    }

    public function test_unknown_format_falls_back_to_a4(): void
    {
        $service = app(OrderPrintService::class);

        $this->assertSame('a4', $service->normalizeFormat('pdf-gigante'));
        $this->assertSame('a4', $service->normalizeFormat(null));
        $this->assertSame('80mm', $service->normalizeFormat('80MM'));
    }

    private function extractGallery(string $html): string
    {
        $inicio = strpos($html, '<table class="pdfe-galeria-fotos">');
        $this->assertNotFalse($inicio, 'A galeria de fotos não foi renderizada.');
        $fim = strpos($html, '</table>', $inicio);
        $this->assertNotFalse($fim);

        return substr($html, $inicio, $fim - $inicio);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(int $orderId, string $formato = 'a4'): array
    {
        return app(OrderCompletePdfContextFactory::class)->build(
            ['order' => Order::query()->findOrFail($orderId)],
            ['formato' => $formato, 'image_tokens' => self::IMAGE_TOKENS]
        );
    }

    private function renderHtml(int $orderId, string $formato): string
    {
        return app(PdfTemplateRenderer::class)->render(
            PdfDefaultTemplates::all()['os_completa']['schema'],
            $this->buildContext($orderId, $formato),
            app(PdfTemplateRegistry::class)->get('os_completa') ?? [],
            $formato
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function generate(int $orderId, string $formato): array
    {
        return app(\App\Services\Pdf\PdfGenerationService::class)->generate(
            'os_completa',
            ['order' => Order::query()->findOrFail($orderId)],
            ['formato' => $formato, 'unsigned_review' => true]
        );
    }

    private function buildOrderFixture(): int
    {
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente OS Completa']);
        $equipmentId = $this->createEquipmentRecord($clientId, ['resumo_tecnico' => 'Notebook Teste PDF']);
        $tecnico = $this->createUserRecord(['nome' => 'Técnico PDF', 'perfil' => 'tecnico', 'grupo_id' => 2]);

        $orderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'tecnico_id' => $tecnico->id,
            'numero_os' => 'OS26070777',
            'relato_cliente' => 'Não liga e esquenta muito.',
            'diagnostico_tecnico' => 'Pasta térmica ressecada.',
            'solucao_aplicada' => 'Limpeza completa e troca da pasta térmica.',
            'acessorios' => 'Carregador',
            'valor_final' => 150.00,
            'garantia_dias' => 90,
        ]);

        DB::table('os_itens')->insert([
            'os_id' => $orderId,
            'tipo' => 'servico',
            'descricao' => 'Limpeza interna completa',
            'quantidade' => 1,
            'valor_unitario' => 150.00,
            'valor_total' => 150.00,
        ]);

        return $orderId;
    }

    private function attachBudget(int $orderId): void
    {
        $order = Order::query()->findOrFail($orderId);

        $budgetId = $this->createBudgetRecord([
            'numero' => 'ORC-2607-000777',
            'cliente_id' => (int) $order->cliente_id,
            'equipamento_id' => (int) $order->equipamento_id,
            'os_id' => $orderId,
            'titulo' => 'Orçamento da OS completa',
            'status' => 'aprovado',
            'subtotal' => 150.00,
            'total' => 150.00,
        ]);

        $this->createBudgetItemRecord($budgetId, [
            'descricao' => 'Limpeza interna completa',
            'quantidade' => 1,
            'valor_unitario' => 150.00,
            'total' => 150.00,
        ]);
    }

    private function recordEvent(
        int $orderId,
        string $tipo,
        string $titulo,
        string $createdAt,
        string $categoria = OrderEvent::CATEGORIA_STATUS
    ): void {
        DB::table('os_eventos')->insert([
            'os_id' => $orderId,
            'categoria' => $categoria,
            'tipo' => $tipo,
            'titulo' => $titulo,
            'origem' => OrderEvent::ORIGEM_USUARIO,
            'usuario_id' => (int) Order::query()->findOrFail($orderId)->tecnico_id,
            'created_at' => $createdAt,
        ]);
    }

    private function seedPdfEngineTemplates(): void
    {
        $now = now();

        foreach (PdfDefaultTemplates::all() as $tipoCodigo => $definition) {
            $schemaJson = json_encode($definition['schema'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $templateId = DB::table('pdf_templates')->insertGetId([
                'tipo_codigo' => $tipoCodigo,
                'nome' => (string) $definition['nome'],
                'arquivado' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('pdf_template_versoes')->insert([
                'template_id' => $templateId,
                'versao' => 1,
                'status' => 'publicado',
                'schema_json' => $schemaJson,
                'papel' => 'a4',
                'orientacao' => 'retrato',
                'hash_schema' => hash('sha256', (string) $schemaJson),
                'publicado_em' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedCompanyProfile(): void
    {
        foreach ([
            'empresa_nome_fantasia' => 'Jovem Tech',
            'empresa_razao_social' => 'Jovem Tech Assistência LTDA',
            'empresa_cnpj' => '12345678000199',
            'empresa_telefone' => '11988887777',
            'empresa_email' => 'contato@jovemtech.test',
            'empresa_endereco' => 'Rua dos Testes, 100 — Centro',
        ] as $chave => $valor) {
            DB::table('configuracoes')->insert([
                'chave' => $chave,
                'valor' => $valor,
                'tipo' => 'texto',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function loginAndGetToken(string $email): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Senha@123',
            'device_name' => 'desktop-os-print',
        ]);

        return (string) $response->json('data.access_token');
    }
}
