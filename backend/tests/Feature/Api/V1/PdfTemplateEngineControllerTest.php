<?php

namespace Tests\Feature\Api\V1;

use App\Services\Pdf\PdfDefaultTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class PdfTemplateEngineControllerTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->seedOrderCatalog();

        // Novos slugs do motor (em produção: migration 2026_07_18_000012).
        DB::table('permissoes')->insert([
            ['id' => 8, 'nome' => 'Publicar', 'slug' => 'publicar'],
            ['id' => 9, 'nome' => 'Restaurar', 'slug' => 'restaurar'],
        ]);

        $this->seedPdfEngineTemplates();
    }

    public function test_types_endpoint_lists_registered_types_with_published_versions(): void
    {
        $this->actingWithPermissions(['visualizar']);

        $response = $this->getJson('/api/v1/knowledge/pdf-engine/types');

        $response->assertOk()->assertJsonCount(7, 'data.tipos');

        $abertura = collect($response->json('data.tipos'))->firstWhere('tipo_codigo', 'os_abertura');
        $this->assertNotNull($abertura);
        $this->assertSame(1, (int) $abertura['versao_publicada']);
        $this->assertFalse((bool) $abertura['tem_rascunho']);
        $this->assertNotNull($abertura['template_id']);
    }

    public function test_type_variables_endpoint_exposes_allowlist_and_block_catalog(): void
    {
        $this->actingWithPermissions(['visualizar']);

        $response = $this->getJson('/api/v1/knowledge/pdf-engine/types/os_laudo_tecnico/variables');

        $response->assertOk();
        $variaveis = collect($response->json('data.tipo.variaveis'))->pluck('caminho');
        $this->assertTrue($variaveis->contains('os.diagnostico_tecnico'));
        $this->assertTrue($variaveis->contains('empresa.nome_fantasia'));
        $this->assertContains('tabela', $response->json('data.tipo.blocos'));
        $this->assertContains('moeda', $response->json('data.tipo.formatadores'));
        $this->assertContains('foto_equipamento_principal', $response->json('data.tipo.tokens_imagem'));
    }

    public function test_draft_save_honors_optimistic_concurrency_with_409(): void
    {
        $this->actingWithPermissions(['visualizar', 'editar']);
        $templateId = $this->templateIdFor('os_laudo_tecnico');
        $schema = PdfDefaultTemplates::all()['os_laudo_tecnico']['schema'];

        // Sem rascunho ainda: updated_at nulo cria o rascunho.
        $first = $this->putJson("/api/v1/knowledge/pdf-engine/templates/{$templateId}/draft", [
            'schema' => $schema,
        ]);
        $first->assertOk();
        $draftStamp = (string) $first->json('data.rascunho.updated_at');
        $this->assertNotSame('', $draftStamp);

        // Carimbo errado (edição concorrente) → 409.
        $this->putJson("/api/v1/knowledge/pdf-engine/templates/{$templateId}/draft", [
            'schema' => $schema,
            'updated_at' => '2020-01-01 00:00:00',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PDF_ENGINE_DRAFT_STALE');

        // Carimbo correto → salva.
        $this->putJson("/api/v1/knowledge/pdf-engine/templates/{$templateId}/draft", [
            'schema' => $schema,
            'updated_at' => $draftStamp,
        ])->assertOk();
    }

    public function test_editor_can_create_blank_document_with_allowlisted_data_source(): void
    {
        $this->actingWithPermissions(['visualizar', 'editar']);

        $response = $this->postJson('/api/v1/knowledge/pdf-engine/templates', [
            'nome' => 'Termo de garantia especial',
            'descricao' => 'Documento manual entregue após o reparo.',
            'tipo_base_codigo' => 'os_encerramento',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.template.nome', 'Termo de garantia especial')
            ->assertJsonPath('data.template.personalizado', true)
            ->assertJsonPath('data.template.tipo_base_codigo', 'os_encerramento')
            ->assertJsonPath('data.template.rascunho.versao', 1)
            ->assertJsonPath('data.template.rascunho.status', 'rascunho');

        $codigo = (string) $response->json('data.template.tipo_codigo');
        $this->assertStringStartsWith('custom_termo_de_garantia_especial', $codigo);
        $header = $response->json('data.template.rascunho.schema.cabecalho.0');
        $this->assertSame([25, 50, 25], $header['larguras']);
        $this->assertCount(3, $header['colunas']);
        $this->assertSame('((logo_empresa))', $header['colunas'][0][0]['token']);
        $this->assertSame('((foto_equipamento_principal))', $header['colunas'][2][0]['token']);
        $this->assertDatabaseHas('pdf_templates', [
            'tipo_codigo' => $codigo,
            'personalizado' => true,
            'tipo_base_codigo' => 'os_encerramento',
        ]);

        $this->getJson('/api/v1/knowledge/pdf-engine/types/' . $codigo . '/variables')
            ->assertOk()
            ->assertJsonPath('data.tipo.tipo_codigo', $codigo);
    }

    public function test_editor_can_clone_document_into_independent_custom_family(): void
    {
        $this->actingWithPermissions(['visualizar', 'editar']);
        $sourceId = $this->templateIdFor('os_laudo_tecnico');
        $sourceSchema = (string) DB::table('pdf_template_versoes')
            ->where('template_id', $sourceId)
            ->where('versao', 1)
            ->value('schema_json');

        $response = $this->postJson("/api/v1/knowledge/pdf-engine/templates/{$sourceId}/clone", [
            'nome' => 'Laudo de seguradora',
            'descricao' => 'Cópia com nomenclatura própria.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.template.nome', 'Laudo de seguradora')
            ->assertJsonPath('data.template.tipo_base_codigo', 'os_laudo_tecnico')
            ->assertJsonPath('data.template.origem_template_id', $sourceId);

        $copyId = (int) $response->json('data.template.id');
        $this->assertNotSame($sourceId, $copyId);
        $this->assertSame(
            json_decode($sourceSchema, true),
            json_decode((string) DB::table('pdf_template_versoes')->where('template_id', $copyId)->value('schema_json'), true)
        );
        $this->assertDatabaseHas('pdf_templates', ['id' => $sourceId, 'personalizado' => false]);
    }

    public function test_clone_replaces_legacy_header_without_changing_document_content(): void
    {
        $this->actingWithPermissions(['visualizar', 'editar']);
        $sourceId = $this->templateIdFor('os_laudo_tecnico');
        $version = DB::table('pdf_template_versoes')
            ->where('template_id', $sourceId)
            ->where('status', 'publicado')
            ->first();
        $this->assertNotNull($version);

        $sourceSchema = json_decode((string) $version->schema_json, true, 512, JSON_THROW_ON_ERROR);
        $sourceSchema['cabecalho'][0] = [
            'tipo' => 'colunas',
            'visivel_em' => ['a4'],
            'colunas' => [
                [['tipo' => 'imagem', 'token' => '((logo_empresa))']],
                [['tipo' => 'paragrafo', 'texto' => 'Cabeçalho legado']],
            ],
        ];
        $sourceSchema['corpo'][] = ['tipo' => 'paragrafo', 'texto' => 'Conteúdo que deve ser preservado.'];
        $sourceJson = json_encode($sourceSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        DB::table('pdf_template_versoes')->where('id', $version->id)->update([
            'schema_json' => $sourceJson,
            'hash_schema' => hash('sha256', $sourceJson),
        ]);

        $response = $this->postJson("/api/v1/knowledge/pdf-engine/templates/{$sourceId}/clone", [
            'nome' => 'Laudo com cabeçalho normalizado',
        ])->assertCreated();

        $copySchema = $response->json('data.template.rascunho.schema');
        $this->assertSame($sourceSchema['corpo'], $copySchema['corpo']);
        $this->assertSame($sourceSchema['rodape'], $copySchema['rodape']);
        $this->assertSame(array_slice($sourceSchema['cabecalho'], 1), array_slice($copySchema['cabecalho'], 1));
        $this->assertSame([25, 50, 25], $copySchema['cabecalho'][0]['larguras']);
        $this->assertCount(3, $copySchema['cabecalho'][0]['colunas']);
        $this->assertSame(
            '((foto_equipamento_principal))',
            $copySchema['cabecalho'][0]['colunas'][2][0]['token']
        );

        $persistedSource = json_decode(
            (string) DB::table('pdf_template_versoes')->where('id', $version->id)->value('schema_json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertCount(2, $persistedSource['cabecalho'][0]['colunas']);
    }

    public function test_header_data_migration_versions_published_templates_and_is_idempotent(): void
    {
        $templateId = $this->templateIdFor('os_orcamento');
        $published = DB::table('pdf_template_versoes')
            ->where('template_id', $templateId)
            ->where('status', 'publicado')
            ->first();
        $this->assertNotNull($published);

        $legacySchema = json_decode((string) $published->schema_json, true, 512, JSON_THROW_ON_ERROR);
        $legacySchema['cabecalho'][0] = [
            'tipo' => 'colunas',
            'visivel_em' => ['a4'],
            'colunas' => [
                [['tipo' => 'imagem', 'token' => '((logo_empresa))']],
                [['tipo' => 'paragrafo', 'texto' => 'Dados antigos']],
            ],
        ];
        $legacyJson = json_encode($legacySchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        DB::table('pdf_template_versoes')->where('id', $published->id)->update([
            'schema_json' => $legacyJson,
            'hash_schema' => hash('sha256', $legacyJson),
        ]);

        $migration = require database_path('migrations/2026_07_18_000015_standardize_pdf_template_headers.php');
        $migration->up();

        $current = DB::table('pdf_template_versoes')
            ->where('template_id', $templateId)
            ->where('status', 'publicado')
            ->first();
        $this->assertNotNull($current);
        $this->assertSame((int) $published->versao + 1, (int) $current->versao);
        $this->assertDatabaseHas('pdf_template_versoes', [
            'id' => $published->id,
            'status' => 'arquivado',
        ]);

        $currentSchema = json_decode((string) $current->schema_json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($legacySchema['corpo'], $currentSchema['corpo']);
        $this->assertSame($legacySchema['rodape'], $currentSchema['rodape']);
        $this->assertSame([25, 50, 25], $currentSchema['cabecalho'][0]['larguras']);
        $this->assertCount(3, $currentSchema['cabecalho'][0]['colunas']);

        $versionCount = DB::table('pdf_template_versoes')->where('template_id', $templateId)->count();
        $migration->up();
        $this->assertSame(
            $versionCount,
            DB::table('pdf_template_versoes')->where('template_id', $templateId)->count()
        );
    }

    public function test_create_document_requires_edit_permission(): void
    {
        $this->actingWithPermissions(['visualizar']);

        $this->postJson('/api/v1/knowledge/pdf-engine/templates', [
            'nome' => 'Documento sem permissão',
            'tipo_base_codigo' => 'os_abertura',
        ])->assertForbidden();
    }

    public function test_publish_blocks_unknown_variable_with_422_and_error_list(): void
    {
        $this->actingWithPermissions(['visualizar', 'editar', 'publicar']);
        $templateId = $this->templateIdFor('os_laudo_tecnico');

        $schema = PdfDefaultTemplates::all()['os_laudo_tecnico']['schema'];
        $schema['corpo'][] = ['tipo' => 'paragrafo', 'texto' => '{{ os.variavel_que_nao_existe }}'];

        $this->putJson("/api/v1/knowledge/pdf-engine/templates/{$templateId}/draft", ['schema' => $schema])->assertOk();

        $response = $this->postJson("/api/v1/knowledge/pdf-engine/templates/{$templateId}/publish");

        $response->assertStatus(422)->assertJsonPath('error.code', 'PDF_ENGINE_SCHEMA_INVALID');
        $erros = (array) $response->json('error.details.erros');
        $this->assertTrue(
            collect($erros)->contains(fn (string $erro): bool => str_contains($erro, 'os.variavel_que_nao_existe')),
            implode(' | ', $erros)
        );
    }

    public function test_publish_promotes_draft_and_archives_previous_version(): void
    {
        $this->actingWithPermissions(['visualizar', 'editar', 'publicar']);
        $templateId = $this->templateIdFor('os_laudo_tecnico');

        $schema = PdfDefaultTemplates::all()['os_laudo_tecnico']['schema'];
        $schema['corpo'][] = ['tipo' => 'paragrafo', 'texto' => 'Bloco novo da v2.'];

        $this->putJson("/api/v1/knowledge/pdf-engine/templates/{$templateId}/draft", ['schema' => $schema])->assertOk();
        $this->postJson("/api/v1/knowledge/pdf-engine/templates/{$templateId}/publish")
            ->assertOk()
            ->assertJsonPath('data.versao_publicada.versao', 2)
            ->assertJsonPath('data.versao_publicada.status', 'publicado');

        $this->assertDatabaseHas('pdf_template_versoes', ['template_id' => $templateId, 'versao' => 1, 'status' => 'arquivado']);
        $this->assertDatabaseHas('pdf_template_versoes', ['template_id' => $templateId, 'versao' => 2, 'status' => 'publicado']);
    }

    public function test_restore_creates_new_draft_and_conflicts_when_draft_exists(): void
    {
        $this->actingWithPermissions(['visualizar', 'editar', 'publicar', 'restaurar']);
        $templateId = $this->templateIdFor('os_laudo_tecnico');

        $this->postJson("/api/v1/knowledge/pdf-engine/templates/{$templateId}/versions/1/restore")
            ->assertOk()
            ->assertJsonPath('data.origem_versao', 1)
            ->assertJsonPath('data.rascunho.status', 'rascunho')
            ->assertJsonPath('data.rascunho.versao', 2);

        $this->postJson("/api/v1/knowledge/pdf-engine/templates/{$templateId}/versions/1/restore")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PDF_ENGINE_DRAFT_EXISTS');
    }

    public function test_preview_streams_pdf_with_simulated_data(): void
    {
        $this->actingWithPermissions(['visualizar']);
        $templateId = $this->templateIdFor('os_orcamento');

        $response = $this->postJson("/api/v1/knowledge/pdf-engine/templates/{$templateId}/preview", [
            'formato' => 'a4',
        ]);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_publish_requires_publicar_permission(): void
    {
        $this->actingWithPermissions(['visualizar', 'editar']);
        $templateId = $this->templateIdFor('os_laudo_tecnico');

        $this->putJson("/api/v1/knowledge/pdf-engine/templates/{$templateId}/draft", [
            'schema' => PdfDefaultTemplates::all()['os_laudo_tecnico']['schema'],
        ])->assertOk();

        $this->postJson("/api/v1/knowledge/pdf-engine/templates/{$templateId}/publish")->assertForbidden();
    }

    private function actingWithPermissions(array $actions): void
    {
        $this->grantGroupPermissions(1, ['conhecimento' => $actions]);
        $user = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($user, ['*']);
    }

    private function templateIdFor(string $tipoCodigo): int
    {
        return (int) DB::table('pdf_templates')->where('tipo_codigo', $tipoCodigo)->value('id');
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
