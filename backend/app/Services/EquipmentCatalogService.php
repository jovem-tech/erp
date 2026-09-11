<?php

namespace App\Services;

use App\Models\EquipmentBrand;
use App\Models\EquipmentModel;
use App\Models\EquipmentType;
use App\Services\Orders\OrderSearchIndexService;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Catálogo de equipamentos (tipo -> marca -> modelo) usado para padronizar o
 * cadastro de aparelhos em OS, orçamento e mobile. E' puramente consultivo:
 * nada aqui cria vinculo direto com `os`/`orcamentos` — esses fluxos apenas
 * leem os nomes já cadastrados via `EquipmentWorkflowService::formData()`.
 *
 * Nasceu como extracao dos helpers de escopo tipo->marca->modelo que ja
 * existiam em EquipmentWorkflowService (quick-add do formulario de
 * equipamento) — ver
 * `documentacao/04-governanca-ai/playbooks/catalogo-equipamentos-vinculo-rapido.md`.
 * `EquipmentWorkflowService::createBrand/createModel` delegam para ca' para
 * manter o comportamento do quick-add identico.
 *
 * Convencao herdada de EstoqueCatalogController: nunca ha' exclusao de
 * verdade aqui, so' `ativo = false` — as tabelas legadas tem FK de
 * `equipamentos` para o catalogo (`restrictOnDelete`/`nullOnDelete` conforme
 * a coluna) e um DELETE quebraria em producao.
 */
class EquipmentCatalogService
{
    public const BRAND_SCOPE_ANCHOR_MODEL_NAME = '__CATALOG_BRAND_SCOPE__';

    private const MAX_NAME_LENGTH = 100;

    private const MAX_IMPORT_ROWS = 5000;

    public function __construct(private readonly OrderSearchIndexService $orderSearchIndexService)
    {
    }

    // ----------------------------------------------------------------------
    // Quick-add (usado pelo formulario de equipamento — comportamento
    // preexistente, com Title Case, preservado por compatibilidade).
    // ----------------------------------------------------------------------

    public function createBrand(string $name, int $typeId): EquipmentBrand
    {
        $normalized = $this->normalizeTitleCaseName($name);

        if ($normalized === '') {
            throw new RuntimeException('Informe um nome de marca valido.');
        }

        $brand = EquipmentBrand::query()->firstOrNew(['nome' => $normalized]);
        $brand->nome = $normalized;
        $brand->ativo = true;
        if (! $brand->exists) {
            $brand->created_at = now();
        }
        $brand->updated_at = now();
        $brand->save();

        $this->ensureBrandTypeCatalogScope($typeId, (int) $brand->id);

        return $brand->fresh() ?? $brand;
    }

    public function createModel(int $brandId, string $name, int $typeId): EquipmentModel
    {
        if ($brandId <= 0) {
            throw new RuntimeException('Selecione uma marca valida para o modelo.');
        }

        $brand = EquipmentBrand::query()->find($brandId);
        if (! $brand instanceof EquipmentBrand) {
            throw new RuntimeException('Marca informada nao foi encontrada.');
        }

        $normalized = $this->normalizeTitleCaseName($name);
        if ($normalized === '') {
            throw new RuntimeException('Informe um nome de modelo valido.');
        }

        $model = EquipmentModel::query()->firstOrNew([
            'marca_id' => $brandId,
            'nome' => $normalized,
        ]);

        $model->marca_id = $brandId;
        $model->nome = $normalized;
        $model->ativo = true;
        if (! $model->exists) {
            $model->created_at = now();
        }
        $model->updated_at = now();
        $model->save();

        $this->ensureModelTypeCatalogScope($typeId, $brandId, (int) $model->id);

        return $model->fresh() ?? $model;
    }

    // ----------------------------------------------------------------------
    // Escopo do catalogo (tipo -> marca -> modelo)
    // ----------------------------------------------------------------------

    /**
     * @return array<int, array{tipo_id:int,marca_id:int,modelo_id:int}>
     */
    public function catalogRelations(): array
    {
        if (! $this->hasCatalogRelationsTable()) {
            return [];
        }

        return DB::table('equipamentos_catalogo_relacoes')
            ->where('ativo', 1)
            ->orderBy('tipo_id')
            ->orderBy('marca_id')
            ->orderBy('modelo_id')
            ->get(['tipo_id', 'marca_id', 'modelo_id'])
            ->map(static fn (object $relation): array => [
                'tipo_id' => (int) ($relation->tipo_id ?? 0),
                'marca_id' => (int) ($relation->marca_id ?? 0),
                'modelo_id' => (int) ($relation->modelo_id ?? 0),
            ])
            ->values()
            ->all();
    }

    public function ensureBrandTypeCatalogScope(int $typeId, int $brandId): bool
    {
        if ($typeId <= 0 || $brandId <= 0 || ! $this->hasCatalogRelationsTable()) {
            return false;
        }

        if (! EquipmentType::query()->whereKey($typeId)->exists()) {
            throw new RuntimeException('Tipo informado nao foi encontrado.');
        }

        if (! EquipmentBrand::query()->whereKey($brandId)->exists()) {
            throw new RuntimeException('Marca informada nao foi encontrada.');
        }

        // A tabela legada exige `modelo_id` nao nulo. Mantemos uma ancora inativa
        // para registrar o escopo `tipo -> marca` sem expor um modelo falso ao usuario.
        $anchorModel = $this->ensureBrandScopeAnchorModel($brandId);

        return $this->ensureCatalogRelationRecord($typeId, $brandId, (int) $anchorModel->id);
    }

    public function ensureModelTypeCatalogScope(int $typeId, int $brandId, int $modelId): bool
    {
        if ($typeId <= 0 || $brandId <= 0 || $modelId <= 0 || ! $this->hasCatalogRelationsTable()) {
            return false;
        }

        if (! EquipmentType::query()->whereKey($typeId)->exists()) {
            throw new RuntimeException('Tipo informado nao foi encontrado.');
        }

        $model = EquipmentModel::query()->find($modelId);
        if (! $model instanceof EquipmentModel || (int) $model->marca_id !== $brandId) {
            throw new RuntimeException('Modelo informado nao pertence a marca selecionada.');
        }

        return $this->ensureCatalogRelationRecord($typeId, $brandId, $modelId);
    }

    /**
     * @return bool true quando uma linha NOVA foi inserida (falso quando já existia/foi reativada).
     */
    public function ensureCatalogRelationRecord(int $typeId, int $brandId, int $modelId): bool
    {
        $existing = DB::table('equipamentos_catalogo_relacoes')
            ->where('tipo_id', $typeId)
            ->where('marca_id', $brandId)
            ->where('modelo_id', $modelId)
            ->first();

        if ($existing !== null) {
            DB::table('equipamentos_catalogo_relacoes')
                ->where('id', $existing->id)
                ->update([
                    'ativo' => 1,
                    'updated_at' => now(),
                ]);

            return false;
        }

        DB::table('equipamentos_catalogo_relacoes')->insert([
            'tipo_id' => $typeId,
            'marca_id' => $brandId,
            'modelo_id' => $modelId,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    public function ensureBrandScopeAnchorModel(int $brandId): EquipmentModel
    {
        $anchor = EquipmentModel::query()->firstOrNew([
            'marca_id' => $brandId,
            'nome' => self::BRAND_SCOPE_ANCHOR_MODEL_NAME,
        ]);

        $anchor->marca_id = $brandId;
        $anchor->nome = self::BRAND_SCOPE_ANCHOR_MODEL_NAME;
        $anchor->ativo = false;
        if (! $anchor->exists) {
            $anchor->created_at = now();
        }
        $anchor->updated_at = now();
        $anchor->save();

        return $anchor->fresh() ?? $anchor;
    }

    public function hasCatalogRelationsTable(): bool
    {
        return DB::getSchemaBuilder()->hasTable('equipamentos_catalogo_relacoes');
    }

    // ----------------------------------------------------------------------
    // Lookups case-insensitive (colation MySQL ja' e' CI, mas o SQLite dos
    // testes nao — nunca depender da colation do banco).
    // ----------------------------------------------------------------------

    public function findTypeByName(string $name): ?EquipmentType
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        return EquipmentType::query()->whereRaw('LOWER(nome) = ?', [mb_strtolower($name, 'UTF-8')])->first();
    }

    public function findBrandByName(string $name): ?EquipmentBrand
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        return EquipmentBrand::query()->whereRaw('LOWER(nome) = ?', [mb_strtolower($name, 'UTF-8')])->first();
    }

    public function findModelByName(int $brandId, string $name): ?EquipmentModel
    {
        $name = trim($name);
        if ($name === '' || $brandId <= 0) {
            return null;
        }

        return EquipmentModel::query()
            ->where('marca_id', $brandId)
            ->whereRaw('LOWER(nome) = ?', [mb_strtolower($name, 'UTF-8')])
            ->first();
    }

    // ----------------------------------------------------------------------
    // Listagens (tela "Equipamentos" em Cadastros)
    // ----------------------------------------------------------------------

    /**
     * @param array{search?:string,tipo_id?:int|string,marca_id?:int|string,ativo?:string} $filters
     */
    public function paginateModels(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = EquipmentModel::query()
            ->join('equipamentos_marcas as marca', 'marca.id', '=', 'equipamentos_modelos.marca_id')
            ->where('equipamentos_modelos.nome', '!=', self::BRAND_SCOPE_ANCHOR_MODEL_NAME)
            ->select([
                'equipamentos_modelos.id',
                'equipamentos_modelos.nome',
                'equipamentos_modelos.ativo',
                'equipamentos_modelos.marca_id',
                'marca.nome as marca_nome',
                'marca.ativo as marca_ativo',
            ]);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.mb_strtolower($search, 'UTF-8').'%';
            $query->where(function ($inner) use ($like): void {
                $inner->whereRaw('LOWER(equipamentos_modelos.nome) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(marca.nome) LIKE ?', [$like]);
            });
        }

        $marcaId = (int) ($filters['marca_id'] ?? 0);
        if ($marcaId > 0) {
            $query->where('equipamentos_modelos.marca_id', $marcaId);
        }

        $ativo = (string) ($filters['ativo'] ?? '');
        if ($ativo === '1') {
            $query->where('equipamentos_modelos.ativo', 1);
        } elseif ($ativo === '0') {
            $query->where('equipamentos_modelos.ativo', 0);
        }

        $tipoId = (int) ($filters['tipo_id'] ?? 0);
        if ($tipoId > 0) {
            $query->whereExists(function ($sub) use ($tipoId): void {
                $sub->selectRaw('1')
                    ->from('equipamentos_catalogo_relacoes as rel')
                    ->whereColumn('rel.modelo_id', 'equipamentos_modelos.id')
                    ->where('rel.tipo_id', $tipoId)
                    ->where('rel.ativo', 1);
            });
        }

        $paginator = $query
            ->orderBy('marca.nome')
            ->orderBy('equipamentos_modelos.nome')
            ->paginate(max(1, $perPage))
            ->withQueryString();

        $modelIds = array_map(static fn ($row): int => (int) $row->id, $paginator->items());
        $typesByModel = $this->typesForModels($modelIds);

        $paginator->getCollection()->transform(static fn ($row): array => [
            'id' => (int) $row->id,
            'nome' => (string) $row->nome,
            'ativo' => (bool) $row->ativo,
            'marca' => [
                'id' => (int) $row->marca_id,
                'nome' => (string) $row->marca_nome,
                'ativo' => (bool) $row->marca_ativo,
            ],
            'tipos' => $typesByModel[(int) $row->id] ?? [],
        ]);

        return $paginator;
    }

    /**
     * @param  array<int, int>  $modelIds
     * @return array<int, array<int, array{id:int,nome:string}>>
     */
    private function typesForModels(array $modelIds): array
    {
        if ($modelIds === []) {
            return [];
        }

        return DB::table('equipamentos_catalogo_relacoes as rel')
            ->join('equipamentos_tipos as tipo', 'tipo.id', '=', 'rel.tipo_id')
            ->whereIn('rel.modelo_id', $modelIds)
            ->where('rel.ativo', 1)
            ->orderBy('tipo.nome')
            ->get(['rel.modelo_id', 'tipo.id as tipo_id', 'tipo.nome as tipo_nome'])
            ->groupBy('modelo_id')
            ->map(static fn (Collection $rows): array => $rows
                ->unique('tipo_id')
                ->map(static fn (object $row): array => ['id' => (int) $row->tipo_id, 'nome' => (string) $row->tipo_nome])
                ->values()
                ->all())
            ->all();
    }

    /**
     * @param array{search?:string,tipo_id?:int|string,ativo?:string} $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function listBrands(array $filters): Collection
    {
        $query = EquipmentBrand::query()->select(['id', 'nome', 'ativo']);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->whereRaw('LOWER(nome) LIKE ?', ['%'.mb_strtolower($search, 'UTF-8').'%']);
        }

        $ativo = (string) ($filters['ativo'] ?? '');
        if ($ativo === '1') {
            $query->where('ativo', 1);
        } elseif ($ativo === '0') {
            $query->where('ativo', 0);
        }

        $tipoId = (int) ($filters['tipo_id'] ?? 0);
        if ($tipoId > 0) {
            $query->whereExists(function ($sub) use ($tipoId): void {
                $sub->selectRaw('1')
                    ->from('equipamentos_catalogo_relacoes as rel')
                    ->whereColumn('rel.marca_id', 'equipamentos_marcas.id')
                    ->where('rel.tipo_id', $tipoId)
                    ->where('rel.ativo', 1);
            });
        }

        $brands = $query->orderBy('nome')->get();
        $brandIds = $brands->pluck('id')->all();

        $modelCounts = $brandIds === [] ? collect() : EquipmentModel::query()
            ->whereIn('marca_id', $brandIds)
            ->where('ativo', 1)
            ->where('nome', '!=', self::BRAND_SCOPE_ANCHOR_MODEL_NAME)
            ->selectRaw('marca_id, COUNT(*) as total')
            ->groupBy('marca_id')
            ->pluck('total', 'marca_id');

        $typesByBrand = $brandIds === [] ? collect() : DB::table('equipamentos_catalogo_relacoes as rel')
            ->join('equipamentos_tipos as tipo', 'tipo.id', '=', 'rel.tipo_id')
            ->whereIn('rel.marca_id', $brandIds)
            ->where('rel.ativo', 1)
            ->orderBy('tipo.nome')
            ->get(['rel.marca_id', 'tipo.id as tipo_id', 'tipo.nome as tipo_nome'])
            ->groupBy('marca_id')
            ->map(static fn (Collection $rows): array => $rows
                ->unique('tipo_id')
                ->map(static fn (object $row): array => ['id' => (int) $row->tipo_id, 'nome' => (string) $row->tipo_nome])
                ->values()
                ->all());

        return $brands->map(static fn (EquipmentBrand $brand): array => [
            'id' => (int) $brand->id,
            'nome' => (string) $brand->nome,
            'ativo' => (bool) $brand->ativo,
            'modelos_ativos' => (int) ($modelCounts[$brand->id] ?? 0),
            'tipos' => $typesByBrand[$brand->id] ?? [],
        ])->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listTypes(): Collection
    {
        $types = EquipmentType::query()->select(['id', 'nome', 'ativo'])->orderBy('nome')->get();
        $typeIds = $types->pluck('id')->all();

        $brandCounts = $typeIds === [] ? collect() : DB::table('equipamentos_catalogo_relacoes')
            ->whereIn('tipo_id', $typeIds)
            ->where('ativo', 1)
            ->select('tipo_id', DB::raw('COUNT(DISTINCT marca_id) as total'))
            ->groupBy('tipo_id')
            ->pluck('total', 'tipo_id');

        $modelCounts = $typeIds === [] ? collect() : DB::table('equipamentos_catalogo_relacoes as rel')
            ->join('equipamentos_modelos as modelo', 'modelo.id', '=', 'rel.modelo_id')
            ->whereIn('rel.tipo_id', $typeIds)
            ->where('rel.ativo', 1)
            ->where('modelo.ativo', 1)
            ->where('modelo.nome', '!=', self::BRAND_SCOPE_ANCHOR_MODEL_NAME)
            ->select('rel.tipo_id', DB::raw('COUNT(DISTINCT rel.modelo_id) as total'))
            ->groupBy('rel.tipo_id')
            ->pluck('total', 'tipo_id');

        return $types->map(static fn (EquipmentType $type): array => [
            'id' => (int) $type->id,
            'nome' => (string) $type->nome,
            'ativo' => (bool) $type->ativo,
            'marcas' => (int) ($brandCounts[$type->id] ?? 0),
            'modelos' => (int) ($modelCounts[$type->id] ?? 0),
        ])->values();
    }

    // ----------------------------------------------------------------------
    // Mutations da tela de catalogo (novo / renomear / ativar-desativar).
    // Unicidade de nome e' responsabilidade da validacao no controller
    // (Rule::unique, mesmo padrao de EstoqueCatalogController) — aqui so'
    // protegemos a ancora tecnica, que nunca deve ficar visivel/editavel.
    // ----------------------------------------------------------------------

    public function renameModel(EquipmentModel $model, string $name): EquipmentModel
    {
        $this->guardNotAnchor($model);

        $normalized = $this->normalizeImportName($name);
        if ($normalized === '' || $normalized === self::BRAND_SCOPE_ANCHOR_MODEL_NAME) {
            throw new RuntimeException('Informe um nome de modelo valido.');
        }

        $model->nome = $normalized;
        $model->updated_at = now();
        $model->save();

        return $model->fresh() ?? $model;
    }

    public function setModelActive(EquipmentModel $model, bool $active): EquipmentModel
    {
        $this->guardNotAnchor($model);

        $model->ativo = $active;
        $model->updated_at = now();
        $model->save();

        return $model->fresh() ?? $model;
    }

    public function createBrandInCatalog(string $name, int $typeId): EquipmentBrand
    {
        $normalized = $this->normalizeImportName($name);
        if ($normalized === '') {
            throw new RuntimeException('Informe um nome de marca valido.');
        }

        $brand = EquipmentBrand::query()->create([
            'nome' => $normalized,
            'ativo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->ensureBrandTypeCatalogScope($typeId, (int) $brand->id);

        return $brand->fresh() ?? $brand;
    }

    public function createModelInCatalog(int $brandId, string $name, int $typeId): EquipmentModel
    {
        $brand = EquipmentBrand::query()->find($brandId);
        if (! $brand instanceof EquipmentBrand) {
            throw new RuntimeException('Marca informada nao foi encontrada.');
        }

        $normalized = $this->normalizeImportName($name);
        if ($normalized === '' || $normalized === self::BRAND_SCOPE_ANCHOR_MODEL_NAME) {
            throw new RuntimeException('Informe um nome de modelo valido.');
        }

        $model = EquipmentModel::query()->create([
            'marca_id' => $brandId,
            'nome' => $normalized,
            'ativo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->ensureModelTypeCatalogScope($typeId, $brandId, (int) $model->id);

        return $model->fresh() ?? $model;
    }

    public function renameBrand(EquipmentBrand $brand, string $name): EquipmentBrand
    {
        $normalized = $this->normalizeImportName($name);
        if ($normalized === '') {
            throw new RuntimeException('Informe um nome de marca valido.');
        }

        $brand->nome = $normalized;
        $brand->updated_at = now();
        $brand->save();

        return $brand->fresh() ?? $brand;
    }

    public function setBrandActive(EquipmentBrand $brand, bool $active): EquipmentBrand
    {
        $brand->ativo = $active;
        $brand->updated_at = now();
        $brand->save();

        return $brand->fresh() ?? $brand;
    }

    public function createType(string $name): EquipmentType
    {
        $normalized = $this->normalizeImportName($name);
        if ($normalized === '') {
            throw new RuntimeException('Informe um nome de tipo valido.');
        }

        return EquipmentType::query()->create([
            'nome' => $normalized,
            'ativo' => true,
        ]);
    }

    public function renameType(EquipmentType $type, string $name): EquipmentType
    {
        $normalized = $this->normalizeImportName($name);
        if ($normalized === '') {
            throw new RuntimeException('Informe um nome de tipo valido.');
        }

        $type->nome = $normalized;
        $type->save();

        return $type->fresh() ?? $type;
    }

    public function setTypeActive(EquipmentType $type, bool $active): EquipmentType
    {
        $type->ativo = $active;
        $type->save();

        return $type->fresh() ?? $type;
    }

    private function guardNotAnchor(EquipmentModel $model): void
    {
        if ($model->nome === self::BRAND_SCOPE_ANCHOR_MODEL_NAME) {
            throw new RuntimeException('Este modelo e um vinculo tecnico interno do catalogo e nao pode ser alterado.');
        }
    }

    // ----------------------------------------------------------------------
    // Importacao/exportacao CSV — upsert aditivo (nunca desativa o que ficou
    // fora do arquivo; so' desativa quando a linha diz `ativo=0` de forma
    // explicita).
    // ----------------------------------------------------------------------

    /**
     * Colunas aceitas (header, `;` ou `,`): tipo;marca;modelo;ativo (padrao,
     * casa por nome) — mais duas opcionais para edicao em massa e mesclagem:
     * `id` (mira o registro exato, ignora casamento por nome) e
     * `mesclar_com_id` (funde este id no id informado; so' faz sentido junto
     * com `id`). Ver `applyImportRowById()`/`applyMergeRow()`.
     *
     * @return array{
     *     linhas:int,
     *     criados:array{marcas:int,modelos:int,relacoes:int},
     *     atualizados:int,
     *     reativados:int,
     *     desativados:int,
     *     ignorados:int,
     *     mesclados:int,
     *     mesclagens:array<int, array{de:int, para:int}>,
     *     erros:array<int, array{linha:int, motivo:string}>
     * }
     */
    public function importCsv(UploadedFile $file): array
    {
        $content = (string) file_get_contents($file->getRealPath());
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }

        if ($lines === []) {
            throw new RuntimeException('Arquivo vazio.');
        }

        $headerLine = array_shift($lines);
        $delimiter = substr_count($headerLine, ';') >= substr_count($headerLine, ',') ? ';' : ',';

        $headerRow = str_getcsv($headerLine, $delimiter, '"', '\\') ?: [];
        $headers = $this->mapHeaderColumns($headerRow);

        if (! isset($headers['tipo'], $headers['marca'], $headers['modelo'])) {
            throw new RuntimeException('Cabecalho invalido. Use o modelo de importacao (colunas tipo;marca;modelo;ativo).');
        }

        if (count($lines) > self::MAX_IMPORT_ROWS) {
            throw new RuntimeException(sprintf(
                'Arquivo excede o limite de %s linhas.',
                number_format(self::MAX_IMPORT_ROWS, 0, ',', '.')
            ));
        }

        $loserIdsInFile = $this->collectLoserIdsInFile($lines, $headers, $delimiter);

        $result = $this->emptyImportResult();
        $seen = [];
        $idStates = [];
        $processed = 0;

        DB::transaction(function () use ($lines, $headers, $delimiter, $loserIdsInFile, &$result, &$seen, &$idStates, &$processed): void {
            foreach ($lines as $offset => $line) {
                if (trim($line) === '') {
                    continue;
                }

                $lineNumber = $offset + 2; // +1 pelo cabecalho, +1 para base 1
                $row = str_getcsv($line, $delimiter, '"', '\\') ?: [];

                $data = [];
                foreach ($headers as $key => $index) {
                    $data[$key] = trim((string) ($row[$index] ?? ''));
                }

                $data += ['id' => '', 'mesclar_com_id' => ''];

                if ($data['id'] === '' && $data['tipo'] === '' && $data['marca'] === '' && $data['modelo'] === '') {
                    continue;
                }

                $processed++;

                try {
                    $this->applyImportRow($data, $seen, $result, $loserIdsInFile, $idStates);
                } catch (RuntimeException $exception) {
                    $result['erros'][] = ['linha' => $lineNumber, 'motivo' => $exception->getMessage()];
                }
            }
        });

        $result['linhas'] = $processed;

        return $result;
    }

    /**
     * Varredura previa (fora da transacao principal) so' para descobrir quais
     * ids sao "perdedores" de uma mesclagem neste MESMO arquivo — usado para
     * recusar mesclar em um alvo que tambem esta sendo mesclado para outro
     * lugar na mesma importacao (cadeia A->B->C resolvida em duas etapas).
     *
     * @param array<int, string> $lines
     * @param array<string, int> $headers
     * @return array<int, true>
     */
    private function collectLoserIdsInFile(array $lines, array $headers, string $delimiter): array
    {
        if (! isset($headers['id'], $headers['mesclar_com_id'])) {
            return [];
        }

        $loserIds = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $row = str_getcsv($line, $delimiter, '"', '\\') ?: [];
            $id = trim((string) ($row[$headers['id']] ?? ''));
            $mergeTarget = trim((string) ($row[$headers['mesclar_com_id']] ?? ''));

            if ($id !== '' && $mergeTarget !== '' && ctype_digit($id)) {
                $loserIds[(int) $id] = true;
            }
        }

        return $loserIds;
    }

    /**
     * @return array<string, int>
     */
    private function mapHeaderColumns(array $headerRow): array
    {
        $aliases = [
            'id' => ['id'],
            'tipo' => ['tipo', 'tipo_equipamento', 'grupo'],
            'marca' => ['marca', 'fabricante'],
            'modelo' => ['modelo', 'nome'],
            'ativo' => ['ativo', 'status'],
            'mesclar_com_id' => ['mesclar_com_id', 'merge_id'],
        ];

        $normalized = array_map(static function ($value): string {
            $value = preg_replace('/^\xEF\xBB\xBF/', '', (string) $value) ?? (string) $value;

            return mb_strtolower(trim($value), 'UTF-8');
        }, $headerRow);

        $map = [];
        foreach ($aliases as $canonical => $variants) {
            foreach ($variants as $variant) {
                $index = array_search($variant, $normalized, true);
                if ($index !== false) {
                    $map[$canonical] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * @param array<string, string> $data
     * @param array<string, bool> $seen
     * @param array{criados:array{marcas:int,modelos:int,relacoes:int},atualizados:int,reativados:int,desativados:int,ignorados:int,erros:array} $result
     * @param array<int, true> $loserIdsInFile
     * @param array<int, array{nome:string,ativo:bool}> $idStates
     */
    private function applyImportRow(array $data, array &$seen, array &$result, array $loserIdsInFile, array &$idStates): void
    {
        $rawId = trim((string) ($data['id'] ?? ''));
        $rawMergeTarget = trim((string) ($data['mesclar_com_id'] ?? ''));

        if ($rawId !== '') {
            $this->applyImportRowById($rawId, $rawMergeTarget, $data, $loserIdsInFile, $idStates, $result);

            return;
        }

        if ($rawMergeTarget !== '') {
            throw new RuntimeException('A coluna "mesclar_com_id" exige a coluna "id" preenchida nesta linha.');
        }

        $tipoNome = $this->normalizeImportName($data['tipo'] ?? '');
        $marcaNome = $this->normalizeImportName($data['marca'] ?? '');
        $modeloNome = $this->normalizeImportName($data['modelo'] ?? '');

        if ($tipoNome === '') {
            throw new RuntimeException('Coluna "tipo" vazia.');
        }

        if ($marcaNome === '') {
            throw new RuntimeException('Coluna "marca" vazia.');
        }

        foreach (['tipo' => $tipoNome, 'marca' => $marcaNome, 'modelo' => $modeloNome] as $campo => $valor) {
            if (mb_strlen($valor, 'UTF-8') > self::MAX_NAME_LENGTH) {
                throw new RuntimeException(sprintf('Coluna "%s" excede %d caracteres.', $campo, self::MAX_NAME_LENGTH));
            }
        }

        $ativo = $this->parseAtivoFlag($data['ativo'] ?? '');

        $type = $this->findTypeByName($tipoNome);
        if (! $type instanceof EquipmentType) {
            throw new RuntimeException(sprintf('Tipo "%s" nao existe — cadastre o tipo antes de importar.', $tipoNome));
        }

        $fingerprint = mb_strtolower($tipoNome.'|'.$marcaNome.'|'.$modeloNome, 'UTF-8');
        if (isset($seen[$fingerprint])) {
            $result['ignorados']++;

            return;
        }
        $seen[$fingerprint] = true;

        $brand = $this->findBrandByName($marcaNome);
        if (! $brand instanceof EquipmentBrand) {
            $brand = EquipmentBrand::query()->create([
                'nome' => $marcaNome,
                'ativo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $result['criados']['marcas']++;
        } elseif ($brand->nome !== $marcaNome) {
            $brand->nome = $marcaNome;
            $brand->updated_at = now();
            $brand->save();
            $result['atualizados']++;
        }

        if ($modeloNome === '') {
            // Linha so' de marca: o "ativo" da linha controla a propria marca,
            // e o escopo tipo->marca e' garantido via ancora tecnica.
            if ((bool) $brand->ativo !== $ativo) {
                $brand->ativo = $ativo;
                $brand->updated_at = now();
                $brand->save();
                $ativo ? $result['reativados']++ : $result['desativados']++;
            }

            if ($this->ensureBrandTypeCatalogScope((int) $type->id, (int) $brand->id)) {
                $result['criados']['relacoes']++;
            }

            return;
        }

        if ($modeloNome === self::BRAND_SCOPE_ANCHOR_MODEL_NAME) {
            throw new RuntimeException('Nome de modelo reservado ao sistema — escolha outro nome.');
        }

        $model = $this->findModelByName((int) $brand->id, $modeloNome);
        if (! $model instanceof EquipmentModel) {
            $model = EquipmentModel::query()->create([
                'marca_id' => $brand->id,
                'nome' => $modeloNome,
                'ativo' => $ativo,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $result['criados']['modelos']++;
        } else {
            $changedName = $model->nome !== $modeloNome;
            if ($changedName) {
                $model->nome = $modeloNome;
            }

            if ((bool) $model->ativo !== $ativo) {
                $model->ativo = $ativo;
                $model->updated_at = now();
                $model->save();
                $ativo ? $result['reativados']++ : $result['desativados']++;
            } elseif ($changedName) {
                $model->updated_at = now();
                $model->save();
                $result['atualizados']++;
            }
        }

        if ($this->ensureModelTypeCatalogScope((int) $type->id, (int) $brand->id, (int) $model->id)) {
            $result['criados']['relacoes']++;
        }

        if (! $ativo) {
            DB::table('equipamentos_catalogo_relacoes')
                ->where('tipo_id', $type->id)
                ->where('marca_id', $brand->id)
                ->where('modelo_id', $model->id)
                ->update(['ativo' => 0, 'updated_at' => now()]);
        }
    }

    // ----------------------------------------------------------------------
    // Edicao em massa por ID e mesclagem de duplicados (specs/045). Ativada
    // pela coluna opcional "id" do mesmo CSV de sempre — sem ela, o import
    // continua casando por nome exatamente como antes. Com "id" preenchido a
    // linha deixa de casar por nome e passa a mirar aquele registro exato;
    // com "mesclar_com_id" tambem preenchido, a linha vira uma mesclagem (o
    // resto da linha e' ignorado — ela so' diz "isto vira aquilo").
    // ----------------------------------------------------------------------

    /**
     * @param array<string, string> $data
     * @param array<int, true> $loserIdsInFile
     * @param array<int, array{nome:string,ativo:bool}> $idStates
     * @param array{criados:array{marcas:int,modelos:int,relacoes:int},atualizados:int,reativados:int,desativados:int,ignorados:int,mesclados:int,mesclagens:array,erros:array} $result
     */
    private function applyImportRowById(string $rawId, string $rawMergeTarget, array $data, array $loserIdsInFile, array &$idStates, array &$result): void
    {
        $model = $this->resolveModelById($rawId);

        if ($rawMergeTarget !== '') {
            $this->applyMergeRow($model, $rawMergeTarget, $loserIdsInFile, $result);

            return;
        }

        $this->applyRenameByIdRow($model, $data, $idStates, $result);
    }

    private function resolveModelById(string $rawId): EquipmentModel
    {
        if (! ctype_digit($rawId)) {
            throw new RuntimeException(sprintf('ID de modelo invalido: "%s".', $rawId));
        }

        $model = EquipmentModel::query()->find((int) $rawId);

        if (! $model instanceof EquipmentModel || $model->nome === self::BRAND_SCOPE_ANCHOR_MODEL_NAME) {
            throw new RuntimeException(sprintf('ID de modelo %s nao encontrado.', $rawId));
        }

        return $model;
    }

    /**
     * @param array<string, string> $data
     * @param array<int, array{nome:string,ativo:bool}> $idStates
     * @param array{criados:array{marcas:int,modelos:int,relacoes:int},atualizados:int,reativados:int,desativados:int,ignorados:int,mesclados:int,mesclagens:array,erros:array} $result
     */
    private function applyRenameByIdRow(EquipmentModel $model, array $data, array &$idStates, array &$result): void
    {
        $tipoNome = $this->normalizeImportName($data['tipo'] ?? '');
        $marcaNome = $this->normalizeImportName($data['marca'] ?? '');
        $modeloNome = $this->normalizeImportName($data['modelo'] ?? '');

        if ($tipoNome === '') {
            throw new RuntimeException('Coluna "tipo" vazia.');
        }

        if ($marcaNome === '') {
            throw new RuntimeException('Coluna "marca" vazia.');
        }

        if ($modeloNome === '') {
            throw new RuntimeException('Coluna "modelo" vazia.');
        }

        if (mb_strlen($modeloNome, 'UTF-8') > self::MAX_NAME_LENGTH) {
            throw new RuntimeException(sprintf('Coluna "modelo" excede %d caracteres.', self::MAX_NAME_LENGTH));
        }

        if ($modeloNome === self::BRAND_SCOPE_ANCHOR_MODEL_NAME) {
            throw new RuntimeException('Nome de modelo reservado ao sistema — escolha outro nome.');
        }

        $currentBrand = $model->brand;
        if (! $currentBrand instanceof EquipmentBrand || mb_strtolower($currentBrand->nome, 'UTF-8') !== mb_strtolower($marcaNome, 'UTF-8')) {
            throw new RuntimeException(sprintf(
                'ID %d pertence a marca "%s", mas o arquivo diz "%s" — corrija a coluna marca (reatribuir marca por CSV nao e suportado; use mesclar_com_id se o destino ja existir).',
                $model->id,
                $currentBrand->nome ?? '?',
                $marcaNome
            ));
        }

        $type = $this->findTypeByName($tipoNome);
        if (! $type instanceof EquipmentType) {
            throw new RuntimeException(sprintf('Tipo "%s" nao existe — cadastre o tipo antes de importar.', $tipoNome));
        }

        $ativo = $this->parseAtivoFlag($data['ativo'] ?? '');

        $state = ['nome' => $modeloNome, 'ativo' => $ativo];
        if (isset($idStates[$model->id])) {
            if ($idStates[$model->id] !== $state) {
                throw new RuntimeException(sprintf('ID %d ja foi processado nesta importacao com dados diferentes.', $model->id));
            }

            $result['ignorados']++;

            return;
        }
        $idStates[$model->id] = $state;

        $changedName = $model->nome !== $modeloNome;
        if ($changedName) {
            $model->nome = $modeloNome;
        }

        if ((bool) $model->ativo !== $ativo) {
            $model->ativo = $ativo;
            $model->updated_at = now();
            $model->save();
            $ativo ? $result['reativados']++ : $result['desativados']++;
        } elseif ($changedName) {
            $model->updated_at = now();
            $model->save();
            $result['atualizados']++;
        }

        if ($this->ensureModelTypeCatalogScope((int) $type->id, (int) $model->marca_id, (int) $model->id)) {
            $result['criados']['relacoes']++;
        }

        if (! $ativo) {
            DB::table('equipamentos_catalogo_relacoes')
                ->where('tipo_id', $type->id)
                ->where('marca_id', $model->marca_id)
                ->where('modelo_id', $model->id)
                ->update(['ativo' => 0, 'updated_at' => now()]);
        }
    }

    /**
     * @param array<int, true> $loserIdsInFile
     * @param array{criados:array{marcas:int,modelos:int,relacoes:int},atualizados:int,reativados:int,desativados:int,ignorados:int,mesclados:int,mesclagens:array,erros:array} $result
     */
    private function applyMergeRow(EquipmentModel $loser, string $rawMergeTarget, array $loserIdsInFile, array &$result): void
    {
        if (! ctype_digit($rawMergeTarget)) {
            throw new RuntimeException(sprintf('ID de destino da mesclagem invalido: "%s".', $rawMergeTarget));
        }

        $targetId = (int) $rawMergeTarget;

        if ($targetId === $loser->id) {
            throw new RuntimeException('Nao e possivel mesclar um modelo com ele mesmo.');
        }

        if (isset($loserIdsInFile[$targetId])) {
            throw new RuntimeException(sprintf(
                'ID de destino %d tambem esta sendo mesclado para outro lugar nesta importacao — resolva em duas etapas.',
                $targetId
            ));
        }

        $winner = EquipmentModel::query()->find($targetId);
        if (! $winner instanceof EquipmentModel || $winner->nome === self::BRAND_SCOPE_ANCHOR_MODEL_NAME) {
            throw new RuntimeException(sprintf('ID de destino da mesclagem %d nao encontrado.', $targetId));
        }

        if (! $winner->ativo) {
            throw new RuntimeException(sprintf('O modelo de destino da mesclagem (ID %d) esta inativo.', $targetId));
        }

        if ((int) $winner->marca_id !== (int) $loser->marca_id) {
            throw new RuntimeException('So e possivel mesclar modelos da mesma marca.');
        }

        $this->mergeModel($loser, $winner);

        $result['mesclados']++;
        $result['mesclagens'][] = ['de' => $loser->id, 'para' => $winner->id];
    }

    /**
     * Move todo mundo que apontava para o perdedor (aparelhos cadastrados,
     * orcamentos e o escopo de catalogo) para o vencedor, e desativa o
     * perdedor — nunca exclusao real, mesma convencao do resto do catalogo.
     *
     * `ensureCatalogRelationRecord()` (nao um UPDATE direto) evita violar a
     * unique key `(tipo_id, marca_id, modelo_id)` de `equipamentos_catalogo_relacoes`
     * quando o vencedor ja tem a mesma relacao que o perdedor.
     */
    private function mergeModel(EquipmentModel $loser, EquipmentModel $winner): void
    {
        $affectedEquipmentIds = DB::table('equipamentos')
            ->where('modelo_id', $loser->id)
            ->pluck('id')
            ->all();

        DB::table('equipamentos')
            ->where('modelo_id', $loser->id)
            ->update(['modelo_id' => $winner->id]);

        // orcamentos.equipamento_modelo_id nao tem FK nem e' lido em lugar
        // nenhum do sistema hoje — repontamos mesmo assim por integridade
        // futura (barato, uma UPDATE a mais).
        if (Schema::hasColumn('orcamentos', 'equipamento_modelo_id')) {
            DB::table('orcamentos')
                ->where('equipamento_modelo_id', $loser->id)
                ->update(['equipamento_modelo_id' => $winner->id]);
        }

        $loserRelations = DB::table('equipamentos_catalogo_relacoes')
            ->where('modelo_id', $loser->id)
            ->get(['id', 'tipo_id', 'marca_id']);

        foreach ($loserRelations as $relation) {
            $this->ensureCatalogRelationRecord((int) $relation->tipo_id, (int) $relation->marca_id, (int) $winner->id);

            DB::table('equipamentos_catalogo_relacoes')->where('id', $relation->id)->delete();
        }

        $loser->ativo = false;
        $loser->updated_at = now();
        $loser->save();

        // Busca textual da OS (os.busca_texto) e' um snapshot, nao um join ao
        // vivo — reindexa so' as OS dos aparelhos que de fato mudaram de
        // modelo, para o nome novo aparecer na busca sem esperar um
        // `php artisan os:reindexar-busca` manual.
        foreach ($affectedEquipmentIds as $equipmentId) {
            $this->orderSearchIndexService->rebuildForEquipment((int) $equipmentId);
        }
    }

    private function parseAtivoFlag(string $raw): bool
    {
        $value = mb_strtolower(trim($raw), 'UTF-8');

        if ($value === '') {
            return true;
        }

        if (in_array($value, ['1', 'sim', 'true', 'ativo', 'yes', 's'], true)) {
            return true;
        }

        if (in_array($value, ['0', 'nao', 'não', 'false', 'inativo', 'no', 'n'], true)) {
            return false;
        }

        throw new RuntimeException(sprintf('Valor de "ativo" invalido: "%s" (use 1, 0, sim ou nao).', $raw));
    }

    /**
     * @return array{criados:array{marcas:int,modelos:int,relacoes:int},atualizados:int,reativados:int,desativados:int,ignorados:int,mesclados:int,mesclagens:array<int,array{de:int,para:int}>,linhas:int,erros:array}
     */
    private function emptyImportResult(): array
    {
        return [
            'linhas' => 0,
            'criados' => ['marcas' => 0, 'modelos' => 0, 'relacoes' => 0],
            'atualizados' => 0,
            'reativados' => 0,
            'desativados' => 0,
            'ignorados' => 0,
            'mesclados' => 0,
            'mesclagens' => [],
            'erros' => [],
        ];
    }

    /**
     * Colunas: id;tipo;marca;modelo;ativo;mesclar_com_id. `id` e' o id do
     * modelo (vazio nas linhas "so' marca", que representam o escopo tecnico
     * tipo->marca sem modelo real). `mesclar_com_id` sai sempre vazio — e' a
     * coluna que o operador preenche antes de reimportar para fundir um
     * modelo duplicado no de outro id (ver `applyMergeRow()`).
     *
     * @return \Generator<int, array{0:string,1:string,2:string,3:string,4:string,5:string}>
     */
    public function exportRows(): \Generator
    {
        $rows = DB::table('equipamentos_catalogo_relacoes as rel')
            ->join('equipamentos_tipos as tipo', 'tipo.id', '=', 'rel.tipo_id')
            ->join('equipamentos_marcas as marca', 'marca.id', '=', 'rel.marca_id')
            ->join('equipamentos_modelos as modelo', 'modelo.id', '=', 'rel.modelo_id')
            ->orderBy('tipo.nome')
            ->orderBy('marca.nome')
            ->orderBy('modelo.nome')
            ->get([
                'modelo.id as modelo_id',
                'tipo.nome as tipo_nome',
                'marca.nome as marca_nome',
                'modelo.nome as modelo_nome',
                'modelo.ativo as modelo_ativo',
                'rel.ativo as rel_ativo',
            ]);

        foreach ($rows as $row) {
            if ($row->modelo_nome === self::BRAND_SCOPE_ANCHOR_MODEL_NAME) {
                yield ['', (string) $row->tipo_nome, (string) $row->marca_nome, '', $row->rel_ativo ? '1' : '0', ''];

                continue;
            }

            yield [
                (string) $row->modelo_id,
                (string) $row->tipo_nome,
                (string) $row->marca_nome,
                (string) $row->modelo_nome,
                $row->modelo_ativo ? '1' : '0',
                '',
            ];
        }
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function csvTemplateRows(): array
    {
        return [
            ['id', 'tipo', 'marca', 'modelo', 'ativo', 'mesclar_com_id'],
            ['', 'Smartphone', 'Samsung', 'Galaxy S23', '1', ''],
            ['', 'Notebook', 'Dell', 'Inspiron 15', '1', ''],
        ];
    }

    // ----------------------------------------------------------------------
    // Normalizacao de nomes
    // ----------------------------------------------------------------------

    /**
     * Usado pelo quick-add do formulario de equipamento (comportamento
     * preexistente — preservado por compatibilidade mesmo sendo imperfeito
     * para siglas/CamelCase, ex.: "ThinkPad" vira "Thinkpad").
     */
    private function normalizeTitleCaseName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Usado pela tela de catalogo e pela importacao CSV: preserva o casing
     * que o operador digitou/curou no arquivo ("iPhone", "ASUS ROG") — so'
     * remove espacos redundantes.
     */
    private function normalizeImportName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }
}
