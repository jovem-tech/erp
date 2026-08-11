<?php

use App\Services\Pdf\PdfDefaultTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leva as condições comerciais e a garantia aos modelos PDF já publicados.
 *
 * Duas mudanças, ambas aditivas:
 *
 *  1. Famílias de orçamento (`os_orcamento` e personalizadas derivadas dela)
 *     ganham o bloco de condições de pagamento + garantia, inserido antes da
 *     assinatura quando existir, senão ao final do corpo.
 *  2. Documentos de OS que citavam "{{ os.garantia_dias }} dia(s)" passam a
 *     usar o prazo por extenso "{{ os.garantia_prazo }}" ("1 ano" em vez de
 *     "365").
 *
 * Nada é removido e nenhum bloco existente muda de lugar: layouts
 * personalizados pelo usuário continuam valendo. Versões publicadas são
 * imutáveis — a anterior é arquivada e uma nova é publicada, como já faz
 * 2026_07_18_000015_standardize_pdf_template_headers.
 */
return new class extends Migration
{
    private const LEGACY_WARRANTY_TEXT = '{{ os.garantia_dias }} dia(s)';

    private const WARRANTY_TERM_TOKEN = '{{ os.garantia_prazo }}';

    public function up(): void
    {
        if (! Schema::hasTable('pdf_templates') || ! Schema::hasTable('pdf_template_versoes')) {
            return;
        }

        $templates = DB::table('pdf_templates')
            ->where('arquivado', false)
            ->orderBy('id')
            ->get(['id', 'tipo_codigo', 'tipo_base_codigo']);

        foreach ($templates as $template) {
            DB::transaction(function () use ($template): void {
                $row = DB::table('pdf_templates')
                    ->where('id', $template->id)
                    ->where('arquivado', false)
                    ->lockForUpdate()
                    ->first();

                if ($row === null) {
                    return;
                }

                $draft = DB::table('pdf_template_versoes')
                    ->where('template_id', $row->id)
                    ->where('status', 'rascunho')
                    ->orderByDesc('versao')
                    ->first();
                $published = DB::table('pdf_template_versoes')
                    ->where('template_id', $row->id)
                    ->where('status', 'publicado')
                    ->orderByDesc('versao')
                    ->first();
                $source = $draft ?? $published;

                if ($source === null) {
                    return;
                }

                try {
                    $schema = json_decode((string) $source->schema_json, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    return;
                }

                if (! is_array($schema)) {
                    return;
                }

                $updated = $this->applyWarrantyTerm($schema);

                if ($this->isBudgetFamily($row)) {
                    $updated = $this->appendCommercialTerms($updated);
                }

                $schemaJson = json_encode(
                    $updated,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
                $newHash = hash('sha256', $schemaJson);

                if (hash_equals((string) ($source->hash_schema ?? ''), $newHash)) {
                    return;
                }

                $this->storeVersion($row, $draft, $published, $updated, $schemaJson, $newHash);
            }, 3);
        }
    }

    public function down(): void
    {
        // Não reverte versões documentais: remover uma publicação posterior
        // violaria a trilha de auditoria e poderia apagar edições do usuário.
    }

    private function isBudgetFamily(object $template): bool
    {
        $base = trim((string) ($template->tipo_base_codigo ?? '')) ?: (string) $template->tipo_codigo;

        return $base === 'os_orcamento';
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function appendCommercialTerms(array $schema): array
    {
        $corpo = is_array($schema['corpo'] ?? null) ? $schema['corpo'] : [];

        // Já tem o bloco (rodou antes, ou o usuário montou à mão): não duplica.
        if (str_contains(json_encode($corpo, JSON_UNESCAPED_UNICODE) ?: '', 'orcamento.formas_pagamento')) {
            return $schema;
        }

        $blocos = PdfDefaultTemplates::blocosCondicoesComerciais();

        // Condições vêm antes das assinaturas — o cliente assina depois de ler
        // o que está aceitando.
        $posicao = null;
        foreach ($corpo as $index => $bloco) {
            if (is_array($bloco) && (string) ($bloco['tipo'] ?? '') === 'assinatura') {
                $posicao = $index;
                break;
            }
        }

        $schema['corpo'] = $posicao === null
            ? [...$corpo, ...$blocos]
            : [...array_slice($corpo, 0, $posicao), ...$blocos, ...array_slice($corpo, $posicao)];

        return $schema;
    }

    /**
     * Troca o texto legado da garantia pelo prazo por extenso, em qualquer
     * profundidade do schema.
     *
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    private function applyWarrantyTerm(array $node): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->applyWarrantyTerm($value);

                continue;
            }

            if (is_string($value) && str_contains($value, self::LEGACY_WARRANTY_TEXT)) {
                $node[$key] = str_replace(self::LEGACY_WARRANTY_TEXT, self::WARRANTY_TERM_TOKEN, $value);
            }
        }

        return $node;
    }

    /**
     * Rascunho é mutável (edita no lugar); publicado é imutável (arquiva e
     * publica a próxima versão).
     *
     * @param  array<string, mixed>  $schema
     */
    private function storeVersion(
        object $template,
        ?object $draft,
        ?object $published,
        array $schema,
        string $schemaJson,
        string $hash
    ): void {
        $page = is_array($schema['pagina'] ?? null) ? $schema['pagina'] : [];
        $now = now();

        $payload = [
            'schema_json' => $schemaJson,
            'papel' => (string) ($page['papel'] ?? 'a4'),
            'orientacao' => (string) ($page['orientacao'] ?? 'retrato'),
            'margens_json' => json_encode($page['margens'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'fonte' => (string) ($page['fonte'] ?? 'DejaVu Sans'),
            'hash_schema' => $hash,
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
                ->where('template_id', $template->id)
                ->max('versao')) + 1;

            DB::table('pdf_template_versoes')->insert(array_merge($payload, [
                'template_id' => $template->id,
                'versao' => $nextVersion,
                'status' => 'publicado',
                'publicado_em' => $now,
                'publicado_por' => null,
                'criado_por' => null,
                'created_at' => $now,
            ]));
        }

        DB::table('pdf_templates')->where('id', $template->id)->update(['updated_at' => $now]);
    }
};
