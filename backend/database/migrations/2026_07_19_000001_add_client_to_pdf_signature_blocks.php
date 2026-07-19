<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Acrescenta a assinatura do cliente aos blocos que possuem somente a
 * assinatura do técnico ou do usuário emissor.
 *
 * Publicações permanecem imutáveis: sem rascunho, uma nova versão publicada
 * é criada. Quando existe rascunho, somente ele é atualizado para não publicar
 * silenciosamente as demais alterações ainda em edição.
 */
return new class extends Migration
{
    private const CLIENT_SIGNATURE = '{{ cliente.nome }} - Cliente';

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

                $updatedSchema = $this->withClientSignatures($schema);
                $schemaJson = json_encode(
                    $updatedSchema,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
                $newHash = hash('sha256', $schemaJson);

                if (hash_equals((string) ($source->hash_schema ?? ''), $newHash)) {
                    return;
                }

                $page = is_array($updatedSchema['pagina'] ?? null) ? $updatedSchema['pagina'] : [];
                $now = now();
                $payload = [
                    'schema_json' => $schemaJson,
                    'papel' => (string) ($page['papel'] ?? 'a4'),
                    'orientacao' => (string) ($page['orientacao'] ?? 'retrato'),
                    'margens_json' => json_encode(
                        $page['margens'] ?? [],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
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
        // Não remove versões nem assinaturas: o documento pode ter sido
        // editado ou emitido após a migration e integra a trilha de auditoria.
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function withClientSignatures(array $schema): array
    {
        foreach (['cabecalho', 'corpo', 'rodape'] as $area) {
            if (is_array($schema[$area] ?? null)) {
                $schema[$area] = $this->updateBlocks($schema[$area]);
            }
        }

        return $schema;
    }

    /**
     * @param array<int, mixed> $blocks
     * @return array<int, mixed>
     */
    private function updateBlocks(array $blocks): array
    {
        foreach ($blocks as $index => $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = strtolower(trim((string) ($block['tipo'] ?? '')));
            if ($type === 'assinatura') {
                $labels = array_values(array_filter(
                    is_array($block['rotulos'] ?? null) ? $block['rotulos'] : [],
                    static fn (mixed $label): bool => is_string($label) && trim($label) !== ''
                ));

                if (count($labels) === 1 && ! $this->isClientSignature($labels[0])) {
                    $block['rotulos'] = [$labels[0], self::CLIENT_SIGNATURE];
                }
            }

            if ($type === 'condicional' && is_array($block['blocos'] ?? null)) {
                $block['blocos'] = $this->updateBlocks($block['blocos']);
            }

            if ($type === 'colunas' && is_array($block['colunas'] ?? null)) {
                foreach ($block['colunas'] as $columnIndex => $column) {
                    if (is_array($column)) {
                        $block['colunas'][$columnIndex] = $this->updateBlocks($column);
                    }
                }
            }

            $blocks[$index] = $block;
        }

        return $blocks;
    }

    private function isClientSignature(string $label): bool
    {
        return preg_match('/\{\{\s*cliente\.nome\b/i', $label) === 1;
    }
};
