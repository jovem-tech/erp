<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\EquipamentoDefeito;
use App\Models\EquipamentoDefeitoProcedimento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EquipamentoDefeitoController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('conhecimento:visualizar');

        $search = trim((string) $request->query('search', $request->query('q', '')));
        $perPage = max(1, min(50, (int) $request->query('per_page', 15)));
        $active = $request->query('active');
        $tipoId = $request->query('tipo_id');
        $classificacao = $request->query('classificacao');

        $query = EquipamentoDefeito::query()->with('tipo')->withCount('procedimentos');

        if ($search !== '') {
            $term = '%' . mb_strtolower($search) . '%';
            $query->where(static function ($builder) use ($term): void {
                $builder
                    ->whereRaw('LOWER(COALESCE(nome, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(descricao, \'\')) LIKE ?', [$term]);
            });
        }

        if ($tipoId !== null && $tipoId !== '') {
            $query->where('tipo_id', (int) $tipoId);
        }

        if ($classificacao !== null && $classificacao !== '' && in_array($classificacao, ['hardware', 'software'], true)) {
            $query->where('classificacao', $classificacao);
        }

        if ($active !== null && $active !== '') {
            $query->where('ativo', filter_var($active, FILTER_VALIDATE_BOOL));
        }

        $paginator = $query
            ->orderBy('nome')
            ->paginate($perPage)
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (EquipamentoDefeito $defeito): array => $this->mapEquipamentoDefeitoSummary($defeito))
        );

        return $this->success(
            ['equipamentos_defeitos' => $paginator->items()],
            meta: $this->paginationMeta($paginator),
            request: $request
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('conhecimento:criar');

        $payload = $this->validatedEquipamentoDefeitoPayload($request);

        $defeito = EquipamentoDefeito::query()->create($payload);

        return $this->success(
            ['equipamento_defeito' => $this->mapEquipamentoDefeitoDetail($defeito->fresh(['tipo', 'procedimentos']) ?? $defeito)],
            201,
            request: $request
        );
    }

    public function show(Request $request, int $defeito): JsonResponse
    {
        $this->authorize('conhecimento:visualizar');

        $defeitoModel = EquipamentoDefeito::query()->with(['tipo', 'procedimentos'])->find($defeito);

        if (! $defeitoModel instanceof EquipamentoDefeito) {
            return $this->error(
                'Defeito de equipamento nao encontrado.',
                404,
                'EQUIPMENT_DEFECT_NOT_FOUND',
                null,
                request: $request
            );
        }

        return $this->success(
            ['equipamento_defeito' => $this->mapEquipamentoDefeitoDetail($defeitoModel)],
            request: $request
        );
    }

    public function update(Request $request, int $defeito): JsonResponse
    {
        $this->authorize('conhecimento:editar');

        $defeitoModel = EquipamentoDefeito::query()->find($defeito);

        if (! $defeitoModel instanceof EquipamentoDefeito) {
            return $this->error(
                'Defeito de equipamento nao encontrado.',
                404,
                'EQUIPMENT_DEFECT_NOT_FOUND',
                null,
                request: $request
            );
        }

        $payload = $this->validatedEquipamentoDefeitoPayload($request);

        $defeitoModel->fill($payload);
        $defeitoModel->save();

        return $this->success(
            ['equipamento_defeito' => $this->mapEquipamentoDefeitoDetail($defeitoModel->fresh(['tipo', 'procedimentos']) ?? $defeitoModel)],
            request: $request
        );
    }

    public function toggleActive(Request $request, int $defeito): JsonResponse
    {
        $this->authorize('conhecimento:editar');

        $defeitoModel = EquipamentoDefeito::query()->find($defeito);

        if (! $defeitoModel instanceof EquipamentoDefeito) {
            return $this->error(
                'Defeito de equipamento nao encontrado.',
                404,
                'EQUIPMENT_DEFECT_NOT_FOUND',
                null,
                request: $request
            );
        }

        $defeitoModel->forceFill([
            'ativo' => ! (bool) $defeitoModel->ativo,
            'updated_at' => now(),
        ])->save();

        return $this->success(
            ['equipamento_defeito' => $this->mapEquipamentoDefeitoDetail($defeitoModel->fresh(['tipo', 'procedimentos']) ?? $defeitoModel)],
            request: $request
        );
    }

    public function destroy(Request $request, int $defeito): JsonResponse
    {
        $this->authorize('conhecimento:excluir');

        $defeitoModel = EquipamentoDefeito::query()->find($defeito);

        if (! $defeitoModel instanceof EquipamentoDefeito) {
            return $this->error(
                'Defeito de equipamento nao encontrado.',
                404,
                'EQUIPMENT_DEFECT_NOT_FOUND',
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

    public function storeProcedimento(Request $request, int $defeito): JsonResponse
    {
        $this->authorize('conhecimento:editar');

        $defeitoModel = EquipamentoDefeito::query()->find($defeito);

        if (! $defeitoModel instanceof EquipamentoDefeito) {
            return $this->error(
                'Defeito de equipamento nao encontrado.',
                404,
                'EQUIPMENT_DEFECT_NOT_FOUND',
                null,
                request: $request
            );
        }

        $validated = $request->validate([
            'descricao' => ['required', 'string', 'max:255'],
        ]);

        $nextOrdem = ((int) $defeitoModel->procedimentos()->max('ordem')) + 1;

        EquipamentoDefeitoProcedimento::query()->create([
            'defeito_id' => $defeitoModel->id,
            'descricao' => trim((string) $validated['descricao']),
            'ordem' => $nextOrdem,
        ]);

        return $this->success(
            ['equipamento_defeito' => $this->mapEquipamentoDefeitoDetail($defeitoModel->fresh(['tipo', 'procedimentos']) ?? $defeitoModel)],
            request: $request
        );
    }

    public function updateProcedimento(Request $request, int $defeito, int $procedimento): JsonResponse
    {
        $this->authorize('conhecimento:editar');

        $defeitoModel = EquipamentoDefeito::query()->find($defeito);

        if (! $defeitoModel instanceof EquipamentoDefeito) {
            return $this->error(
                'Defeito de equipamento nao encontrado.',
                404,
                'EQUIPMENT_DEFECT_NOT_FOUND',
                null,
                request: $request
            );
        }

        $procedimentoModel = EquipamentoDefeitoProcedimento::query()
            ->where('defeito_id', $defeito)
            ->find($procedimento);

        if (! $procedimentoModel instanceof EquipamentoDefeitoProcedimento) {
            return $this->error(
                'Procedimento de defeito nao encontrado.',
                404,
                'EQUIPMENT_DEFECT_PROCEDURE_NOT_FOUND',
                null,
                request: $request
            );
        }

        $validated = $request->validate([
            'descricao' => ['required', 'string', 'max:255'],
        ]);

        $procedimentoModel->descricao = trim((string) $validated['descricao']);
        $procedimentoModel->save();

        return $this->success(
            ['equipamento_defeito' => $this->mapEquipamentoDefeitoDetail($defeitoModel->fresh(['tipo', 'procedimentos']) ?? $defeitoModel)],
            request: $request
        );
    }

    public function destroyProcedimento(Request $request, int $defeito, int $procedimento): JsonResponse
    {
        $this->authorize('conhecimento:editar');

        $defeitoModel = EquipamentoDefeito::query()->find($defeito);

        if (! $defeitoModel instanceof EquipamentoDefeito) {
            return $this->error(
                'Defeito de equipamento nao encontrado.',
                404,
                'EQUIPMENT_DEFECT_NOT_FOUND',
                null,
                request: $request
            );
        }

        $procedimentoModel = EquipamentoDefeitoProcedimento::query()
            ->where('defeito_id', $defeito)
            ->find($procedimento);

        if (! $procedimentoModel instanceof EquipamentoDefeitoProcedimento) {
            return $this->error(
                'Procedimento de defeito nao encontrado.',
                404,
                'EQUIPMENT_DEFECT_PROCEDURE_NOT_FOUND',
                null,
                request: $request
            );
        }

        $procedimentoModel->delete();

        return $this->success(
            ['equipamento_defeito' => $this->mapEquipamentoDefeitoDetail($defeitoModel->fresh(['tipo', 'procedimentos']) ?? $defeitoModel)],
            request: $request
        );
    }

    public function moveProcedimento(Request $request, int $defeito, int $procedimento): JsonResponse
    {
        $this->authorize('conhecimento:editar');

        $defeitoModel = EquipamentoDefeito::query()->find($defeito);

        if (! $defeitoModel instanceof EquipamentoDefeito) {
            return $this->error(
                'Defeito de equipamento nao encontrado.',
                404,
                'EQUIPMENT_DEFECT_NOT_FOUND',
                null,
                request: $request
            );
        }

        $procedimentoModel = EquipamentoDefeitoProcedimento::query()
            ->where('defeito_id', $defeito)
            ->find($procedimento);

        if (! $procedimentoModel instanceof EquipamentoDefeitoProcedimento) {
            return $this->error(
                'Procedimento de defeito nao encontrado.',
                404,
                'EQUIPMENT_DEFECT_PROCEDURE_NOT_FOUND',
                null,
                request: $request
            );
        }

        $validated = $request->validate([
            'direction' => ['required', 'string', 'in:up,down'],
        ]);

        DB::transaction(function () use ($defeito, $procedimentoModel, $validated): void {
            $siblings = EquipamentoDefeitoProcedimento::query()
                ->where('defeito_id', $defeito)
                ->orderBy('ordem')
                ->get();

            $currentIndex = $siblings->search(
                fn (EquipamentoDefeitoProcedimento $sibling): bool => $sibling->id === $procedimentoModel->id
            );

            if ($currentIndex === false) {
                return;
            }

            $adjacentIndex = $validated['direction'] === 'up' ? $currentIndex - 1 : $currentIndex + 1;

            if ($adjacentIndex < 0 || $adjacentIndex >= $siblings->count()) {
                return;
            }

            $adjacent = $siblings->get($adjacentIndex);
            $current = $siblings->get($currentIndex);

            $currentOrdem = $current->ordem;
            $adjacentOrdem = $adjacent->ordem;

            $current->update(['ordem' => $adjacentOrdem]);
            $adjacent->update(['ordem' => $currentOrdem]);
        });

        return $this->success(
            ['equipamento_defeito' => $this->mapEquipamentoDefeitoDetail($defeitoModel->fresh(['tipo', 'procedimentos']) ?? $defeitoModel)],
            request: $request
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mapEquipamentoDefeitoSummary(EquipamentoDefeito $defeito): array
    {
        return [
            'id' => (int) $defeito->id,
            'nome' => (string) ($defeito->nome ?? ''),
            'tipo_id' => $defeito->tipo_id !== null ? (int) $defeito->tipo_id : null,
            'tipo_nome' => (string) ($defeito->tipo?->nome ?? ''),
            'classificacao' => (string) ($defeito->classificacao ?? ''),
            'descricao' => (string) ($defeito->descricao ?? ''),
            'ativo' => (bool) ($defeito->ativo ?? false),
            'procedimentos_count' => (int) ($defeito->procedimentos_count ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapEquipamentoDefeitoDetail(EquipamentoDefeito $defeito): array
    {
        return [
            'id' => (int) $defeito->id,
            'nome' => (string) ($defeito->nome ?? ''),
            'tipo_id' => $defeito->tipo_id !== null ? (int) $defeito->tipo_id : null,
            'tipo_nome' => (string) ($defeito->tipo?->nome ?? ''),
            'classificacao' => (string) ($defeito->classificacao ?? ''),
            'descricao' => (string) ($defeito->descricao ?? ''),
            'ativo' => (bool) ($defeito->ativo ?? false),
            'created_at' => $this->formatDateTime($defeito->created_at),
            'updated_at' => $this->formatDateTime($defeito->updated_at),
            'procedimentos' => $defeito->procedimentos->map(static fn (EquipamentoDefeitoProcedimento $procedimento): array => [
                'id' => (int) $procedimento->id,
                'descricao' => (string) ($procedimento->descricao ?? ''),
                'ordem' => (int) ($procedimento->ordem ?? 0),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedEquipamentoDefeitoPayload(Request $request): array
    {
        $validated = $request->validate([
            'nome' => ['required', 'string', 'max:150'],
            'tipo_id' => ['required', 'integer', 'exists:equipamentos_tipos,id'],
            'classificacao' => ['required', 'string', 'in:hardware,software'],
            'descricao' => ['nullable', 'string'],
            'ativo' => ['nullable', 'boolean'],
        ], [], [
            'nome' => 'nome',
            'tipo_id' => 'tipo de equipamento',
            'classificacao' => 'classificacao',
            'descricao' => 'descricao',
            'ativo' => 'status',
        ]);

        $payload = [];

        foreach ($validated as $field => $value) {
            if ($field === 'ativo') {
                continue;
            }

            $payload[$field] = $this->normalizeFieldValue($value);
        }

        $payload['tipo_id'] = (int) $payload['tipo_id'];
        $payload['ativo'] = $request->boolean('ativo', true);

        return $payload;
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
