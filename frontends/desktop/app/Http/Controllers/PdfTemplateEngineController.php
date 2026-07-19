<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\PdfTemplateEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Página "Modelos PDF" — motor central de documentos: lista dos tipos
 * documentais registrados e editor de blocos com rascunho/publicação/
 * versões/prévia.
 */
class PdfTemplateEngineController extends DesktopController
{
    public function __construct(
        private readonly PdfTemplateEngineService $engineService
    ) {
    }

    public function index(): View|RedirectResponse
    {
        try {
            $tipos = $this->engineService->types();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return redirect()->route('dashboard')->with('error', $exception->getMessage());
        }

        return view('knowledge.pdf-templates.engine-index', [
            'pageTitle' => 'Modelos PDF',
            'tipos' => $tipos,
        ]);
    }

    /**
     * Mantém links antigos funcionando sem permitir edições no catálogo
     * HTML aposentado. Toda gestão passa pelo motor central versionado.
     */
    public function legacyRetired(): RedirectResponse
    {
        return redirect()
            ->route('knowledge.pdf-engine.index')
            ->with('info', 'Os modelos HTML foram substituídos pelo editor central e versionado de PDFs.');
    }

    public function edit(int $template): View|RedirectResponse
    {
        try {
            $templateData = $this->engineService->template($template);
            $metadata = $this->engineService->typeMetadata((string) ($templateData['tipo_codigo'] ?? ''));
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.pdf-engine.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            if ($exception->statusCode() === 404) {
                abort(404);
            }

            return redirect()->route('knowledge.pdf-engine.index')->with('error', $exception->getMessage());
        }

        if ($templateData === []) {
            abort(404);
        }

        return view('knowledge.pdf-templates.engine-edit', [
            'pageTitle' => 'Editor de modelo — ' . (string) ($templateData['nome'] ?? 'Modelo PDF'),
            'template' => $templateData,
            'metadata' => $metadata,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nome' => ['required', 'string', 'min:3', 'max:120'],
            'descricao' => ['nullable', 'string', 'max:1000'],
            'tipo_base_codigo' => ['required', 'string', 'in:os_abertura,os_orcamento,os_encerramento'],
        ]);

        try {
            $template = $this->engineService->create(
                trim((string) $validated['nome']),
                isset($validated['descricao']) ? trim((string) $validated['descricao']) : null,
                (string) $validated['tipo_base_codigo']
            );
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('knowledge.pdf-engine.edit', ['template' => (int) ($template['id'] ?? 0)])
            ->with('success', 'Documento criado como rascunho. Personalize e publique quando estiver pronto.');
    }

    public function cloneTemplate(Request $request, int $template): RedirectResponse
    {
        $validated = $request->validate([
            'nome' => ['required', 'string', 'min:3', 'max:120'],
            'descricao' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $copy = $this->engineService->cloneTemplate(
                $template,
                trim((string) $validated['nome']),
                isset($validated['descricao']) ? trim((string) $validated['descricao']) : null
            );
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('knowledge.pdf-engine.edit', ['template' => (int) ($copy['id'] ?? 0)])
            ->with('success', 'Documento clonado. O original permanece inalterado.');
    }

    public function saveDraft(Request $request, int $template): JsonResponse
    {
        $validated = $request->validate([
            'schema' => ['required', 'array'],
            'updated_at' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $rascunho = $this->engineService->saveDraft(
                $template,
                (array) $validated['schema'],
                $validated['updated_at'] ?? null
            );
        } catch (ApiAuthenticationException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 401);
        } catch (ApiAuthorizationException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (ApiRequestException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'detalhes' => $exception->details(),
            ], in_array($exception->statusCode(), [409, 422], true) ? $exception->statusCode() : 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Rascunho salvo.',
            'rascunho' => $rascunho,
        ]);
    }

    public function publish(int $template): JsonResponse
    {
        try {
            $versao = $this->engineService->publish($template);
        } catch (ApiAuthenticationException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 401);
        } catch (ApiAuthorizationException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (ApiRequestException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'detalhes' => $exception->details(),
            ], $exception->statusCode() === 422 ? 422 : 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Template publicado. Novas emissões já usam esta versão.',
            'versao_publicada' => $versao,
        ]);
    }

    public function restore(int $template, int $versao): JsonResponse
    {
        try {
            $result = $this->engineService->restore($template, $versao);
        } catch (ApiAuthenticationException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 401);
        } catch (ApiAuthorizationException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (ApiRequestException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], in_array($exception->statusCode(), [404, 409], true) ? $exception->statusCode() : 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Versão restaurada como novo rascunho.',
            'dados' => $result,
        ]);
    }

    public function preview(Request $request, int $template): Response|JsonResponse
    {
        $validated = $request->validate([
            'schema' => ['nullable', 'array'],
            'versao' => ['nullable', 'integer', 'min:1'],
            'formato' => ['nullable', 'in:a4,80mm'],
            'entidade_id' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $download = $this->engineService->preview($template, array_filter([
                'schema' => $validated['schema'] ?? null,
                'versao' => $validated['versao'] ?? null,
                'formato' => $validated['formato'] ?? 'a4',
                'entidade_id' => $validated['entidade_id'] ?? null,
            ], static fn ($value): bool => $value !== null));
        } catch (ApiAuthenticationException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 401);
        } catch (ApiAuthorizationException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (ApiRequestException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'detalhes' => $exception->details(),
            ], in_array($exception->statusCode(), [404, 422], true) ? $exception->statusCode() : 500);
        }

        return response($download['body'], 200, $download['headers']);
    }
}
