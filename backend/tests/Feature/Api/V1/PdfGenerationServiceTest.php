<?php

namespace Tests\Feature\Api\V1;

use App\Models\Budget;
use App\Models\UserSignature;
use App\Services\Pdf\PdfDefaultTemplates;
use App\Services\Pdf\PdfGenerationService;
use App\Services\Pdf\PdfSampleContext;
use App\Services\Pdf\PdfSchemaValidator;
use App\Services\Pdf\PdfTemplateRegistry;
use App\Services\Pdf\PdfTemplateRenderer;
use App\Services\Pdf\PdfVariableResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class PdfGenerationServiceTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->seedOrderCatalog();
        $this->seedPdfEngineTemplates();
        $this->seedCompanyProfile();
    }

    public function test_generates_every_registered_type_in_a4_and_80mm(): void
    {
        [$orderId, $budgetId] = $this->buildOrderAndBudgetFixture();

        $registry = app(PdfTemplateRegistry::class);
        $service = app(PdfGenerationService::class);

        $order = \App\Models\Order::query()->findOrFail($orderId);
        $budget = Budget::query()->findOrFail($budgetId);

        foreach ($registry->codes() as $tipoCodigo) {
            [$subject, $options] = match ($tipoCodigo) {
                'os_orcamento' => [['budget' => $budget], ['approval_link' => 'https://exemplo.test/aprovar/abc123']],
                'os_encerramento' => [['order' => $order], [
                    'status_final_nome' => 'Entregue - Reparado e Pago',
                    'data_entrega' => '18/07/2026',
                    'observacao_encerramento' => 'Cliente retirou na loja.',
                    'valor_titulo' => 150.0,
                    'saldo_restante' => 0.0,
                    'recebimentos' => [
                        ['forma_pagamento' => 'pix', 'valor' => 150.0, 'data_pagamento' => '18/07/2026'],
                    ],
                ]],
                default => [['order' => $order], []],
            };

            foreach (['a4', '80mm'] as $formato) {
                $result = $service->generate($tipoCodigo, $subject, array_merge($options, ['formato' => $formato]));

                $this->assertTrue(
                    (bool) ($result['ok'] ?? false),
                    sprintf('Falha ao gerar %s (%s): %s', $tipoCodigo, $formato, (string) ($result['message'] ?? ''))
                );
                $this->assertStringStartsWith('%PDF', (string) $result['bytes'], $tipoCodigo . ' ' . $formato);
                $this->assertSame(1, (int) $result['template_versao'], $tipoCodigo);
                $this->assertNotSame('', (string) $result['hash_schema'], $tipoCodigo);
            }
        }
    }

    public function test_returns_error_when_type_has_no_published_template(): void
    {
        DB::table('pdf_template_versoes')->delete();
        DB::table('pdf_templates')->delete();

        [$orderId] = $this->buildOrderAndBudgetFixture();
        $order = \App\Models\Order::query()->findOrFail($orderId);

        $result = app(PdfGenerationService::class)->generate('os_laudo_tecnico', ['order' => $order]);

        $this->assertFalse((bool) ($result['ok'] ?? true));
        $this->assertStringContainsString('Nenhum template publicado', (string) ($result['message'] ?? ''));
    }

    public function test_human_attributed_document_is_blocked_without_registered_signature(): void
    {
        config()->set('document-signatures.require_user_signature', true);
        [$orderId] = $this->buildOrderAndBudgetFixture();
        $order = \App\Models\Order::query()->with('technician')->findOrFail($orderId);

        $result = app(PdfGenerationService::class)->generate(
            'os_abertura',
            ['order' => $order],
            ['actor' => $order->technician]
        );

        $this->assertFalse((bool) ($result['ok'] ?? true));
        $this->assertStringContainsString('cadastrar sua assinatura', (string) ($result['message'] ?? ''));
    }

    public function test_schema_validator_blocks_unknown_variable_and_bad_formatter(): void
    {
        $registry = app(PdfTemplateRegistry::class);
        $validator = app(PdfSchemaValidator::class);
        $descriptor = $registry->get('os_laudo_tecnico');

        $schema = PdfDefaultTemplates::all()['os_laudo_tecnico']['schema'];
        $schema['corpo'][] = ['tipo' => 'paragrafo', 'texto' => 'Inválido: {{ os.campo_inexistente }} e {{ os.numero | formato_bugado }}'];
        $schema['corpo'][] = ['tipo' => 'tabela', 'fonte' => 'colecao_inexistente', 'colunas' => [['campo' => 'x', 'rotulo' => 'X']]];

        $errors = $validator->validate($schema, $descriptor);

        $this->assertNotEmpty($errors);
        $this->assertTrue(
            collect($errors)->contains(fn (string $error): bool => str_contains($error, 'os.campo_inexistente')),
            'Deveria acusar variável desconhecida. Erros: ' . implode(' | ', $errors)
        );
        $this->assertTrue(
            collect($errors)->contains(fn (string $error): bool => str_contains($error, 'formato_bugado')),
            'Deveria acusar formatador inválido.'
        );
        $this->assertTrue(
            collect($errors)->contains(fn (string $error): bool => str_contains($error, 'colecao_inexistente')),
            'Deveria acusar fonte de tabela não permitida.'
        );
    }

    public function test_default_schemas_pass_validation_for_their_own_types(): void
    {
        $registry = app(PdfTemplateRegistry::class);
        $validator = app(PdfSchemaValidator::class);

        foreach (PdfDefaultTemplates::all() as $tipoCodigo => $definition) {
            $errors = $validator->validate($definition['schema'], $registry->get($tipoCodigo));

            $this->assertSame(
                [],
                $errors,
                sprintf('Schema padrão de %s inválido: %s', $tipoCodigo, implode(' | ', $errors))
            );
        }
    }

    public function test_signature_renders_responsible_and_client_side_by_side(): void
    {
        $descriptor = app(PdfTemplateRegistry::class)->get('os_laudo_tecnico');
        $schema = PdfDefaultTemplates::all()['os_laudo_tecnico']['schema'];
        $signature = collect($schema['corpo'])->firstWhere('tipo', 'assinatura');

        $this->assertNotNull($signature);
        $this->assertSame([
            '{{ os.tecnico_nome }} - Técnico responsável',
            '{{ cliente.nome }} - Cliente',
        ], $signature['rotulos']);

        $html = app(PdfTemplateRenderer::class)->render(
            $schema,
            PdfSampleContext::for($descriptor),
            $descriptor,
            'a4'
        );

        $this->assertStringContainsString('Exemplo de tecnico nome - Técnico responsável', $html);
        $this->assertStringContainsString('Exemplo de nome - Cliente', $html);
        $this->assertSame(2, substr_count($html, 'style="width: 50%"'));
        $this->assertSame(2, substr_count($html, 'class="imagem-assinatura"'));
    }

    public function test_signed_signature_uses_signer_name_role_and_effective_date_without_moving_columns(): void
    {
        Storage::fake('local');
        config()->set('document-signatures.require_user_signature', true);

        [$orderId] = $this->buildOrderAndBudgetFixture();
        $order = \App\Models\Order::query()->with('technician')->findOrFail($orderId);
        $signer = $order->technician;
        $this->assertNotNull($signer);

        DB::table('equipe_membros')->insert([
            'nome' => 'Otávio Rosa dos Santos',
            'email' => 'otavio@example.test',
            'cargo' => 'Técnico responsável',
            'usuario_id' => (int) $signer->id,
            'atua_tecnico' => true,
            'atua_vendas' => false,
            'atua_administrativo' => false,
            'ativo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $signer->forceFill(['nome' => 'Otávio Rosa dos Santos'])->save();

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nS8AAAAASUVORK5CYII=', true);
        $this->assertIsString($png);
        $signaturePath = 'private/assinaturas/usuarios/' . (int) $signer->id . '/' . Str::uuid() . '.png';
        Storage::disk('local')->put($signaturePath, $png);

        $signature = UserSignature::query()->create([
            'usuario_id' => (int) $signer->id,
            'arquivo' => $signaturePath,
            'hash_sha256' => hash('sha256', $png),
            'origem' => 'desenho',
            'largura' => 1,
            'altura' => 1,
            'ativa' => true,
            'criada_por' => (int) $signer->id,
        ]);

        $result = app(PdfGenerationService::class)->generate(
            'os_laudo_tecnico',
            ['order' => $order],
            [
                'actor' => $signer,
                'signature_signer' => $signer,
                'responsible_signature' => $signature,
                'signature_signed_at' => '2026-06-20 14:30:00 America/Sao_Paulo',
            ]
        );

        $this->assertTrue((bool) ($result['ok'] ?? false), (string) ($result['message'] ?? ''));
        $this->assertStringStartsWith('%PDF', (string) ($result['bytes'] ?? ''));
        $this->assertSame('Otávio Rosa dos Santos', $result['assinatura']['signatario_nome'] ?? null);
        $this->assertSame('Técnico responsável', $result['assinatura']['signatario_funcao'] ?? null);
        $this->assertSame('20/06/2026', $result['assinatura']['assinada_em'] ?? null);
    }

    public function test_signature_validator_limits_labels_and_validates_their_variables(): void
    {
        $validator = app(PdfSchemaValidator::class);
        $descriptor = app(PdfTemplateRegistry::class)->get('os_laudo_tecnico');
        $schema = PdfDefaultTemplates::all()['os_laudo_tecnico']['schema'];

        foreach ($schema['corpo'] as &$block) {
            if (($block['tipo'] ?? null) === 'assinatura') {
                $block['rotulos'] = [
                    '{{ os.tecnico_nome }} - Técnico',
                    '{{ cliente.nome }} - Cliente',
                    '{{ os.campo_inexistente }} - Terceiro',
                ];
            }
        }
        unset($block);

        $errors = $validator->validate($schema, $descriptor);

        $this->assertTrue(collect($errors)->contains(
            static fn (string $error): bool => str_contains($error, 'entre 1 e 2 rótulos')
        ));

        foreach ($schema['corpo'] as &$block) {
            if (($block['tipo'] ?? null) === 'assinatura') {
                $block['rotulos'] = ['{{ os.campo_inexistente }} - Responsável'];
            }
        }
        unset($block);

        $errors = $validator->validate($schema, $descriptor);
        $this->assertTrue(collect($errors)->contains(
            static fn (string $error): bool => str_contains($error, 'os.campo_inexistente')
        ));
    }

    public function test_three_columns_accept_custom_widths_and_reject_a_fourth_column(): void
    {
        $validator = app(PdfSchemaValidator::class);
        $descriptor = app(PdfTemplateRegistry::class)->get('os_abertura');
        $schema = PdfDefaultTemplates::all()['os_abertura']['schema'];

        $this->assertCount(3, $schema['cabecalho'][0]['colunas']);
        $this->assertSame([25, 50, 25], $schema['cabecalho'][0]['larguras']);
        $this->assertSame([], $validator->validate($schema, $descriptor));

        $schema['cabecalho'][0]['colunas'][] = [];
        $schema['cabecalho'][0]['larguras'] = [20, 40, 20, 20];
        $errors = $validator->validate($schema, $descriptor);

        $this->assertTrue(collect($errors)->contains(
            static fn (string $error): bool => str_contains($error, 'entre 1 e 3 colunas')
        ));
    }

    public function test_equipment_photo_token_uses_private_authorized_image_as_data_uri(): void
    {
        Storage::fake('local');
        [$orderId] = $this->buildOrderAndBudgetFixture();
        $order = \App\Models\Order::query()->findOrFail($orderId);
        $path = 'equipamentos/' . (int) $order->equipamento_id . '/principal.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nS8AAAAASUVORK5CYII=', true);
        $this->assertIsString($png);
        Storage::disk('local')->put($path, $png);

        DB::table('equipamentos_fotos')->insert([
            'equipamento_id' => (int) $order->equipamento_id,
            'arquivo' => $path,
            'is_principal' => true,
            'created_at' => now(),
        ]);

        $descriptor = app(PdfTemplateRegistry::class)->get('os_abertura');
        $context = app(\App\Services\Pdf\Contexts\OrderPdfContextFactory::class)->build(
            ['order' => $order],
            ['image_tokens' => ['foto_equipamento_principal']]
        );
        $schema = [
            'pagina' => ['papel' => 'a4'],
            'cabecalho' => [[
                'tipo' => 'colunas',
                'larguras' => [25, 50, 25],
                'colunas' => [[], [], [[
                    'tipo' => 'imagem',
                    'token' => '((foto_equipamento_principal))',
                    'alinhamento' => 'direita',
                ]]],
            ]],
            'corpo' => [],
            'rodape' => [],
        ];

        $this->assertStringStartsWith('data:image/png;base64,', $context['equipamento']['foto_principal_base64']);

        $html = app(PdfTemplateRenderer::class)->render($schema, $context, $descriptor, 'a4');
        $this->assertStringContainsString('width: 25.0000%', $html);
        $this->assertStringContainsString('width: 50.0000%', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
    }

    public function test_equipment_photo_token_rejects_path_traversal(): void
    {
        Storage::fake('local');
        [$orderId] = $this->buildOrderAndBudgetFixture();
        $order = \App\Models\Order::query()->findOrFail($orderId);

        DB::table('equipamentos_fotos')->insert([
            'equipamento_id' => (int) $order->equipamento_id,
            'arquivo' => '../.env',
            'is_principal' => true,
            'created_at' => now(),
        ]);

        $context = app(\App\Services\Pdf\Contexts\OrderPdfContextFactory::class)->build(
            ['order' => $order],
            ['image_tokens' => ['foto_equipamento_principal']]
        );

        $this->assertSame('', $context['equipamento']['foto_principal_base64']);
    }

    public function test_equipment_photo_is_not_loaded_when_schema_does_not_use_its_token(): void
    {
        Storage::fake('local');
        [$orderId] = $this->buildOrderAndBudgetFixture();
        $order = \App\Models\Order::query()->findOrFail($orderId);

        DB::table('equipamentos_fotos')->insert([
            'equipamento_id' => (int) $order->equipamento_id,
            'arquivo' => 'equipamentos/foto-nao-utilizada.png',
            'is_principal' => true,
            'created_at' => now(),
        ]);

        $context = app(\App\Services\Pdf\Contexts\OrderPdfContextFactory::class)->build(['order' => $order]);

        $this->assertSame('', $context['equipamento']['foto_principal_base64']);
    }

    public function test_variable_resolver_escapes_by_default_and_formats(): void
    {
        $resolver = new PdfVariableResolver();
        $context = [
            'cliente' => ['nome' => '<script>alert(1)</script>', 'telefone' => '11999990000'],
            'os' => ['valor_final' => 1234.5],
        ];

        $resolved = $resolver->resolveText(
            'Cliente: {{ cliente.nome }} — Fone: {{ cliente.telefone | telefone }} — Total: {{ os.valor_final | moeda }}',
            $context
        );

        $this->assertStringNotContainsString('<script>', $resolved);
        $this->assertStringContainsString('&lt;script&gt;', $resolved);
        $this->assertStringContainsString('(11) 99999-0000', $resolved);
        $this->assertStringContainsString('R$ 1.234,50', $resolved);
    }

    /**
     * Espelha o seed da migration 2026_07_18_000011 (o sqlite dos testes não
     * roda as migrations do MySQL legado).
     */
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

    /**
     * @return array{0: int, 1: int}
     */
    private function buildOrderAndBudgetFixture(): array
    {
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente PDF Engine']);
        $equipmentId = $this->createEquipmentRecord($clientId, ['resumo_tecnico' => 'Notebook Teste PDF']);
        $tecnico = $this->createUserRecord(['nome' => 'Técnico PDF', 'perfil' => 'tecnico']);

        $orderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'tecnico_id' => $tecnico->id,
            'numero_os' => 'OS26070777',
            'relato_cliente' => 'Não liga e esquenta muito.',
            'diagnostico_tecnico' => 'Pasta térmica ressecada.',
            'solucao_aplicada' => 'Limpeza completa e troca da pasta térmica.',
            'valor_final' => 150.00,
        ]);

        DB::table('os_itens')->insert([
            [
                'os_id' => $orderId,
                'tipo' => 'servico',
                'descricao' => 'Limpeza interna completa',
                'quantidade' => 1,
                'valor_unitario' => 100.00,
                'valor_total' => 100.00,
            ],
            [
                'os_id' => $orderId,
                'tipo' => 'peca',
                'descricao' => 'Pasta térmica premium',
                'quantidade' => 1,
                'valor_unitario' => 50.00,
                'valor_total' => 50.00,
            ],
        ]);

        // Checklist de entrada preenchido: array_map sobre 'estado_fisico' só
        // roda a closure quando há pelo menos 1 item — sem isso, um bug na
        // closure (ex.: $this fora de contexto) passa despercebido.
        $checklistTypeId = (int) DB::table('checklist_tipos')->insertGetId([
            'codigo' => 'entrada', 'nome' => 'Checklist de Entrada', 'ativo' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $checklistModelId = (int) DB::table('checklist_modelos')->insertGetId([
            'checklist_tipo_id' => $checklistTypeId, 'tipo_equipamento_id' => 1,
            'nome' => 'Checklist notebook', 'ordem' => 1, 'ativo' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $checklistItemId = (int) DB::table('checklist_itens')->insertGetId([
            'checklist_modelo_id' => $checklistModelId, 'descricao' => 'Tela sem trincas', 'ordem' => 1, 'ativo' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $checklistExecucaoId = (int) DB::table('checklist_execucoes')->insertGetId([
            'os_id' => $orderId, 'checklist_tipo_id' => $checklistTypeId, 'checklist_modelo_id' => $checklistModelId,
            'tipo_equipamento_id' => 1, 'status' => 'concluido', 'total_itens' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('checklist_respostas')->insert([
            'checklist_execucao_id' => $checklistExecucaoId, 'checklist_item_id' => $checklistItemId,
            'descricao_item' => 'Tela sem trincas', 'ordem' => 1, 'status' => 'ok',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $budgetId = $this->createBudgetRecord([
            'numero' => 'ORC-2607-000777',
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'os_id' => $orderId,
            'titulo' => 'Orçamento PDF Engine',
            'subtotal' => 150.00,
            'total' => 150.00,
        ]);

        $this->createBudgetItemRecord($budgetId, [
            'descricao' => 'Limpeza interna completa',
            'quantidade' => 1,
            'valor_unitario' => 150.00,
            'total' => 150.00,
        ]);

        return [$orderId, $budgetId];
    }
}
