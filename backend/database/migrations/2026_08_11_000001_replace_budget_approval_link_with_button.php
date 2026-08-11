<?php

use App\Services\Pdf\PdfDefaultTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Troca a caixa com a URL crua de aprovação por um botão clicável.
 *
 * Antes o orçamento imprimia "Aprovação online: acesse o link abaixo" seguido de
 * um endereço de ~90 caracteres quebrado em duas linhas. Agora vira um botão,
 * com a validade logo abaixo — mesma ação, legível para o cliente.
 *
 * A troca é cirúrgica: só o bloco condicional de `orcamento.link_aprovacao` é
 * substituído, no lugar onde já estava. O resto do modelo (inclusive
 * personalizações) fica intocado, e famílias que já tenham o botão são puladas.
 */
return new class extends Migration
{
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
            $base = trim((string) ($template->tipo_base_codigo ?? '')) ?: (string) $template->tipo_codigo;

            if ($base !== 'os_orcamento') {
                continue;
            }

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

                $corpo = is_array($schema['corpo'] ?? null) ? $schema['corpo'] : [];

                // Já tem botão (rodou antes, ou o usuário montou à mão).
                if (str_contains(json_encode($corpo, JSON_UNESCAPED_UNICODE) ?: '', '"botao_link"')) {
                    return;
                }

                $novoCorpo = $this->replaceApprovalBlocks($corpo);

                if ($novoCorpo === null) {
                    return;
                }

                $schema['corpo'] = $novoCorpo;

                $schemaJson = json_encode(
                    $schema,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
                $newHash = hash('sha256', $schemaJson);

                if (hash_equals((string) ($source->hash_schema ?? ''), $newHash)) {
                    return;
                }

                $this->storeVersion($row, $draft, $published, $schema, $schemaJson, $newHash);
            }, 3);
        }
    }

    public function down(): void
    {
        // Não reverte versões documentais: remover uma publicação posterior
        // violaria a trilha de auditoria e poderia apagar edições do usuário.
    }

    /**
     * Substitui, na posição original, o condicional que imprimia a URL de
     * aprovação. Devolve null quando não há nada a trocar.
     *
     * @param  array<int, mixed>  $corpo
     * @return array<int, mixed>|null
     */
    private function replaceApprovalBlocks(array $corpo): ?array
    {
        $posicao = null;

        foreach ($corpo as $index => $bloco) {
            if (! is_array($bloco)) {
                continue;
            }

            $variavel = strtolower(trim((string) ($bloco['se']['variavel'] ?? '')));

            if ((string) ($bloco['tipo'] ?? '') === 'condicional' && $variavel === 'orcamento.link_aprovacao') {
                $posicao = $index;
                break;
            }
        }

        if ($posicao === null) {
            return null;
        }

        // O bloco seguinte, quando é o aviso de validade em dias, também sai:
        // blocosAprovacaoOnline() já devolve os dois na ordem certa.
        $descartar = 1;
        $seguinte = $corpo[$posicao + 1] ?? null;

        if (
            is_array($seguinte)
            && (string) ($seguinte['tipo'] ?? '') === 'condicional'
            && strtolower(trim((string) ($seguinte['se']['variavel'] ?? ''))) === 'orcamento.validade_dias'
        ) {
            $descartar = 2;
        }

        return [
            ...array_slice($corpo, 0, $posicao),
            ...PdfDefaultTemplates::blocosAprovacaoOnline(),
            ...array_slice($corpo, $posicao + $descartar),
        ];
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
