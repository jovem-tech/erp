<?php

namespace Tests\Feature\Api\V1;

use App\Services\Pdf\PdfDefaultTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Botão "Visualizar layout" da lista legada de Modelos PDF: renderiza o
 * conteúdo HTML antigo (com tokens {{legado}} convertidos) pelo motor
 * central — nenhuma chamada a dompdf fora de app/Services/Pdf.
 */
class OsPdfTemplateLegacyPreviewTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->seedOrderCatalog();
        $this->grantGroupPermissions(1, ['conhecimento' => ['visualizar']]);

        $this->seedPdfEngineTemplates();
    }

    public function test_preview_renders_pdf_with_simulated_data_for_legacy_type(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $templateId = DB::table('os_pdf_templates')->insertGetId([
            'codigo' => 'laudo',
            'nome' => 'Laudo técnico',
            'conteudo_html' => '<p>OS {{numero_os}} — Diagnóstico: {{relato_cliente}}</p>',
            'ordem' => 20,
            'ativo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/knowledge/pdf-templates/{$templateId}/preview");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_preview_with_real_order_uses_its_data(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $clienteId = $this->createClientRecord(['nome_razao' => 'Cliente Preview Legado']);
        $equipamentoId = $this->createEquipmentRecord($clienteId);
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'numero_os' => 'OS26070999',
            'diagnostico_tecnico' => 'Placa mãe com curto identificado.',
        ]);

        $templateId = DB::table('os_pdf_templates')->insertGetId([
            'codigo' => 'laudo',
            'nome' => 'Laudo técnico',
            'conteudo_html' => '<p>Diagnóstico registrado.</p>',
            'ordem' => 20,
            'ativo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/knowledge/pdf-templates/{$templateId}/preview?entidade_id={$orderId}");

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_preview_returns_422_for_codigo_not_mapped_to_engine_type(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $templateId = DB::table('os_pdf_templates')->insertGetId([
            'codigo' => 'contrato_personalizado_xyz',
            'nome' => 'Contrato personalizado',
            'conteudo_html' => '<p>Conteúdo qualquer.</p>',
            'ordem' => 90,
            'ativo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/knowledge/pdf-templates/{$templateId}/preview");

        $response->assertStatus(422)->assertJsonPath('error.code', 'PDF_TEMPLATE_PREVIEW_UNMAPPED');
    }

    public function test_preview_returns_404_for_missing_template(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/v1/knowledge/pdf-templates/999999/preview')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'PDF_TEMPLATE_NOT_FOUND');
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
}
