<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AddClientToPdfSignatureBlocksMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_versions_a_published_technical_signature(): void
    {
        $template = DB::table('pdf_templates')->where('tipo_codigo', 'os_laudo_tecnico')->first();
        $this->assertNotNull($template);

        $published = DB::table('pdf_template_versoes')
            ->where('template_id', $template->id)
            ->where('status', 'publicado')
            ->first();
        $this->assertNotNull($published);

        $legacyJson = $this->schemaWithTechnicalSignatureOnly((string) $published->schema_json);
        DB::table('pdf_template_versoes')->where('id', $published->id)->update([
            'schema_json' => $legacyJson,
            'hash_schema' => hash('sha256', $legacyJson),
        ]);

        $migration = require database_path(
            'migrations/2026_07_19_000001_add_client_to_pdf_signature_blocks.php'
        );
        $migration->up();

        $newPublished = DB::table('pdf_template_versoes')
            ->where('template_id', $template->id)
            ->where('status', 'publicado')
            ->first();
        $this->assertNotNull($newPublished);
        $this->assertSame((int) $published->versao + 1, (int) $newPublished->versao);
        $this->assertDatabaseHas('pdf_template_versoes', [
            'id' => $published->id,
            'status' => 'arquivado',
        ]);
        $this->assertSame([
            '{{ os.tecnico_nome }} - Técnico responsável',
            '{{ cliente.nome }} - Cliente',
        ], $this->signatureLabels((string) $newPublished->schema_json));

        $versionCount = DB::table('pdf_template_versoes')->where('template_id', $template->id)->count();
        $migration->up();
        $this->assertSame(
            $versionCount,
            DB::table('pdf_template_versoes')->where('template_id', $template->id)->count()
        );
    }

    public function test_migration_updates_the_draft_without_publishing_other_edits(): void
    {
        $template = DB::table('pdf_templates')->where('tipo_codigo', 'os_laudo_tecnico')->first();
        $this->assertNotNull($template);

        $published = DB::table('pdf_template_versoes')
            ->where('template_id', $template->id)
            ->where('status', 'publicado')
            ->first();
        $this->assertNotNull($published);

        $draftJson = $this->schemaWithTechnicalSignatureOnly((string) $published->schema_json);
        $draftSchema = json_decode($draftJson, true, 512, JSON_THROW_ON_ERROR);
        $draftSchema['corpo'][] = ['tipo' => 'paragrafo', 'texto' => 'Edição ainda não publicada.'];
        $draftJson = json_encode(
            $draftSchema,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $draftId = DB::table('pdf_template_versoes')->insertGetId([
            'template_id' => $template->id,
            'versao' => (int) $published->versao + 1,
            'status' => 'rascunho',
            'schema_json' => $draftJson,
            'papel' => $published->papel,
            'orientacao' => $published->orientacao,
            'margens_json' => $published->margens_json,
            'fonte' => $published->fonte,
            'hash_schema' => hash('sha256', $draftJson),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path(
            'migrations/2026_07_19_000001_add_client_to_pdf_signature_blocks.php'
        );
        $migration->up();

        $draft = DB::table('pdf_template_versoes')->where('id', $draftId)->first();
        $this->assertNotNull($draft);
        $this->assertSame('rascunho', $draft->status);
        $this->assertSame([
            '{{ os.tecnico_nome }} - Técnico responsável',
            '{{ cliente.nome }} - Cliente',
        ], $this->signatureLabels((string) $draft->schema_json));
        $this->assertStringContainsString('Edição ainda não publicada.', (string) $draft->schema_json);
        $this->assertDatabaseHas('pdf_template_versoes', [
            'id' => $published->id,
            'status' => 'publicado',
        ]);
    }

    private function schemaWithTechnicalSignatureOnly(string $schemaJson): string
    {
        $schema = json_decode($schemaJson, true, 512, JSON_THROW_ON_ERROR);
        foreach ($schema['corpo'] as &$block) {
            if (($block['tipo'] ?? null) === 'assinatura') {
                $block['rotulos'] = ['{{ os.tecnico_nome }} - Técnico responsável'];
            }
        }
        unset($block);

        return json_encode(
            $schema,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /** @return array<int, string> */
    private function signatureLabels(string $schemaJson): array
    {
        $schema = json_decode($schemaJson, true, 512, JSON_THROW_ON_ERROR);
        foreach ($schema['corpo'] as $block) {
            if (($block['tipo'] ?? null) === 'assinatura') {
                return $block['rotulos'] ?? [];
            }
        }

        return [];
    }
}
