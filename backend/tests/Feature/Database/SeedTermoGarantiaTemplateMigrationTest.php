<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeedTermoGarantiaTemplateMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_publishes_the_approved_warranty_template(): void
    {
        $template = DB::table('pdf_templates')
            ->where('tipo_codigo', 'custom_termo_de_garantia_trt0vfnh')
            ->first();

        $this->assertNotNull($template);
        $this->assertSame('Termo de Garantia', $template->nome);
        $this->assertSame(1, (int) $template->personalizado);
        $this->assertSame('os_encerramento', $template->tipo_base_codigo);

        $version = DB::table('pdf_template_versoes')
            ->where('template_id', $template->id)
            ->where('status', 'publicado')
            ->first();

        $this->assertNotNull($version);
        $this->assertSame(1, (int) $version->versao);
        $this->assertSame(hash('sha256', (string) $version->schema_json), $version->hash_schema);
        $this->assertSame(
            '63682f8f2e8f9f143439dc37b4c9ebe43160e19d16a22d10d545b362bddd4edb',
            $version->hash_schema
        );

        $schema = json_decode((string) $version->schema_json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([25, 50, 25], $schema['cabecalho'][0]['larguras']);
        $this->assertSame('((logo_empresa))', $schema['cabecalho'][0]['colunas'][0][0]['token']);
        $this->assertSame(
            '((foto_equipamento_principal))',
            $schema['cabecalho'][0]['colunas'][2][0]['token']
        );
        $this->assertSame('Termo de Garantia', $schema['corpo'][0]['texto']);
    }

    public function test_migration_is_idempotent_and_preserves_the_existing_family(): void
    {
        $before = DB::table('pdf_templates')
            ->where('tipo_codigo', 'custom_termo_de_garantia_trt0vfnh')
            ->first();
        $this->assertNotNull($before);

        $migration = require database_path(
            'migrations/2026_07_18_000016_seed_termo_garantia_template.php'
        );
        $migration->up();

        $this->assertSame(
            1,
            DB::table('pdf_templates')
                ->where('tipo_codigo', 'custom_termo_de_garantia_trt0vfnh')
                ->count()
        );
        $this->assertSame(
            1,
            DB::table('pdf_template_versoes')->where('template_id', $before->id)->count()
        );
    }
}
