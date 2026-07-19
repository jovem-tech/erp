<?php

namespace App\Services\Pdf;

use App\Models\PdfTemplate;
use App\Models\PdfTemplateVersao;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Administração dos templates do motor central: rascunho -> publicado ->
 * arquivado, com versões imutáveis depois de publicadas.
 *
 * Regras de concorrência: salvar rascunho exige o updated_at que o editor
 * carregou (409 quando defasado); publicar/restaurar rodam em transação com
 * lockForUpdate na família, rebaixando o publicado anterior para arquivado
 * e promovendo o novo atomicamente.
 */
class PdfTemplateAdminService
{
    public function __construct(
        private readonly PdfTemplateRegistry $registry,
        private readonly PdfSchemaValidator $validator
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTypes(): array
    {
        $families = PdfTemplate::query()
            ->with('versoes')
            ->get()
            ->keyBy('tipo_codigo');

        $rows = [];

        $descriptors = $this->registry->types();
        foreach ($families->where('personalizado', true) as $family) {
            $base = $descriptors[(string) $family->tipo_base_codigo] ?? null;
            if (is_array($base)) {
                $descriptors[(string) $family->tipo_codigo] = array_merge($base, [
                    'codigo' => (string) $family->tipo_codigo,
                    'nome' => (string) $family->nome,
                    'descricao' => (string) ($family->descricao ?? ''),
                    'legacy_tipo' => (string) $family->tipo_codigo,
                    'message_template_code' => null,
                    'automatic_triggers' => [],
                    'personalizado' => true,
                    'tipo_base_codigo' => (string) $family->tipo_base_codigo,
                ]);
            }
        }

        foreach ($descriptors as $codigo => $descriptor) {
            /** @var PdfTemplate|null $family */
            $family = $families->get($codigo);
            $publicada = $family?->versoes->where('status', PdfTemplateVersao::STATUS_PUBLICADO)->sortByDesc('versao')->first();
            $rascunho = $family?->versoes->where('status', PdfTemplateVersao::STATUS_RASCUNHO)->sortByDesc('versao')->first();

            $rows[] = [
                'tipo_codigo' => $codigo,
                'nome' => (string) ($family->nome ?? $descriptor['nome']),
                'descricao' => (string) ($family->descricao ?? $descriptor['descricao'] ?? ''),
                'template_id' => $family !== null ? (int) $family->id : null,
                'arquivado' => (bool) ($family->arquivado ?? false),
                'versao_publicada' => $publicada !== null ? (int) $publicada->versao : null,
                'publicado_em' => $publicada?->publicado_em?->toDateTimeString(),
                'tem_rascunho' => $rascunho !== null,
                'versao_rascunho' => $rascunho !== null ? (int) $rascunho->versao : null,
                'total_versoes' => $family !== null ? $family->versoes->count() : 0,
                'gatilhos_automaticos' => (array) ($descriptor['automatic_triggers'] ?? []),
                'personalizado' => (bool) ($family->personalizado ?? false),
                'tipo_base_codigo' => (string) ($family->tipo_base_codigo ?? $codigo),
            ];
        }

        return $rows;
    }

    /**
     * Cria uma família personalizada com cabeçalho/rodapé institucionais e
     * corpo mínimo editável. A fonte de dados é sempre um tipo-base seguro.
     *
     * @return array<string, mixed>
     */
    public function create(string $nome, ?string $descricao, string $tipoBaseCodigo, ?int $actorId): array
    {
        $baseDescriptor = $this->registry->types()[$tipoBaseCodigo] ?? null;
        $default = PdfDefaultTemplates::all()[$tipoBaseCodigo]['schema'] ?? null;
        if (! is_array($baseDescriptor) || ! is_array($default)) {
            return ['result' => 'invalid_base'];
        }

        $schema = $default;
        $schema['corpo'] = [
            ['tipo' => 'cabecalho_secao', 'texto' => $nome],
            ['tipo' => 'paragrafo', 'texto' => 'Edite este conteúdo e insira os campos necessários.'],
        ];

        return $this->createCustomFamily($nome, $descricao, $tipoBaseCodigo, $schema, null, $actorId);
    }

    /**
     * Clona o rascunho atual; na ausência dele, clona a versão publicada.
     * O schema segue independente e inicia novamente na versão 1.
     *
     * @return array<string, mixed>
     */
    public function clone(int $sourceTemplateId, string $nome, ?string $descricao, ?int $actorId): array
    {
        $source = PdfTemplate::query()->with('versoes')->find($sourceTemplateId);
        if (! $source instanceof PdfTemplate) {
            return ['result' => 'not_found'];
        }

        $descriptor = $this->registry->get((string) $source->tipo_codigo);
        if (! is_array($descriptor)) {
            return ['result' => 'unknown_type'];
        }

        $version = $source->versoes
            ->where('status', PdfTemplateVersao::STATUS_RASCUNHO)
            ->sortByDesc('versao')
            ->first()
            ?? $source->versoes
                ->where('status', PdfTemplateVersao::STATUS_PUBLICADO)
                ->sortByDesc('versao')
                ->first();

        if (! $version instanceof PdfTemplateVersao) {
            return ['result' => 'no_version'];
        }

        return $this->createCustomFamily(
            $nome,
            $descricao,
            (string) ($descriptor['tipo_base_codigo'] ?? $source->tipo_codigo),
            $version->schema(),
            (int) $source->id,
            $actorId
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function typeMetadata(string $codigo): ?array
    {
        $descriptor = $this->registry->get($codigo);
        if ($descriptor === null) {
            return null;
        }

        $variables = [];
        foreach (($descriptor['variables'] ?? []) as $path => $tipo) {
            $variables[] = ['caminho' => (string) $path, 'tipo' => (string) $tipo];
        }

        $collections = [];
        foreach (($descriptor['collections'] ?? []) as $nome => $colunas) {
            $collections[] = [
                'nome' => (string) $nome,
                'colunas' => collect($colunas)
                    ->map(static fn ($tipo, $campo): array => ['campo' => (string) $campo, 'tipo' => (string) $tipo])
                    ->values()
                    ->all(),
            ];
        }

        return [
            'tipo_codigo' => $codigo,
            'nome' => (string) ($descriptor['nome'] ?? ''),
            'descricao' => (string) ($descriptor['descricao'] ?? ''),
            'variaveis' => $variables,
            'colecoes' => $collections,
            'tokens_imagem' => PdfTemplateRegistry::IMAGE_TOKENS,
            'formatadores' => PdfVariableResolver::FORMATTERS,
            'blocos' => PdfSchemaValidator::BLOCK_TYPES,
            'papeis' => PdfSchemaValidator::PAPERS,
            'orientacoes' => PdfSchemaValidator::ORIENTATIONS,
            'operadores_condicao' => PdfSchemaValidator::CONDITION_OPERATORS,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function templateDetail(int $templateId): array
    {
        $family = PdfTemplate::query()->with('versoes')->find($templateId);
        if (! $family instanceof PdfTemplate) {
            return ['result' => 'not_found'];
        }

        $publicada = $family->versoes->where('status', PdfTemplateVersao::STATUS_PUBLICADO)->sortByDesc('versao')->first();
        $rascunho = $family->versoes->where('status', PdfTemplateVersao::STATUS_RASCUNHO)->sortByDesc('versao')->first();

        return [
            'result' => 'ok',
            'template' => [
                'id' => (int) $family->id,
                'tipo_codigo' => (string) $family->tipo_codigo,
                'nome' => (string) $family->nome,
                'descricao' => (string) ($family->descricao ?? ''),
                'arquivado' => (bool) $family->arquivado,
                'personalizado' => (bool) $family->personalizado,
                'tipo_base_codigo' => (string) ($family->tipo_base_codigo ?? $family->tipo_codigo),
                'origem_template_id' => $family->origem_template_id !== null ? (int) $family->origem_template_id : null,
                'versao_publicada' => $publicada !== null ? $this->mapVersion($publicada, includeSchema: true) : null,
                'rascunho' => $rascunho !== null ? $this->mapVersion($rascunho, includeSchema: true) : null,
                'versoes' => $family->versoes
                    ->sortByDesc('versao')
                    ->map(fn (PdfTemplateVersao $versao): array => $this->mapVersion($versao))
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * Salva (cria/atualiza) o rascunho da família.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function saveDraft(int $templateId, array $schema, ?string $expectedUpdatedAt, ?int $actorId): array
    {
        return DB::transaction(function () use ($templateId, $schema, $expectedUpdatedAt, $actorId): array {
            $family = PdfTemplate::query()->lockForUpdate()->find($templateId);
            if (! $family instanceof PdfTemplate) {
                return ['result' => 'not_found'];
            }

            $descriptor = $this->registry->get((string) $family->tipo_codigo);
            if ($descriptor === null) {
                return ['result' => 'unknown_type'];
            }

            $rascunho = $family->rascunhoAtual();

            // Concorrência otimista: o editor manda o updated_at do rascunho
            // que carregou (ou null quando ainda não existia rascunho).
            $currentStamp = $rascunho?->updated_at?->toDateTimeString();
            $expected = trim((string) ($expectedUpdatedAt ?? '')) ?: null;
            if ($currentStamp !== $expected) {
                return ['result' => 'stale', 'updated_at' => $currentStamp];
            }

            // Validação estrutural leve no save (bloqueio duro só no publish):
            // áreas precisam existir e blocos precisam ter tipo conhecido.
            $structuralErrors = array_values(array_filter(
                $this->validator->validate($schema, $descriptor),
                static fn (string $error): bool => str_contains($error, 'Área obrigatória')
                    || str_contains($error, 'Tipo de bloco desconhecido')
            ));

            if ($structuralErrors !== []) {
                return ['result' => 'invalid', 'errors' => $structuralErrors];
            }

            $schemaJson = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $pagina = is_array($schema['pagina'] ?? null) ? $schema['pagina'] : [];
            $now = Carbon::now();

            $payload = [
                'schema_json' => $schemaJson,
                'papel' => (string) ($pagina['papel'] ?? 'a4'),
                'orientacao' => (string) ($pagina['orientacao'] ?? 'retrato'),
                'margens_json' => json_encode($pagina['margens'] ?? [], JSON_UNESCAPED_UNICODE),
                'fonte' => (string) ($pagina['fonte'] ?? 'DejaVu Sans'),
                'hash_schema' => hash('sha256', (string) $schemaJson),
                'updated_at' => $now,
            ];

            if ($rascunho instanceof PdfTemplateVersao) {
                $rascunho->forceFill($payload)->save();
            } else {
                $nextVersion = ((int) $family->versoes()->max('versao')) + 1;
                $rascunho = PdfTemplateVersao::query()->create(array_merge($payload, [
                    'template_id' => (int) $family->id,
                    'versao' => $nextVersion,
                    'status' => PdfTemplateVersao::STATUS_RASCUNHO,
                    'criado_por' => $actorId,
                    'created_at' => $now,
                ]));
            }

            $family->forceFill(['atualizado_por' => $actorId, 'updated_at' => $now])->save();
            PdfGenerationService::forgetSchemaCache($rascunho);

            return [
                'result' => 'ok',
                'rascunho' => $this->mapVersion($rascunho->fresh() ?? $rascunho, includeSchema: true),
            ];
        });
    }

    /**
     * Publica o rascunho atual (validação completa; 1 publicado por família).
     *
     * @return array<string, mixed>
     */
    public function publish(int $templateId, ?int $actorId): array
    {
        return DB::transaction(function () use ($templateId, $actorId): array {
            $family = PdfTemplate::query()->lockForUpdate()->find($templateId);
            if (! $family instanceof PdfTemplate) {
                return ['result' => 'not_found'];
            }

            $descriptor = $this->registry->get((string) $family->tipo_codigo);
            if ($descriptor === null) {
                return ['result' => 'unknown_type'];
            }

            $rascunho = $family->rascunhoAtual();
            if (! $rascunho instanceof PdfTemplateVersao) {
                return ['result' => 'no_draft'];
            }

            $errors = $this->validator->validate($rascunho->schema(), $descriptor);
            if ($errors !== []) {
                return ['result' => 'invalid', 'errors' => $errors];
            }

            $now = Carbon::now();

            $publicadaAnterior = $family->versaoPublicada();
            if ($publicadaAnterior instanceof PdfTemplateVersao) {
                $publicadaAnterior->forceFill([
                    'status' => PdfTemplateVersao::STATUS_ARQUIVADO,
                    'updated_at' => $now,
                ])->save();
                PdfGenerationService::forgetSchemaCache($publicadaAnterior);
            }

            $rascunho->forceFill([
                'status' => PdfTemplateVersao::STATUS_PUBLICADO,
                'publicado_em' => $now,
                'publicado_por' => $actorId,
                'updated_at' => $now,
            ])->save();
            PdfGenerationService::forgetSchemaCache($rascunho);

            $family->forceFill(['atualizado_por' => $actorId, 'updated_at' => $now])->save();

            return [
                'result' => 'ok',
                'versao_publicada' => $this->mapVersion($rascunho->fresh() ?? $rascunho),
            ];
        });
    }

    /**
     * Restaura uma versão antiga como NOVO rascunho (histórico preservado).
     * Também cobre "duplicar": restaurar a própria versão publicada.
     *
     * @return array<string, mixed>
     */
    public function restore(int $templateId, int $versaoNumero, ?int $actorId): array
    {
        return DB::transaction(function () use ($templateId, $versaoNumero, $actorId): array {
            $family = PdfTemplate::query()->lockForUpdate()->find($templateId);
            if (! $family instanceof PdfTemplate) {
                return ['result' => 'not_found'];
            }

            $origem = $family->versoes()->where('versao', $versaoNumero)->first();
            if (! $origem instanceof PdfTemplateVersao) {
                return ['result' => 'version_not_found'];
            }

            if ($family->rascunhoAtual() instanceof PdfTemplateVersao) {
                return ['result' => 'draft_exists'];
            }

            $now = Carbon::now();
            $nextVersion = ((int) $family->versoes()->max('versao')) + 1;

            $rascunho = PdfTemplateVersao::query()->create([
                'template_id' => (int) $family->id,
                'versao' => $nextVersion,
                'status' => PdfTemplateVersao::STATUS_RASCUNHO,
                'schema_json' => (string) $origem->schema_json,
                'papel' => (string) $origem->papel,
                'orientacao' => (string) $origem->orientacao,
                'margens_json' => $origem->margens_json,
                'fonte' => $origem->fonte,
                'hash_schema' => (string) $origem->hash_schema,
                'criado_por' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $family->forceFill(['atualizado_por' => $actorId, 'updated_at' => $now])->save();

            return [
                'result' => 'ok',
                'rascunho' => $this->mapVersion($rascunho, includeSchema: true),
                'origem_versao' => $versaoNumero,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function versionDetail(int $templateId, int $versaoNumero): array
    {
        $versao = PdfTemplateVersao::query()
            ->where('template_id', $templateId)
            ->where('versao', $versaoNumero)
            ->first();

        if (! $versao instanceof PdfTemplateVersao) {
            return ['result' => 'not_found'];
        }

        return ['result' => 'ok', 'versao' => $this->mapVersion($versao, includeSchema: true)];
    }

    /**
     * Comparação simples entre duas versões (schemas completos + resumo de
     * mudanças por área para a UI destacar).
     *
     * @return array<string, mixed>
     */
    public function compare(int $templateId, int $de, int $para): array
    {
        $versaoDe = PdfTemplateVersao::query()->where('template_id', $templateId)->where('versao', $de)->first();
        $versaoPara = PdfTemplateVersao::query()->where('template_id', $templateId)->where('versao', $para)->first();

        if (! $versaoDe instanceof PdfTemplateVersao || ! $versaoPara instanceof PdfTemplateVersao) {
            return ['result' => 'not_found'];
        }

        $schemaDe = $versaoDe->schema();
        $schemaPara = $versaoPara->schema();

        $resumo = [];
        foreach (PdfSchemaValidator::AREAS as $area) {
            $blocosDe = is_array($schemaDe[$area] ?? null) ? $schemaDe[$area] : [];
            $blocosPara = is_array($schemaPara[$area] ?? null) ? $schemaPara[$area] : [];

            $resumo[$area] = [
                'blocos_de' => count($blocosDe),
                'blocos_para' => count($blocosPara),
                'alterada' => json_encode($blocosDe) !== json_encode($blocosPara),
            ];
        }

        return [
            'result' => 'ok',
            'de' => $this->mapVersion($versaoDe, includeSchema: true),
            'para' => $this->mapVersion($versaoPara, includeSchema: true),
            'resumo' => $resumo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapVersion(PdfTemplateVersao $versao, bool $includeSchema = false): array
    {
        $mapped = [
            'id' => (int) $versao->id,
            'versao' => (int) $versao->versao,
            'status' => (string) $versao->status,
            'papel' => (string) $versao->papel,
            'orientacao' => (string) $versao->orientacao,
            'fonte' => (string) ($versao->fonte ?? ''),
            'hash_schema' => (string) ($versao->hash_schema ?? ''),
            'publicado_em' => $versao->publicado_em?->toDateTimeString(),
            'publicado_por' => $versao->publicado_por,
            'criado_por' => $versao->criado_por,
            'created_at' => $versao->created_at?->toDateTimeString(),
            'updated_at' => $versao->updated_at?->toDateTimeString(),
        ];

        if ($includeSchema) {
            $mapped['schema'] = $versao->schema();
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function createCustomFamily(
        string $nome,
        ?string $descricao,
        string $tipoBaseCodigo,
        array $schema,
        ?int $sourceTemplateId,
        ?int $actorId
    ): array {
        $schema = PdfDefaultTemplates::withStandardHeader($schema);

        return DB::transaction(function () use ($nome, $descricao, $tipoBaseCodigo, $schema, $sourceTemplateId, $actorId): array {
            $now = Carbon::now();
            $codigo = $this->uniqueCustomCode($nome);
            $pagina = is_array($schema['pagina'] ?? null) ? $schema['pagina'] : [];
            $schemaJson = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $family = PdfTemplate::query()->create([
                'tipo_codigo' => $codigo,
                'nome' => $nome,
                'descricao' => $descricao,
                'arquivado' => false,
                'personalizado' => true,
                'tipo_base_codigo' => $tipoBaseCodigo,
                'origem_template_id' => $sourceTemplateId,
                'criado_por' => $actorId,
                'atualizado_por' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $draft = PdfTemplateVersao::query()->create([
                'template_id' => (int) $family->id,
                'versao' => 1,
                'status' => PdfTemplateVersao::STATUS_RASCUNHO,
                'schema_json' => $schemaJson,
                'papel' => (string) ($pagina['papel'] ?? 'a4'),
                'orientacao' => (string) ($pagina['orientacao'] ?? 'retrato'),
                'margens_json' => json_encode($pagina['margens'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'fonte' => (string) ($pagina['fonte'] ?? 'DejaVu Sans'),
                'hash_schema' => hash('sha256', $schemaJson),
                'criado_por' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->registry->forget($codigo);

            return [
                'result' => 'ok',
                'template' => [
                    'id' => (int) $family->id,
                    'tipo_codigo' => $codigo,
                    'nome' => $nome,
                    'descricao' => (string) ($descricao ?? ''),
                    'personalizado' => true,
                    'tipo_base_codigo' => $tipoBaseCodigo,
                    'origem_template_id' => $sourceTemplateId,
                    'rascunho' => $this->mapVersion($draft, includeSchema: true),
                ],
            ];
        }, 3);
    }

    private function uniqueCustomCode(string $nome): string
    {
        $slug = str_replace('-', '_', Str::slug($nome));
        $slug = trim(preg_replace('/[^a-z0-9_]+/', '', $slug) ?? '', '_');
        $base = 'custom_' . substr($slug !== '' ? $slug : 'documento', 0, 55);

        // O sufixo aleatório evita a corrida "consulta livre -> insert" entre
        // dois workers que criem documentos com o mesmo nome simultaneamente.
        do {
            $codigo = $base . '_' . Str::lower(Str::random(8));
        } while (PdfTemplate::query()->where('tipo_codigo', $codigo)->exists());

        return $codigo;
    }
}
