<?php

namespace Tests\Feature\Api\V1;

use App\Models\OrderDocument;
use App\Models\OrderDocumentFile;
use App\Services\Orders\OrderDocumentCenterService;
use App\Services\Pdf\PdfDefaultTemplates;
use App\Services\Pdf\PdfTemplateRegistry;
use App\Services\Pdf\PdfTemplateRenderer;
use App\Services\Pdf\Contexts\OrderClosurePdfContextFactory;
use App\Services\Pdf\Contexts\OrderPdfContextFactory;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Arr;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Paridade da Central Documental: todos os tipos renderizam pelo motor
 * central e persistem os formatos A4/80mm com metadados de auditoria. Sem
 * template publicado, a emissão deve ser bloqueada em vez de produzir um
 * PDF visualmente divergente.
 */
class PdfEngineDocumentCenterParityTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->seedOrderCatalog();
        Storage::fake('local');
    }

    public function test_generic_types_generate_via_engine_with_audit_metadata_and_both_formats(): void
    {
        $this->seedPdfEngineTemplates();
        [$actor, $orderId] = $this->buildOrderFixture();

        $service = app(OrderDocumentCenterService::class);

        // devolucao_sem_reparo exige status próprio — usa uma segunda OS.
        $devolucaoOrderId = $this->createSecondOrderWithStatus('devolvido_sem_reparo', 'OS26070889');

        $result = $service->generate($orderId, $actor, ['laudo', 'cobranca_manutencao', 'entrega']);
        $this->assertSame('ok', (string) ($result['result'] ?? ''));

        $devolucaoResult = $service->generate($devolucaoOrderId, $actor, ['devolucao_sem_reparo']);
        $this->assertSame('ok', (string) ($devolucaoResult['result'] ?? ''));

        foreach (array_merge((array) ($result['documents'] ?? []), (array) ($devolucaoResult['documents'] ?? [])) as $docResult) {
            $this->assertTrue(
                (bool) ($docResult['ok'] ?? false),
                sprintf('%s: %s', (string) ($docResult['type'] ?? '?'), (string) ($docResult['message'] ?? ''))
            );
        }

        $expected = [
            'laudo' => $orderId,
            'cobranca_manutencao' => $orderId,
            'entrega' => $orderId,
            'devolucao_sem_reparo' => $devolucaoOrderId,
        ];

        foreach ($expected as $type => $expectedOrderId) {
            $document = OrderDocument::query()
                ->where('os_id', $expectedOrderId)
                ->where('tipo_documento', $type)
                ->orderByDesc('id')
                ->first();

            $this->assertNotNull($document, $type);

            $metadata = is_array($document->metadados_json) ? $document->metadados_json : [];
            $this->assertSame('pdf_engine', (string) ($metadata['motor'] ?? ''), $type . ': deveria ter sido gerado pelo motor central');
            $this->assertSame(1, (int) ($metadata['template_versao'] ?? 0), $type);
            $this->assertNotSame('', (string) ($metadata['hash_schema'] ?? ''), $type);

            foreach (['a4', '80mm'] as $formato) {
                $this->assertTrue(
                    OrderDocumentFile::query()
                        ->where('documento_id', (int) $document->id)
                        ->where('formato', $formato)
                        ->exists(),
                    sprintf('%s deveria ter arquivo %s', $type, $formato)
                );
            }
        }
    }

    public function test_engine_html_preserves_functional_content_of_legacy_documents(): void
    {
        $this->seedPdfEngineTemplates();
        [, $orderId] = $this->buildOrderFixture();

        $order = \App\Models\Order::query()->findOrFail($orderId);
        $registry = app(PdfTemplateRegistry::class);
        $renderer = app(PdfTemplateRenderer::class);
        $factory = app(OrderPdfContextFactory::class);

        $context = $factory->build(['order' => $order]);
        $context['empresa'] = ['nome_fantasia' => 'Empresa Teste', 'razao_social' => '', 'cnpj' => '', 'inscricao_estadual' => '', 'telefone' => '', 'email' => '', 'endereco' => '', 'nome_sistema' => '', 'logo_base64' => ''];
        $context['documento'] = ['nome' => 'Doc', 'codigo' => 'x', 'gerado_em' => now(), 'usuario' => 'Teste', 'versao_template' => 'v1'];

        // Laudo: diagnóstico + solução (paridade com documentNarrative legado).
        $html = $renderer->render(
            PdfDefaultTemplates::all()['os_laudo_tecnico']['schema'],
            $context,
            $registry->get('os_laudo_tecnico'),
            'a4'
        );
        $this->assertStringContainsString('Fonte queimada no conector', $html);
        $this->assertStringContainsString('Troca da fonte interna', $html);

        // Cobrança: valor final consolidado + forma de pagamento.
        $html = $renderer->render(
            PdfDefaultTemplates::all()['os_cobranca_manutencao']['schema'],
            $context,
            $registry->get('os_cobranca_manutencao'),
            'a4'
        );
        $this->assertStringContainsString('R$&nbsp;350,00', str_replace('R$ ', 'R$&nbsp;', $html));
        $this->assertStringContainsString('Cliente Paridade', $html);

        // Devolução: justificativa/diagnóstico presente.
        $html = $renderer->render(
            PdfDefaultTemplates::all()['os_devolucao_sem_reparo']['schema'],
            $context,
            $registry->get('os_devolucao_sem_reparo'),
            '80mm'
        );
        $this->assertStringContainsString('Fonte queimada no conector', $html);

        // Parágrafos mantêm quebras de linha sem transformar conteúdo colado em HTML executável.
        $paragraphSchema = PdfDefaultTemplates::all()['os_laudo_tecnico']['schema'];
        $paragraphSchema['corpo'] = [[
            'tipo' => 'paragrafo',
            'texto' => "Primeira linha\nSegunda linha <script>alert('xss')</script>",
        ]];
        $html = $renderer->render(
            $paragraphSchema,
            $context,
            $registry->get('os_laudo_tecnico'),
            'a4'
        );
        $this->assertStringContainsString("Primeira linha<br>\nSegunda linha", $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_every_declared_closure_variable_exists_and_matches_its_type(): void
    {
        [, $orderId] = $this->buildOrderFixture();
        $order = \App\Models\Order::query()->findOrFail($orderId);
        $order->forceFill(['data_entrega' => null])->save();

        $context = app(OrderClosurePdfContextFactory::class)->build(['order' => $order], [
            'status_final_nome' => 'Entregue',
            'data_entrega' => '18/07/2026',
            'observacao_encerramento' => 'Retirado na loja.',
            'valor_titulo' => 350.0,
            'saldo_restante' => 0.0,
            'recebimentos' => [],
        ]);
        $context['empresa'] = [
            'nome_sistema' => 'Jovem Tech OS',
            'razao_social' => '',
            'nome_fantasia' => 'Jovem Tech OS',
            'cnpj' => '',
            'inscricao_estadual' => '',
            'telefone' => '',
            'email' => '',
            'endereco' => '',
        ];
        $context['documento'] = [
            'nome' => 'Comprovante de encerramento',
            'codigo' => 'os_encerramento',
            'gerado_em' => now(),
            'usuario' => 'Teste',
            'versao_template' => 'v1',
        ];

        $descriptor = app(PdfTemplateRegistry::class)->get('os_encerramento');
        $missing = [];
        $incompatible = [];

        foreach (($descriptor['variables'] ?? []) as $path => $type) {
            if (! Arr::has($context, $path)) {
                $missing[] = $path;
                continue;
            }

            $value = Arr::get($context, $path);
            $compatible = match ($type) {
                'moeda' => is_int($value) || is_float($value),
                'inteiro' => $value === null || is_int($value),
                'data', 'data_hora' => $value === null || is_string($value) || $value instanceof DateTimeInterface,
                default => is_string($value),
            };

            if (! $compatible) {
                $incompatible[] = $path . ' (' . $type . ' / ' . get_debug_type($value) . ')';
            }
        }

        $this->assertSame([], $missing, 'Variáveis declaradas sem origem no contexto: ' . implode(', ', $missing));
        $this->assertSame([], $incompatible, 'Variáveis com tipo incompatível: ' . implode(', ', $incompatible));
        $this->assertNull($context['os']['data_entrega'], 'Data de entrega não pode usar a previsão como fallback.');
        $this->assertSame('18/07/2026', $context['encerramento']['data_entrega']);
    }

    public function test_opening_document_generates_via_engine_with_audit_metadata(): void
    {
        $this->seedPdfEngineTemplates();
        [$actor, $orderId] = $this->buildOrderFixture();

        $order = \App\Models\Order::query()->findOrFail($orderId);
        $result = app(\App\Services\Orders\OrderOpeningPdfService::class)->generate($order, $actor);

        $this->assertTrue((bool) ($result['ok'] ?? false), (string) ($result['message'] ?? ''));

        $document = OrderDocument::query()->findOrFail((int) $result['document_id']);
        $metadata = is_array($document->metadados_json) ? $document->metadados_json : [];

        $this->assertSame('pdf_engine', (string) ($metadata['motor'] ?? ''));
        $this->assertSame('os_abertura', (string) ($metadata['tipo_codigo'] ?? ''));
        $this->assertSame(1, (int) ($metadata['template_versao'] ?? 0));

        foreach (['a4', '80mm'] as $formato) {
            $this->assertTrue(
                OrderDocumentFile::query()
                    ->where('documento_id', (int) $document->id)
                    ->where('formato', $formato)
                    ->exists(),
                'A abertura deveria arquivar o formato ' . $formato
            );
        }
    }

    public function test_closure_pdf_generates_via_engine_and_now_persists_into_archive(): void
    {
        $this->seedPdfEngineTemplates();
        [, $orderId] = $this->buildOrderFixture();

        $order = \App\Models\Order::query()->findOrFail($orderId);
        $result = app(\App\Services\Orders\OrderClosurePdfService::class)->generate($order, [
            'numeroOs' => (string) $order->numero_os,
            'statusFinalNome' => 'Entregue - Reparado e Pago',
            'dataEntrega' => '18/07/2026',
            'observacaoEncerramento' => 'Retirado na loja.',
            'valorFinal' => 350.0,
            'valorTitulo' => 350.0,
            'saldoRestante' => 0.0,
            'recebimentos' => [
                ['forma_pagamento' => 'pix', 'valor' => 350.0, 'data_pagamento' => '18/07/2026'],
            ],
        ]);

        $this->assertTrue((bool) ($result['ok'] ?? false), (string) ($result['message'] ?? ''));
        $this->assertFileExists((string) ($result['path'] ?? ''));
        $this->assertGreaterThan(0, (int) ($result['document_id'] ?? 0), 'O encerramento agora deve entrar no acervo documental');

        $document = OrderDocument::query()->findOrFail((int) $result['document_id']);
        $this->assertSame('encerramento', (string) $document->tipo_documento);

        $metadata = is_array($document->metadados_json) ? $document->metadados_json : [];
        $this->assertSame('pdf_engine', (string) ($metadata['motor'] ?? ''));
        $this->assertSame('os_encerramento', (string) ($metadata['tipo_codigo'] ?? ''));

        foreach (['a4', '80mm'] as $formato) {
            $this->assertTrue(
                OrderDocumentFile::query()
                    ->where('documento_id', (int) $document->id)
                    ->where('formato', $formato)
                    ->exists(),
                'O encerramento deveria arquivar o formato ' . $formato
            );
        }

        @unlink((string) ($result['path'] ?? ''));
    }

    public function test_without_published_templates_generation_is_blocked_without_archiving_a_document(): void
    {
        // Nenhum template publicado: não pode existir fallback visual oculto.
        [$actor, $orderId] = $this->buildOrderFixture();

        $service = app(OrderDocumentCenterService::class);
        $result = $service->generate($orderId, $actor, ['laudo']);

        $this->assertSame('ok', (string) ($result['result'] ?? ''), json_encode($result));
        $this->assertFalse((bool) ($result['documents'][0]['ok'] ?? true));
        $this->assertStringContainsString('template publicado', (string) ($result['documents'][0]['message'] ?? ''));

        $document = OrderDocument::query()
            ->where('os_id', $orderId)
            ->where('tipo_documento', 'laudo')
            ->orderByDesc('id')
            ->first();

        $this->assertNull($document, 'Falha de template não pode criar documento sem auditoria do motor central.');
    }

    public function test_published_custom_document_appears_in_catalog_and_generates_both_formats(): void
    {
        $this->seedPdfEngineTemplates();
        [$actor, $orderId] = $this->buildOrderFixture();
        $now = now();
        $schema = PdfDefaultTemplates::all()['os_laudo_tecnico']['schema'];
        $schema['corpo'][0]['texto'] = 'Relatório personalizado';
        $schemaJson = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $templateId = DB::table('pdf_templates')->insertGetId([
            'tipo_codigo' => 'custom_relatorio_seguradora',
            'nome' => 'Relatório para seguradora',
            'descricao' => 'Documento manual personalizado.',
            'arquivado' => false,
            'personalizado' => true,
            'tipo_base_codigo' => 'os_laudo_tecnico',
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

        $service = app(OrderDocumentCenterService::class);
        $catalog = $service->catalog($orderId, $actor);
        $custom = collect($catalog['catalog'] ?? [])->firstWhere('type', 'custom_relatorio_seguradora');
        $this->assertNotNull($custom);
        $this->assertSame('Relatório para seguradora', $custom['label']);
        $this->assertTrue((bool) $custom['can_generate']);
        $this->assertSame([], $custom['automatic_triggers']);

        $result = $service->generate($orderId, $actor, ['custom_relatorio_seguradora']);
        $this->assertTrue((bool) ($result['documents'][0]['ok'] ?? false), json_encode($result));

        $document = OrderDocument::query()
            ->where('os_id', $orderId)
            ->where('tipo_documento', 'custom_relatorio_seguradora')
            ->firstOrFail();
        $metadata = is_array($document->metadados_json) ? $document->metadados_json : [];
        $this->assertSame('custom_relatorio_seguradora', $metadata['tipo_codigo'] ?? null);

        foreach (['a4', '80mm'] as $formato) {
            $this->assertTrue(OrderDocumentFile::query()
                ->where('documento_id', (int) $document->id)
                ->where('formato', $formato)
                ->exists());
        }
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

    /**
     * @return array{0: \App\Models\User, 1: int}
     */
    private function buildOrderFixture(): array
    {
        $actor = $this->createUserRecord(['grupo_id' => 1]);
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Paridade']);
        $equipmentId = $this->createEquipmentRecord($clientId, ['resumo_tecnico' => 'Console Paridade']);

        // Status de encerramento "entregue" libera os pré-requisitos de
        // entrega; devolução é testada à parte no mesmo fluxo (o precondition
        // de devolucao_sem_reparo exige exatamente esse status, então o teste
        // principal usa os tipos compatíveis com um único status).
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'numero_os' => 'OS26070888',
            'status' => 'entregue_reparado_pago',
            'estado_fluxo' => 'encerrado',
            'relato_cliente' => 'Aparelho desliga sozinho.',
            'diagnostico_tecnico' => 'Fonte queimada no conector.',
            'solucao_aplicada' => 'Troca da fonte interna.',
            'valor_final' => 350.00,
        ]);

        return [$actor, $orderId];
    }

    private function createSecondOrderWithStatus(string $status, string $numeroOs): int
    {
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Devolução']);
        $equipmentId = $this->createEquipmentRecord($clientId);

        return $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'numero_os' => $numeroOs,
            'status' => $status,
            'estado_fluxo' => 'encerrado',
            'diagnostico_tecnico' => 'Reparo inviável economicamente.',
            'valor_final' => 0,
        ]);
    }
}
