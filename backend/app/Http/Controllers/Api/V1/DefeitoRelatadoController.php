<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\DefeitoRelatado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class DefeitoRelatadoController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('conhecimento:visualizar');

        $search = trim((string) $request->query('search', $request->query('q', '')));
        $perPage = max(1, min(50, (int) $request->query('per_page', 15)));
        $active = $request->query('active');
        $tipoEquipamentoId = $request->query('tipo_equipamento_id');
        $categoria = $request->query('categoria');
        $subcategoria = $request->query('subcategoria');

        $query = DefeitoRelatado::query()->with('tipoEquipamento');

        if ($search !== '') {
            $term = '%' . mb_strtolower($search) . '%';
            $query->where(static function ($builder) use ($term): void {
                $builder
                    ->whereRaw('LOWER(COALESCE(texto_relato, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(categoria, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(subcategoria, \'\')) LIKE ?', [$term]);
            });
        }

        if ($tipoEquipamentoId !== null && $tipoEquipamentoId !== '') {
            $query->where('tipo_equipamento_id', (int) $tipoEquipamentoId);
        }

        if ($categoria !== null && $categoria !== '') {
            $query->where('categoria', $categoria);
        }

        if ($subcategoria !== null && $subcategoria !== '') {
            $query->where('subcategoria', $subcategoria);
        }

        if ($active !== null && $active !== '') {
            $query->where('ativo', filter_var($active, FILTER_VALIDATE_BOOL));
        }

        $paginator = $query
            ->orderBy('tipo_equipamento_id')
            ->orderBy('ordem_exibicao')
            ->paginate($perPage)
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (DefeitoRelatado $defeito): array => $this->mapDefeitoRelatadoSummary($defeito))
        );

        return $this->success(
            ['defeitos_relatados' => $paginator->items()],
            meta: $this->paginationMeta($paginator),
            request: $request
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('conhecimento:criar');

        $payload = $this->validatedDefeitoRelatadoPayload($request);
        $payload['slug'] = $this->buildSlug((string) $payload['texto_relato']);
        $payload['created_at'] = now();
        $payload['updated_at'] = now();

        $defeito = DefeitoRelatado::query()->create($payload);

        return $this->success(
            ['defeito_relatado' => $this->mapDefeitoRelatadoDetail($defeito)],
            201,
            request: $request
        );
    }

    public function show(Request $request, int $defeito): JsonResponse
    {
        $this->authorize('conhecimento:visualizar');

        $defeitoModel = DefeitoRelatado::query()->with('tipoEquipamento')->find($defeito);

        if (! $defeitoModel instanceof DefeitoRelatado) {
            return $this->error(
                'Defeito relatado nao encontrado.',
                404,
                'REPORTED_DEFECT_NOT_FOUND',
                null,
                request: $request
            );
        }

        return $this->success(
            ['defeito_relatado' => $this->mapDefeitoRelatadoDetail($defeitoModel)],
            request: $request
        );
    }

    public function update(Request $request, int $defeito): JsonResponse
    {
        $this->authorize('conhecimento:editar');

        $defeitoModel = DefeitoRelatado::query()->find($defeito);

        if (! $defeitoModel instanceof DefeitoRelatado) {
            return $this->error(
                'Defeito relatado nao encontrado.',
                404,
                'REPORTED_DEFECT_NOT_FOUND',
                null,
                request: $request
            );
        }

        $payload = $this->validatedDefeitoRelatadoPayload($request);

        $textoChanged = $payload['texto_relato'] !== $defeitoModel->texto_relato;
        $slugEmpty = trim((string) $defeitoModel->slug) === '';

        if ($textoChanged || $slugEmpty) {
            $payload['slug'] = $this->buildSlug((string) $payload['texto_relato']);
        }

        $payload['updated_at'] = now();

        $defeitoModel->fill($payload);
        $defeitoModel->save();

        return $this->success(
            ['defeito_relatado' => $this->mapDefeitoRelatadoDetail($defeitoModel->fresh() ?? $defeitoModel)],
            request: $request
        );
    }

    public function toggleActive(Request $request, int $defeito): JsonResponse
    {
        $this->authorize('conhecimento:editar');

        $defeitoModel = DefeitoRelatado::query()->find($defeito);

        if (! $defeitoModel instanceof DefeitoRelatado) {
            return $this->error(
                'Defeito relatado nao encontrado.',
                404,
                'REPORTED_DEFECT_NOT_FOUND',
                null,
                request: $request
            );
        }

        $defeitoModel->forceFill([
            'ativo' => ! (bool) $defeitoModel->ativo,
            'updated_at' => now(),
        ])->save();

        return $this->success(
            ['defeito_relatado' => $this->mapDefeitoRelatadoDetail($defeitoModel->fresh() ?? $defeitoModel)],
            request: $request
        );
    }

    public function destroy(Request $request, int $defeito): JsonResponse
    {
        $this->authorize('conhecimento:excluir');

        $defeitoModel = DefeitoRelatado::query()->find($defeito);

        if (! $defeitoModel instanceof DefeitoRelatado) {
            return $this->error(
                'Defeito relatado nao encontrado.',
                404,
                'REPORTED_DEFECT_NOT_FOUND',
                null,
                request: $request
            );
        }

        $defeitoModel->delete();

        return $this->success([
            'deleted' => true,
            'defeito_id' => $defeito,
        ], request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapDefeitoRelatadoSummary(DefeitoRelatado $defeito): array
    {
        return [
            'id' => (int) $defeito->id,
            'tipo_equipamento_id' => $defeito->tipo_equipamento_id !== null ? (int) $defeito->tipo_equipamento_id : null,
            'tipo_equipamento_nome' => (string) ($defeito->tipoEquipamento?->nome ?? ''),
            'categoria' => (string) ($defeito->categoria ?? ''),
            'subcategoria' => (string) ($defeito->subcategoria ?? ''),
            'texto_relato' => (string) ($defeito->texto_relato ?? ''),
            'icone' => (string) ($defeito->icone ?? ''),
            'ordem_exibicao' => (int) ($defeito->ordem_exibicao ?? 0),
            'ativo' => (bool) ($defeito->ativo ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapDefeitoRelatadoDetail(DefeitoRelatado $defeito): array
    {
        return [
            'id' => (int) $defeito->id,
            'tipo_equipamento_id' => $defeito->tipo_equipamento_id !== null ? (int) $defeito->tipo_equipamento_id : null,
            'tipo_equipamento_nome' => (string) ($defeito->tipoEquipamento?->nome ?? ''),
            'categoria' => (string) ($defeito->categoria ?? ''),
            'subcategoria' => (string) ($defeito->subcategoria ?? ''),
            'texto_relato' => (string) ($defeito->texto_relato ?? ''),
            'icone' => (string) ($defeito->icone ?? ''),
            'ordem_exibicao' => (int) ($defeito->ordem_exibicao ?? 0),
            'ativo' => (bool) ($defeito->ativo ?? false),
            'slug' => (string) ($defeito->slug ?? ''),
            'observacoes' => (string) ($defeito->observacoes ?? ''),
            'created_at' => $this->formatDateTime($defeito->created_at),
            'updated_at' => $this->formatDateTime($defeito->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedDefeitoRelatadoPayload(Request $request): array
    {
        $validated = $request->validate([
            'tipo_equipamento_id' => ['nullable', 'integer', 'exists:equipamentos_tipos,id'],
            'categoria' => ['required', 'string', 'max:80'],
            'subcategoria' => ['nullable', 'string', 'max:80'],
            'texto_relato' => ['required', 'string', 'max:255'],
            'icone' => ['nullable', 'string', 'max:20'],
            'ordem_exibicao' => ['nullable', 'integer'],
            'ativo' => ['nullable', 'boolean'],
            'observacoes' => ['nullable', 'string'],
        ], [], [
            'tipo_equipamento_id' => 'tipo de equipamento',
            'categoria' => 'categoria',
            'subcategoria' => 'subcategoria',
            'texto_relato' => 'relato',
            'icone' => 'icone',
            'ordem_exibicao' => 'ordem de exibicao',
            'ativo' => 'status',
            'observacoes' => 'observacoes',
        ]);

        $payload = [];

        foreach ($validated as $field => $value) {
            if ($field === 'ativo') {
                continue;
            }

            $payload[$field] = $this->normalizeFieldValue($value);
        }

        $payload['ordem_exibicao'] = (int) ($payload['ordem_exibicao'] ?? 0);
        $payload['ativo'] = $request->boolean('ativo', true);

        return $payload;
    }

    private function buildSlug(string $texto): string
    {
        return mb_substr(Str::slug($texto), 0, 120);
    }

    private function normalizeFieldValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->toDateTimeString();
        }

        return (string) $value;
    }
}
