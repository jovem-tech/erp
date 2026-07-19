<?php

use App\Services\Pdf\PdfDefaultTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Padroniza o cabeçalho institucional A4 de todas as famílias ativas.
 *
 * Versões publicadas permanecem imutáveis: a migration arquiva a versão
 * anterior e publica uma nova cópia com somente o cabeçalho alterado.
 * Rascunhos, que são mutáveis por definição, são ajustados no próprio registro
 * para não publicar silenciosamente outras edições ainda em andamento.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pdf_templates') || ! Schema::hasTable('pdf_template_versoes')) {
            return;
        }

        $templateIds = DB::table('pdf_templates')
            ->where('arquivado', false)
            ->orderBy('id')
            ->pluck('id');

        foreach ($templateIds as $templateId) {
            DB::transaction(function () use ($templateId): void {
                $template = DB::table('pdf_templates')
                    ->where('id', $templateId)
                    ->where('arquivado', false)
                    ->lockForUpdate()
                    ->first();

                if ($template === null) {
                    return;
                }

                $draft = DB::table('pdf_template_versoes')
                    ->where('template_id', $templateId)
                    ->where('status', 'rascunho')
                    ->orderByDesc('versao')
                    ->first();
                $published = DB::table('pdf_template_versoes')
                    ->where('template_id', $templateId)
                    ->where('status', 'publicado')
                    ->orderByDesc('versao')
                    ->first();
                $source = $draft ?? $published;

                if ($source === null) {
                    return;
                }

                $schema = json_decode((string) $source->schema_json, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($schema)) {
                    return;
                }

                $standardized = PdfDefaultTemplates::withStandardHeader($schema);
                $schemaJson = json_encode(
                    $standardized,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
                $newHash = hash('sha256', $schemaJson);

                if (hash_equals((string) ($source->hash_schema ?? ''), $newHash)) {
                    return;
                }

                $page = is_array($standardized['pagina'] ?? null) ? $standardized['pagina'] : [];
                $now = now();
                $payload = [
                    'schema_json' => $schemaJson,
                    'papel' => (string) ($page['papel'] ?? 'a4'),
                    'orientacao' => (string) ($page['orientacao'] ?? 'retrato'),
                    'margens_json' => json_encode(
                        $page['margens'] ?? [],
                        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                    ),
                    'fonte' => (string) ($page['fonte'] ?? 'DejaVu Sans'),
                    'hash_schema' => $newHash,
                    'updated_at' => $now,
                ];

                if ($draft !== null) {
                    DB::table('pdf_template_versoes')->where('id', $draft->id)->update($payload);
                } else {
                    DB::table('pdf_template_versoes')->where('id', $published->id)->update([
                        'status' => 'arquivado',
                        'updated_at' => $now,
                    ]);

                    $nextVersion = ((int) DB::table('pdf_template_versoes')
                        ->where('template_id', $templateId)
                        ->max('versao')) + 1;

                    DB::table('pdf_template_versoes')->insert(array_merge($payload, [
                        'template_id' => $templateId,
                        'versao' => $nextVersion,
                        'status' => 'publicado',
                        'publicado_em' => $now,
                        'publicado_por' => null,
                        'criado_por' => null,
                        'created_at' => $now,
                    ]));
                }

                DB::table('pdf_templates')->where('id', $templateId)->update([
                    'updated_at' => $now,
                ]);
            }, 3);
        }
    }

    public function down(): void
    {
        // Não reverte versões documentais: remover uma publicação posterior
        // violaria a trilha de auditoria e poderia apagar edições do usuário.
    }
};
