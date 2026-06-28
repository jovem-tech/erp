<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\OsPdfTemplate;
use App\Support\Knowledge\PlaceholderCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class OsPdfTemplateController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('conhecimento:visualizar');

        $search = trim((string) $request->query('search', $request->query('q', '')));
        $perPage = max(1, min(50, (int) $request->query('per_page', 15)));
        $active = $request->query('active');

        $query = OsPdfTemplate::query();

        if ($search !== '') {
            $term = '%' . mb_strtolower($search) . '%';
            $query->where(static function ($builder) use ($term): void {
                $builder
                    ->whereRaw('LOWER(COALESCE(codigo, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(nome, \'\')) LIKE ?', [$term]);
            });
        }

        if ($active !== null && $active !== '') {
            $query->where('ativo', filter_var($active, FILTER_VALIDATE_BOOL));
        }

        $paginator = $query
            ->orderBy('ordem')
            ->paginate($perPage)
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (OsPdfTemplate $template): array => $this->mapOsPdfTemplateSummary($template))
        );

        return $this->success(
            ['os_pdf_templates' => $paginator->items()],
            meta: $this->paginationMeta($paginator),
            request: $request
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('conhecimento:criar');

        $payload = $this->validatedOsPdfTemplatePayload($request);
        $payload['created_at'] = now();
        $payload['updated_at'] = now();

        $template = OsPdfTemplate::query()->create($payload);

        return $this->success(
            ['os_pdf_template' => $this->mapOsPdfTemplateDetail($template)],
            201,
            request: $request
        );
    }

    public function show(Request $request, int $template): JsonResponse
    {
        $this->authorize('conhecimento:visualizar');

        $templateModel = OsPdfTemplate::query()->find($template);

        if (! $templateModel instanceof OsPdfTemplate) {
            return $this->error(
                'Modelo de PDF nao encontrado.',
                404,
                'PDF_TEMPLATE_NOT_FOUND',
                null,
                request: $request
            );
        }

        return $this->success(
            ['os_pdf_template' => $this->mapOsPdfTemplateDetail($templateModel)],
            request: $request
        );
    }

    public function update(Request $request, int $template): JsonResponse
    {
        $this->authorize('conhecimento:editar');

        $templateModel = OsPdfTemplate::query()->find($template);

        if (! $templateModel instanceof OsPdfTemplate) {
            return $this->error(
                'Modelo de PDF nao encontrado.',
                404,
                'PDF_TEMPLATE_NOT_FOUND',
                null,
                request: $request
            );
        }

        $payload = $this->validatedOsPdfTemplatePayload($request);
        $payload['updated_at'] = now();

        $templateModel->fill($payload);
        $templateModel->save();

        return $this->success(
            ['os_pdf_template' => $this->mapOsPdfTemplateDetail($templateModel->fresh() ?? $templateModel)],
            request: $request
        );
    }

    public function toggleActive(Request $request, int $template): JsonResponse
    {
        $this->authorize('conhecimento:editar');

        $templateModel = OsPdfTemplate::query()->find($template);

        if (! $templateModel instanceof OsPdfTemplate) {
            return $this->error(
                'Modelo de PDF nao encontrado.',
                404,
                'PDF_TEMPLATE_NOT_FOUND',
                null,
                request: $request
            );
        }

        $templateModel->forceFill([
            'ativo' => ! (bool) $templateModel->ativo,
            'updated_at' => now(),
        ])->save();

        return $this->success(
            ['os_pdf_template' => $this->mapOsPdfTemplateDetail($templateModel->fresh() ?? $templateModel)],
            request: $request
        );
    }

    public function destroy(Request $request, int $template): JsonResponse
    {
        $this->authorize('conhecimento:excluir');

        $templateModel = OsPdfTemplate::query()->find($template);

        if (! $templateModel instanceof OsPdfTemplate) {
            return $this->error(
                'Modelo de PDF nao encontrado.',
                404,
                'PDF_TEMPLATE_NOT_FOUND',
                null,
                request: $request
            );
        }

        $templateModel->delete();

        return $this->success([
            'deleted' => true,
            'template_id' => $template,
        ], request: $request);
    }

    public function placeholders(Request $request): JsonResponse
    {
        $this->authorize('conhecimento:visualizar');

        return $this->success(['placeholders' => PlaceholderCatalog::all()], request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapOsPdfTemplateSummary(OsPdfTemplate $template): array
    {
        return [
            'id' => (int) $template->id,
            'codigo' => (string) ($template->codigo ?? ''),
            'nome' => (string) ($template->nome ?? ''),
            'ordem' => (int) ($template->ordem ?? 0),
            'ativo' => (bool) ($template->ativo ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapOsPdfTemplateDetail(OsPdfTemplate $template): array
    {
        return [
            'id' => (int) $template->id,
            'codigo' => (string) ($template->codigo ?? ''),
            'nome' => (string) ($template->nome ?? ''),
            'ordem' => (int) ($template->ordem ?? 0),
            'ativo' => (bool) ($template->ativo ?? false),
            'descricao' => (string) ($template->descricao ?? ''),
            'conteudo_html' => (string) ($template->conteudo_html ?? ''),
            'created_at' => $this->formatDateTime($template->created_at),
            'updated_at' => $this->formatDateTime($template->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedOsPdfTemplatePayload(Request $request): array
    {
        $validated = $request->validate([
            'codigo' => ['required', 'string', 'max:255'],
            'nome' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string'],
            'conteudo_html' => ['required', 'string'],
            'ordem' => ['nullable', 'integer'],
            'ativo' => ['nullable', 'boolean'],
        ], [], [
            'codigo' => 'codigo',
            'nome' => 'nome',
            'descricao' => 'descricao',
            'conteudo_html' => 'conteudo HTML',
            'ordem' => 'ordem',
            'ativo' => 'status',
        ]);

        $payload = [];

        foreach ($validated as $field => $value) {
            if ($field === 'ativo') {
                continue;
            }

            $payload[$field] = $this->normalizeFieldValue($value);
        }

        $payload['ordem'] = (int) ($payload['ordem'] ?? 0);
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
