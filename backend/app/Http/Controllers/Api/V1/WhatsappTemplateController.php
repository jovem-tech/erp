<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\WhatsappTemplate;
use App\Support\Knowledge\PlaceholderCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class WhatsappTemplateController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('conhecimento:visualizar');

        $search = trim((string) $request->query('search', $request->query('q', '')));
        $perPage = max(1, min(50, (int) $request->query('per_page', 15)));
        $active = $request->query('active');

        $query = WhatsappTemplate::query();

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
            ->orderBy('nome')
            ->paginate($perPage)
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (WhatsappTemplate $template): array => $this->mapWhatsappTemplateSummary($template))
        );

        return $this->success(
            ['whatsapp_templates' => $paginator->items()],
            meta: $this->paginationMeta($paginator),
            request: $request
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('conhecimento:criar');

        $payload = $this->validatedWhatsappTemplatePayload($request);
        $payload['created_at'] = now();
        $payload['updated_at'] = now();

        $template = WhatsappTemplate::query()->create($payload);

        return $this->success(
            ['whatsapp_template' => $this->mapWhatsappTemplateDetail($template)],
            201,
            request: $request
        );
    }

    public function show(Request $request, int $template): JsonResponse
    {
        $this->authorize('conhecimento:visualizar');

        $templateModel = WhatsappTemplate::query()->find($template);

        if (! $templateModel instanceof WhatsappTemplate) {
            return $this->error(
                'Template de WhatsApp nao encontrado.',
                404,
                'WHATSAPP_TEMPLATE_NOT_FOUND',
                null,
                request: $request
            );
        }

        return $this->success(
            ['whatsapp_template' => $this->mapWhatsappTemplateDetail($templateModel)],
            request: $request
        );
    }

    public function update(Request $request, int $template): JsonResponse
    {
        $this->authorize('conhecimento:editar');

        $templateModel = WhatsappTemplate::query()->find($template);

        if (! $templateModel instanceof WhatsappTemplate) {
            return $this->error(
                'Template de WhatsApp nao encontrado.',
                404,
                'WHATSAPP_TEMPLATE_NOT_FOUND',
                null,
                request: $request
            );
        }

        $payload = $this->validatedWhatsappTemplatePayload($request);
        $payload['updated_at'] = now();

        $templateModel->fill($payload);
        $templateModel->save();

        return $this->success(
            ['whatsapp_template' => $this->mapWhatsappTemplateDetail($templateModel->fresh() ?? $templateModel)],
            request: $request
        );
    }

    public function toggleActive(Request $request, int $template): JsonResponse
    {
        $this->authorize('conhecimento:editar');

        $templateModel = WhatsappTemplate::query()->find($template);

        if (! $templateModel instanceof WhatsappTemplate) {
            return $this->error(
                'Template de WhatsApp nao encontrado.',
                404,
                'WHATSAPP_TEMPLATE_NOT_FOUND',
                null,
                request: $request
            );
        }

        $templateModel->forceFill([
            'ativo' => ! (bool) $templateModel->ativo,
            'updated_at' => now(),
        ])->save();

        return $this->success(
            ['whatsapp_template' => $this->mapWhatsappTemplateDetail($templateModel->fresh() ?? $templateModel)],
            request: $request
        );
    }

    public function destroy(Request $request, int $template): JsonResponse
    {
        $this->authorize('conhecimento:excluir');

        $templateModel = WhatsappTemplate::query()->find($template);

        if (! $templateModel instanceof WhatsappTemplate) {
            return $this->error(
                'Template de WhatsApp nao encontrado.',
                404,
                'WHATSAPP_TEMPLATE_NOT_FOUND',
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
    private function mapWhatsappTemplateSummary(WhatsappTemplate $template): array
    {
        return [
            'id' => (int) $template->id,
            'codigo' => (string) ($template->codigo ?? ''),
            'nome' => (string) ($template->nome ?? ''),
            'evento' => (string) ($template->evento ?? ''),
            'ativo' => (bool) ($template->ativo ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapWhatsappTemplateDetail(WhatsappTemplate $template): array
    {
        return [
            'id' => (int) $template->id,
            'codigo' => (string) ($template->codigo ?? ''),
            'nome' => (string) ($template->nome ?? ''),
            'evento' => (string) ($template->evento ?? ''),
            'ativo' => (bool) ($template->ativo ?? false),
            'conteudo' => (string) ($template->conteudo ?? ''),
            'created_at' => $this->formatDateTime($template->created_at),
            'updated_at' => $this->formatDateTime($template->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedWhatsappTemplatePayload(Request $request): array
    {
        $validated = $request->validate([
            'codigo' => ['required', 'string', 'max:80'],
            'nome' => ['required', 'string', 'max:140'],
            'evento' => ['nullable', 'string', 'max:80'],
            'conteudo' => ['required', 'string'],
            'ativo' => ['nullable', 'boolean'],
        ], [], [
            'codigo' => 'codigo',
            'nome' => 'nome',
            'evento' => 'evento',
            'conteudo' => 'conteudo',
            'ativo' => 'status',
        ]);

        $payload = [];

        foreach ($validated as $field => $value) {
            if ($field === 'ativo') {
                continue;
            }

            $payload[$field] = $this->normalizeFieldValue($value);
        }

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
