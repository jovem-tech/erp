<?php

use App\Services\Pdf\PdfDefaultTemplates;
use App\Services\Pdf\PdfTemplateRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Semeia a v1 publicada dos 7 tipos documentais do motor central de PDF.
 *
 * Idempotente: só cria a família se o tipo_codigo ainda não existir (nunca
 * sobrescreve edições feitas depois pelo editor). Todos os 7 tipos usam os
 * blocos estruturados de PdfDefaultTemplates (título de seção, grade de
 * campos, tabela...) — inclusive o os_abertura, que NÃO importa mais o HTML
 * livre do modelo legado (os_pdf_templates codigo='abertura'): um bloco de
 * texto_rico único não herda o tema compartilhado (títulos de seção, faixa,
 * rodapé), então ficaria inconsistente com os outros 6 documentos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pdf_templates') || ! Schema::hasTable('pdf_template_versoes')) {
            return;
        }

        $registry = new PdfTemplateRegistry();
        $now = now();

        foreach (PdfDefaultTemplates::all() as $tipoCodigo => $definition) {
            $exists = DB::table('pdf_templates')->where('tipo_codigo', $tipoCodigo)->exists();
            if ($exists) {
                continue;
            }

            $schema = $definition['schema'];

            $descriptor = $registry->get($tipoCodigo) ?? [];
            $schemaJson = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $templateId = DB::table('pdf_templates')->insertGetId([
                'tipo_codigo' => $tipoCodigo,
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
    }

    public function down(): void
    {
        if (! Schema::hasTable('pdf_templates')) {
            return;
        }

        // Remove apenas famílias ainda na v1 publicada intocada (sem
        // rascunhos/versões adicionais criadas pelo editor).
        foreach (array_keys(PdfDefaultTemplates::all()) as $tipoCodigo) {
            $template = DB::table('pdf_templates')->where('tipo_codigo', $tipoCodigo)->first();
            if ($template === null) {
                continue;
            }

            $versionCount = DB::table('pdf_template_versoes')->where('template_id', $template->id)->count();
            if ($versionCount <= 1) {
                DB::table('pdf_template_versoes')->where('template_id', $template->id)->delete();
                DB::table('pdf_templates')->where('id', $template->id)->delete();
            }
        }
    }
};
