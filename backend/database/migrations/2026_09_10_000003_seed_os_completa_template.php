<?php

use App\Services\Pdf\PdfDefaultTemplates;
use App\Services\Pdf\PdfTemplateRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Semeia a v1 publicada da "Ordem de serviço completa" (os_completa), o
 * documento do botão Imprimir da tela da OS.
 *
 * Sem uma versão publicada o motor recusa a geração ("Nenhum template
 * publicado para o tipo os_completa") e o botão volta a não imprimir nada.
 *
 * Idempotente: só cria se o tipo_codigo ainda não existir — nunca sobrescreve
 * o que o usuário tiver editado depois na tela Modelos PDF.
 */
return new class extends Migration
{
    private const TIPO_CODIGO = 'os_completa';

    public function up(): void
    {
        if (! Schema::hasTable('pdf_templates') || ! Schema::hasTable('pdf_template_versoes')) {
            return;
        }

        if (DB::table('pdf_templates')->where('tipo_codigo', self::TIPO_CODIGO)->exists()) {
            return;
        }

        $definition = PdfDefaultTemplates::all()[self::TIPO_CODIGO] ?? null;

        if ($definition === null) {
            return;
        }

        $schema = $definition['schema'];
        $descriptor = (new PdfTemplateRegistry())->get(self::TIPO_CODIGO) ?? [];
        $schemaJson = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = now();

        $templateId = DB::table('pdf_templates')->insertGetId([
            'tipo_codigo' => self::TIPO_CODIGO,
            'nome' => (string) $definition['nome'],
            'descricao' => (string) ($descriptor['descricao'] ?? ''),
            'arquivado' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('pdf_template_versoes')->insert([
            'template_id' => $templateId,
            'versao' => 1,
            'status' => 'publicado',
            'schema_json' => $schemaJson,
            'papel' => (string) ($schema['pagina']['papel'] ?? 'a4'),
            'orientacao' => (string) ($schema['pagina']['orientacao'] ?? 'retrato'),
            'margens_json' => json_encode($schema['pagina']['margens'] ?? [], JSON_UNESCAPED_UNICODE),
            'fonte' => (string) ($schema['pagina']['fonte'] ?? 'DejaVu Sans'),
            'hash_schema' => hash('sha256', (string) $schemaJson),
            'publicado_em' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('pdf_templates')) {
            return;
        }

        $template = DB::table('pdf_templates')->where('tipo_codigo', self::TIPO_CODIGO)->first();

        if ($template === null) {
            return;
        }

        if (DB::table('pdf_template_versoes')->where('template_id', $template->id)->count() <= 1) {
            DB::table('pdf_template_versoes')->where('template_id', $template->id)->delete();
            DB::table('pdf_templates')->where('id', $template->id)->delete();
        }
    }
};
