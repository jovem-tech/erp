<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sem esta família publicada o motor recusa a geração e o botão Imprimir da
 * OS volta a não abrir documento nenhum.
 */
class SeedOsCompletaTemplateMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TIPO_CODIGO = 'os_completa';

    public function test_migration_publishes_the_full_order_template(): void
    {
        $template = DB::table('pdf_templates')->where('tipo_codigo', self::TIPO_CODIGO)->first();

        $this->assertNotNull($template);
        $this->assertSame('Ordem de serviço completa', $template->nome);
        $this->assertSame(0, (int) $template->arquivado);

        $version = DB::table('pdf_template_versoes')
            ->where('template_id', $template->id)
            ->where('status', 'publicado')
            ->first();

        $this->assertNotNull($version);
        $this->assertSame(1, (int) $version->versao);
        $this->assertSame('a4', $version->papel);
        $this->assertSame(hash('sha256', (string) $version->schema_json), $version->hash_schema);

        $schema = json_decode((string) $version->schema_json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('((logo_empresa))', $schema['cabecalho'][0]['colunas'][0][0]['token']);
        $this->assertSame('((foto_equipamento_principal))', $schema['cabecalho'][0]['colunas'][2][0]['token']);

        $secoes = array_column($schema['corpo'], 'texto');
        $this->assertContains('Dados do atendimento', $secoes);
        $this->assertContains('Itens e serviços', $secoes);
        $this->assertContains('Histórico do atendimento', $secoes);
    }

    public function test_migration_is_idempotent_and_preserves_the_existing_family(): void
    {
        $before = DB::table('pdf_templates')->where('tipo_codigo', self::TIPO_CODIGO)->first();
        $this->assertNotNull($before);

        $migration = require database_path('migrations/2026_09_10_000003_seed_os_completa_template.php');
        $migration->up();

        $this->assertSame(
            1,
            DB::table('pdf_templates')->where('tipo_codigo', self::TIPO_CODIGO)->count()
        );
        $this->assertSame(
            1,
            DB::table('pdf_template_versoes')->where('template_id', $before->id)->count()
        );
    }
}
