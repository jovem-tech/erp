<?php

use App\Services\Pdf\PdfDefaultTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Publica o tema leve como v2 sem sobrescrever personalizacoes do usuario.
 *
 * Somente a v1 original, identificada pelo hash conhecido do seed, pode ser
 * promovida automaticamente. Familias com rascunho ou versao personalizada
 * ficam intocadas para evitar perda silenciosa de trabalho.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const ORIGINAL_V1_HASHES = [
        'os_abertura' => 'ef8d5941fd13621fb0eaaa5b2d0c6a8903fd8f170fd131296ad751a0b3f19ab3',
        'os_orcamento' => 'e69fc40c00de8ce867eb24ffcfc7ae574e9438b48b27bdd269be5c4c75101439',
        'os_laudo_tecnico' => 'e3ecffcb2a733ba0dd8f7f58c6d2ffe42780f42458582c04b6fbd19eaf36b2ce',
        'os_cobranca_manutencao' => '03041d0781abac57383caaba09303b1e31b046b5036995b6f8ad5197f736bb32',
        'os_comprovante_entrega' => '7dd6c8c6e0dfa5f4a5916cf710bd4a796c6b7a86ce6f9f45e934c342dc118057',
        'os_devolucao_sem_reparo' => '5059cd5f78f110361d53ed8a878ff1a76d24a1b71aca3a3453fca2e367ed8b44',
        'os_encerramento' => 'db272732a25385d7d7a0b1e2b9306e50f84f3b1768bfd614817e8d12294ab3f6',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('pdf_templates') || ! Schema::hasTable('pdf_template_versoes')) {
            return;
        }

        foreach (PdfDefaultTemplates::all() as $tipoCodigo => $definition) {
            DB::transaction(function () use ($tipoCodigo, $definition): void {
                $template = DB::table('pdf_templates')
                    ->where('tipo_codigo', $tipoCodigo)
                    ->lockForUpdate()
                    ->first();

                if ($template === null || (bool) $template->arquivado) {
                    return;
                }

                $hasDraft = DB::table('pdf_template_versoes')
                    ->where('template_id', $template->id)
                    ->where('status', 'rascunho')
                    ->exists();
                $published = DB::table('pdf_template_versoes')
                    ->where('template_id', $template->id)
                    ->where('status', 'publicado')
                    ->orderByDesc('versao')
                    ->first();

                if (
                    $hasDraft
                    || $published === null
                    || (int) $published->versao !== 1
                    || ! hash_equals(self::ORIGINAL_V1_HASHES[$tipoCodigo] ?? '', (string) $published->hash_schema)
                ) {
                    return;
                }

                $schema = $definition['schema'];
                $schemaJson = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $newHash = hash('sha256', $schemaJson);

                if (hash_equals((string) $published->hash_schema, $newHash)) {
                    return;
                }

                $now = now();
                DB::table('pdf_template_versoes')->where('id', $published->id)->update([
                    'status' => 'arquivado',
                    'updated_at' => $now,
                ]);

                DB::table('pdf_template_versoes')->insert([
                    'template_id' => $template->id,
                    'versao' => 2,
                    'status' => 'publicado',
                    'schema_json' => $schemaJson,
                    'papel' => (string) ($schema['pagina']['papel'] ?? 'a4'),
                    'orientacao' => (string) ($schema['pagina']['orientacao'] ?? 'retrato'),
                    'margens_json' => json_encode($schema['pagina']['margens'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'fonte' => (string) ($schema['pagina']['fonte'] ?? 'DejaVu Sans'),
                    'hash_schema' => $newHash,
                    'publicado_em' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('pdf_templates')->where('id', $template->id)->update([
                    'updated_at' => $now,
                ]);
            }, 3);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pdf_templates') || ! Schema::hasTable('pdf_template_versoes')) {
            return;
        }

        foreach (PdfDefaultTemplates::all() as $tipoCodigo => $definition) {
            DB::transaction(function () use ($tipoCodigo, $definition): void {
                $template = DB::table('pdf_templates')
                    ->where('tipo_codigo', $tipoCodigo)
                    ->lockForUpdate()
                    ->first();
                if ($template === null) {
                    return;
                }

                $schemaJson = json_encode($definition['schema'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $expectedHash = hash('sha256', $schemaJson);
                $publishedV2 = DB::table('pdf_template_versoes')
                    ->where('template_id', $template->id)
                    ->where('versao', 2)
                    ->where('status', 'publicado')
                    ->first();

                if ($publishedV2 === null || ! hash_equals($expectedHash, (string) $publishedV2->hash_schema)) {
                    return;
                }

                $now = now();
                DB::table('pdf_template_versoes')->where('id', $publishedV2->id)->delete();
                DB::table('pdf_template_versoes')
                    ->where('template_id', $template->id)
                    ->where('versao', 1)
                    ->where('status', 'arquivado')
                    ->update([
                        'status' => 'publicado',
                        'updated_at' => $now,
                    ]);
                DB::table('pdf_templates')->where('id', $template->id)->update(['updated_at' => $now]);
            }, 3);
        }
    }
};
