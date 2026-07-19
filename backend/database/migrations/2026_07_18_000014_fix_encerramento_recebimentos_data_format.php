<?php

use App\Services\Pdf\PdfDefaultTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrige a coluna "Data" da tabela de recebimentos do comprovante de
 * encerramento (os_encerramento): faltava 'formato' => 'data' na definicao
 * do bloco, entao a coluna imprimia a string bruta vinda do banco em vez de
 * dd/mm/aaaa. Mesmo padrao de promocao segura de
 * 2026_07_18_000013_publish_light_pdf_templates_v2: so promove a familia se
 * a versao publicada ainda for exatamente a v2 original (hash conhecido) e
 * nao houver rascunho em edicao, para nao sobrescrever customizacao manual.
 */
return new class extends Migration
{
    private const TIPO_CODIGO = 'os_encerramento';

    private const ORIGINAL_V2_HASH = '214b043c1011a2745d5ca4e12c368bb9f4cb544e37468b40ff890a0dcede3ad2';

    public function up(): void
    {
        if (! Schema::hasTable('pdf_templates') || ! Schema::hasTable('pdf_template_versoes')) {
            return;
        }

        $schema = PdfDefaultTemplates::all()[self::TIPO_CODIGO]['schema'] ?? null;
        if ($schema === null) {
            return;
        }

        DB::transaction(function () use ($schema): void {
            $template = DB::table('pdf_templates')
                ->where('tipo_codigo', self::TIPO_CODIGO)
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
                || ! hash_equals(self::ORIGINAL_V2_HASH, (string) $published->hash_schema)
            ) {
                return;
            }

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
                'versao' => (int) $published->versao + 1,
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

    public function down(): void
    {
        // Intencionalmente sem rollback: reverter reimprimiria a coluna
        // "Data" sem formatacao. Se necessario, restaure a versao anterior
        // manualmente pela tela de versoes do motor (Restaurar).
    }
};
