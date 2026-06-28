<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\PdfTemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PdfTemplateController extends DesktopController
{
    public function __construct(
        private readonly PdfTemplateService $pdfTemplateService
    ) {
    }

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'active' => $request->query('active', ''),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 15),
        ];

        $result = $this->pdfTemplateService->paginate(array_filter(
            $filters,
            static fn ($value): bool => $value !== '' && $value !== 0
        ));

        return view('knowledge.pdf-templates.index', [
            'pageTitle' => 'Modelos PDF',
            'templates' => $result['items'],
            'pagination' => $result['pagination'],
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        return view('knowledge.pdf-templates.create', [
            'pageTitle' => 'Novo modelo de PDF',
            'template' => $this->templateFormDefaults(),
            'placeholders' => $this->pdfTemplateService->placeholders(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validatedTemplatePayload($request);

        try {
            $this->pdfTemplateService->create($payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.pdf-templates.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput($request->except('ativo'))
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput($request->except('ativo'))
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos do modelo de PDF.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput($request->except('ativo'))
                ->with('error', 'Não foi possível cadastrar o modelo de PDF agora. Tente novamente.');
        }

        return redirect()
            ->route('knowledge.pdf-templates.index')
            ->with('success', 'Modelo de PDF cadastrado com sucesso.');
    }

    public function edit(int $template): View|RedirectResponse
    {
        try {
            $templateData = $this->pdfTemplateService->find($template);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.pdf-templates.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            if ($exception->statusCode() === 404) {
                abort(404);
            }

            return redirect()->route('knowledge.pdf-templates.index')->with('error', $exception->getMessage());
        }

        if ($templateData === []) {
            abort(404);
        }

        return view('knowledge.pdf-templates.edit', [
            'pageTitle' => 'Editar modelo de PDF',
            'template' => $templateData,
            'placeholders' => $this->pdfTemplateService->placeholders(),
        ]);
    }

    public function update(Request $request, int $template): RedirectResponse
    {
        $payload = $this->validatedTemplatePayload($request);

        try {
            $this->pdfTemplateService->update($template, $payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.pdf-templates.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput($request->except('ativo'))
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput($request->except('ativo'))
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos do modelo de PDF.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput($request->except('ativo'))
                ->with('error', 'Não foi possível atualizar o modelo de PDF agora. Tente novamente.');
        }

        return redirect()
            ->route('knowledge.pdf-templates.index')
            ->with('success', 'Modelo de PDF atualizado com sucesso.');
    }

    public function toggleActive(int $template): RedirectResponse
    {
        try {
            $this->pdfTemplateService->toggleActive($template);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.pdf-templates.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('knowledge.pdf-templates.index')->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('knowledge.pdf-templates.index')
            ->with('success', 'Status atualizado com sucesso.');
    }

    public function destroy(int $template): RedirectResponse
    {
        try {
            $this->pdfTemplateService->destroy($template);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.pdf-templates.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('knowledge.pdf-templates.index')->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('knowledge.pdf-templates.index')
            ->with('success', 'Modelo de PDF excluido com sucesso.');
    }

    /**
     * @return array<string, mixed>
     */
    private function templateFormDefaults(): array
    {
        return [
            'id' => null,
            'codigo' => '',
            'nome' => '',
            'descricao' => '',
            'conteudo_html' => '',
            'ordem' => 0,
            'ativo' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedTemplatePayload(Request $request): array
    {
        $validated = $request->validate([
            'codigo' => ['required', 'string', 'max:255'],
            'nome' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string'],
            'conteudo_html' => ['required', 'string'],
            'ordem' => ['nullable', 'integer'],
            'ativo' => ['nullable', 'boolean'],
        ], [], [
            'codigo' => 'código',
            'nome' => 'nome',
            'descricao' => 'descrição',
            'conteudo_html' => 'conteúdo HTML',
            'ordem' => 'ordem',
            'ativo' => 'status',
        ]);

        $payload = [];

        foreach ($validated as $field => $value) {
            if ($field === 'ativo') {
                continue;
            }

            $payload[$field] = $this->normalizeValue($value);
        }

        $payload['ordem'] = (int) ($payload['ordem'] ?? 0);
        $payload['ativo'] = $request->boolean('ativo', true);

        return $payload;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function formatApiErrors(ApiRequestException $exception): array
    {
        $details = $exception->details();

        if (! is_array($details)) {
            return [];
        }

        $errors = [];

        foreach ($details as $field => $messages) {
            if (! is_string($field) || ! is_array($messages)) {
                continue;
            }

            $errors[$field] = array_values(array_filter($messages, 'is_string'));
        }

        return $errors;
    }
}
