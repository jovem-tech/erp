<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Budget;
use App\Models\Order;
use App\Services\Pdf\PdfGenerationService;
use App\Services\Pdf\PdfTemplateAdminService;
use App\Services\Pdf\PdfTemplateRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * API do motor central de templates PDF (página Modelos PDF).
 *
 * Permissões (módulo conhecimento): visualizar (leitura + prévia),
 * editar (rascunho), publicar, restaurar.
 */
class PdfTemplateEngineController extends BaseApiController
{
    public function __construct(
        private readonly PdfTemplateAdminService $adminService,
        private readonly PdfGenerationService $generationService,
        private readonly PdfTemplateRegistry $registry
    ) {
    }

    public function types(Request $request): JsonResponse
    {
        $this->authorize('conhecimento:visualizar');

        return $this->success(['tipos' => $this->adminService->listTypes()], request: $request);
    }

    public function typeMetadata(Request $request, string $codigo): JsonResponse
    {
        $this->authorize('conhecimento:visualizar');

        $metadata = $this->adminService->typeMetadata($codigo);
        if ($metadata === null) {
            return $this->error('Tipo documental não encontrado.', 404, 'PDF_ENGINE_TYPE_NOT_FOUND', null, request: $request);
        }

        return $this->success(['tipo' => $metadata], request: $request);
    }

    public function show(Request $request, int $template): JsonResponse
    {
        $this->authorize('conhecimento:visualizar');

        $detail = $this->adminService->templateDetail($template);
        if (($detail['result'] ?? '') !== 'ok') {
            return $this->error('Template não encontrado.', 404, 'PDF_ENGINE_TEMPLATE_NOT_FOUND', null, request: $request);
        }

        return $this->success(['template' => $detail['template']], request: $request);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('conhecimento:editar');

        $validated = $request->validate([
            'nome' => ['required', 'string', 'min:3', 'max:120'],
            'descricao' => ['nullable', 'string', 'max:1000'],
            'tipo_base_codigo' => ['required', 'string', 'in:' . implode(',', $this->registry->codes())],
        ]);

        $actor = $this->authenticatedUser($request);
        $result = $this->adminService->create(
            trim((string) $validated['nome']),
            isset($validated['descricao']) ? trim((string) $validated['descricao']) : null,
            (string) $validated['tipo_base_codigo'],
            $actor?->id !== null ? (int) $actor->id : null
        );

        return match ($result['result'] ?? 'error') {
            'ok' => $this->success(['template' => $result['template']], 201, request: $request),
            'invalid_base' => $this->error('Fonte de dados inválida.', 422, 'PDF_ENGINE_BASE_TYPE_INVALID', null, request: $request),
            default => $this->error('Falha ao criar o documento.', 500, 'PDF_ENGINE_CREATE_FAILED', null, request: $request),
        };
    }

    public function cloneTemplate(Request $request, int $template): JsonResponse
    {
        $this->authorize('conhecimento:editar');

        $validated = $request->validate([
            'nome' => ['required', 'string', 'min:3', 'max:120'],
            'descricao' => ['nullable', 'string', 'max:1000'],
        ]);

        $actor = $this->authenticatedUser($request);
        $result = $this->adminService->clone(
            $template,
            trim((string) $validated['nome']),
            isset($validated['descricao']) ? trim((string) $validated['descricao']) : null,
            $actor?->id !== null ? (int) $actor->id : null
        );

        return match ($result['result'] ?? 'error') {
            'ok' => $this->success(['template' => $result['template']], 201, request: $request),
            'not_found' => $this->error('Template de origem não encontrado.', 404, 'PDF_ENGINE_TEMPLATE_NOT_FOUND', null, request: $request),
            'unknown_type' => $this->error('Tipo documental de origem não registrado.', 422, 'PDF_ENGINE_TYPE_UNKNOWN', null, request: $request),
            'no_version' => $this->error('O template de origem ainda não possui uma versão para clonar.', 422, 'PDF_ENGINE_NO_VERSION', null, request: $request),
            default => $this->error('Falha ao clonar o documento.', 500, 'PDF_ENGINE_CLONE_FAILED', null, request: $request),
        };
    }

    public function saveDraft(Request $request, int $template): JsonResponse
    {
        $this->authorize('conhecimento:editar');

        $validated = $request->validate([
            'schema' => ['required', 'array'],
            'updated_at' => ['nullable', 'string', 'max:32'],
        ]);

        $actor = $this->authenticatedUser($request);
        $result = $this->adminService->saveDraft(
            $template,
            (array) $validated['schema'],
            $validated['updated_at'] ?? null,
            $actor?->id !== null ? (int) $actor->id : null
        );

        return match ($result['result'] ?? 'error') {
            'ok' => $this->success(['rascunho' => $result['rascunho']], request: $request),
            'not_found' => $this->error('Template não encontrado.', 404, 'PDF_ENGINE_TEMPLATE_NOT_FOUND', null, request: $request),
            'unknown_type' => $this->error('Tipo documental não registrado no motor.', 422, 'PDF_ENGINE_TYPE_UNKNOWN', null, request: $request),
            'stale' => $this->error(
                'Este rascunho foi alterado por outra pessoa enquanto você editava. Recarregue para continuar.',
                409,
                'PDF_ENGINE_DRAFT_STALE',
                ['updated_at_atual' => $result['updated_at'] ?? null],
                request: $request
            ),
            'invalid' => $this->error(
                'O rascunho tem problemas estruturais.',
                422,
                'PDF_ENGINE_SCHEMA_INVALID',
                ['erros' => $result['errors'] ?? []],
                request: $request
            ),
            default => $this->error('Falha ao salvar o rascunho.', 500, 'PDF_ENGINE_DRAFT_FAILED', null, request: $request),
        };
    }

    public function publish(Request $request, int $template): JsonResponse
    {
        $this->authorize('conhecimento:publicar');

        $actor = $this->authenticatedUser($request);
        $result = $this->adminService->publish($template, $actor?->id !== null ? (int) $actor->id : null);

        return match ($result['result'] ?? 'error') {
            'ok' => $this->success(['versao_publicada' => $result['versao_publicada']], request: $request),
            'not_found' => $this->error('Template não encontrado.', 404, 'PDF_ENGINE_TEMPLATE_NOT_FOUND', null, request: $request),
            'unknown_type' => $this->error('Tipo documental não registrado no motor.', 422, 'PDF_ENGINE_TYPE_UNKNOWN', null, request: $request),
            'no_draft' => $this->error('Não há rascunho para publicar.', 422, 'PDF_ENGINE_NO_DRAFT', null, request: $request),
            'invalid' => $this->error(
                'O rascunho tem erros de validação e não pode ser publicado.',
                422,
                'PDF_ENGINE_SCHEMA_INVALID',
                ['erros' => $result['errors'] ?? []],
                request: $request
            ),
            default => $this->error('Falha ao publicar o template.', 500, 'PDF_ENGINE_PUBLISH_FAILED', null, request: $request),
        };
    }

    public function restore(Request $request, int $template, int $versao): JsonResponse
    {
        $this->authorize('conhecimento:restaurar');

        $actor = $this->authenticatedUser($request);
        $result = $this->adminService->restore($template, $versao, $actor?->id !== null ? (int) $actor->id : null);

        return match ($result['result'] ?? 'error') {
            'ok' => $this->success([
                'rascunho' => $result['rascunho'],
                'origem_versao' => $result['origem_versao'],
            ], request: $request),
            'not_found' => $this->error('Template não encontrado.', 404, 'PDF_ENGINE_TEMPLATE_NOT_FOUND', null, request: $request),
            'version_not_found' => $this->error('Versão não encontrada.', 404, 'PDF_ENGINE_VERSION_NOT_FOUND', null, request: $request),
            'draft_exists' => $this->error(
                'Já existe um rascunho em edição. Publique ou descarte-o antes de restaurar outra versão.',
                409,
                'PDF_ENGINE_DRAFT_EXISTS',
                null,
                request: $request
            ),
            default => $this->error('Falha ao restaurar a versão.', 500, 'PDF_ENGINE_RESTORE_FAILED', null, request: $request),
        };
    }

    public function versionDetail(Request $request, int $template, int $versao): JsonResponse
    {
        $this->authorize('conhecimento:visualizar');

        $result = $this->adminService->versionDetail($template, $versao);
        if (($result['result'] ?? '') !== 'ok') {
            return $this->error('Versão não encontrada.', 404, 'PDF_ENGINE_VERSION_NOT_FOUND', null, request: $request);
        }

        return $this->success(['versao' => $result['versao']], request: $request);
    }

    public function compare(Request $request, int $template): JsonResponse
    {
        $this->authorize('conhecimento:visualizar');

        $validated = $request->validate([
            'de' => ['required', 'integer', 'min:1'],
            'para' => ['required', 'integer', 'min:1'],
        ]);

        $result = $this->adminService->compare($template, (int) $validated['de'], (int) $validated['para']);
        if (($result['result'] ?? '') !== 'ok') {
            return $this->error('Versão não encontrada para comparação.', 404, 'PDF_ENGINE_VERSION_NOT_FOUND', null, request: $request);
        }

        return $this->success([
            'de' => $result['de'],
            'para' => $result['para'],
            'resumo' => $result['resumo'],
        ], request: $request);
    }

    /**
     * Prévia em PDF: schema enviado pelo editor (rascunho em edição) ou uma
     * versão salva; entidade real (id) ou contexto simulado.
     */
    public function preview(Request $request, int $template): Response|JsonResponse
    {
        $this->authorize('conhecimento:visualizar');

        $validated = $request->validate([
            'schema' => ['nullable', 'array'],
            'versao' => ['nullable', 'integer', 'min:1'],
            'formato' => ['nullable', 'in:a4,80mm'],
            'entidade_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $detail = $this->adminService->templateDetail($template);
        if (($detail['result'] ?? '') !== 'ok') {
            return $this->error('Template não encontrado.', 404, 'PDF_ENGINE_TEMPLATE_NOT_FOUND', null, request: $request);
        }

        $tipoCodigo = (string) $detail['template']['tipo_codigo'];

        $schema = is_array($validated['schema'] ?? null) ? $validated['schema'] : null;
        if ($schema === null && ($validated['versao'] ?? null) !== null) {
            $versaoDetail = $this->adminService->versionDetail($template, (int) $validated['versao']);
            $schema = ($versaoDetail['result'] ?? '') === 'ok' ? (array) $versaoDetail['versao']['schema'] : null;
        }
        if ($schema === null) {
            $schema = (array) ($detail['template']['rascunho']['schema']
                ?? $detail['template']['versao_publicada']['schema']
                ?? []);
        }

        if ($schema === []) {
            return $this->error('Nenhum schema disponível para pré-visualizar.', 422, 'PDF_ENGINE_NO_SCHEMA', null, request: $request);
        }

        $subject = $this->resolvePreviewSubject($tipoCodigo, $validated['entidade_id'] ?? null);
        if (($validated['entidade_id'] ?? null) !== null && $subject === null) {
            return $this->error('Entidade informada para a prévia não foi encontrada.', 404, 'PDF_ENGINE_PREVIEW_ENTITY_NOT_FOUND', null, request: $request);
        }

        $result = $this->generationService->renderPreview($tipoCodigo, $schema, $subject, [
            'formato' => (string) ($validated['formato'] ?? 'a4'),
        ]);

        if (! ($result['ok'] ?? false)) {
            return $this->error((string) ($result['message'] ?? 'Falha na prévia.'), 422, 'PDF_ENGINE_PREVIEW_FAILED', null, request: $request);
        }

        return response((string) $result['bytes'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="previa-' . $tipoCodigo . '.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePreviewSubject(string $tipoCodigo, mixed $entidadeId): ?array
    {
        $id = (int) ($entidadeId ?? 0);
        if ($id <= 0) {
            return null;
        }

        $descriptor = $this->registry->get($tipoCodigo);
        if (($descriptor['tipo_base_codigo'] ?? $tipoCodigo) === 'os_orcamento') {
            $budget = Budget::query()->find($id);

            return $budget instanceof Budget ? ['budget' => $budget] : null;
        }

        $order = Order::query()->find($id);

        return $order instanceof Order ? ['order' => $order] : null;
    }
}
