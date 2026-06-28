<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\WhatsappTemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class WhatsappTemplateController extends DesktopController
{
    public function __construct(
        private readonly WhatsappTemplateService $whatsappTemplateService
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

        $result = $this->whatsappTemplateService->paginate(array_filter(
            $filters,
            static fn ($value): bool => $value !== '' && $value !== 0
        ));

        return view('knowledge.whatsapp-templates.index', [
            'pageTitle' => 'Templates WhatsApp',
            'templates' => $result['items'],
            'pagination' => $result['pagination'],
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        return view('knowledge.whatsapp-templates.create', [
            'pageTitle' => 'Novo template de WhatsApp',
            'template' => $this->templateFormDefaults(),
            'placeholders' => $this->whatsappTemplateService->placeholders(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validatedTemplatePayload($request);

        try {
            $this->whatsappTemplateService->create($payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.whatsapp-templates.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput($request->except('ativo'))
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput($request->except('ativo'))
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos do template de WhatsApp.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput($request->except('ativo'))
                ->with('error', 'Não foi possível cadastrar o template de WhatsApp agora. Tente novamente.');
        }

        return redirect()
            ->route('knowledge.whatsapp-templates.index')
            ->with('success', 'Template de WhatsApp cadastrado com sucesso.');
    }

    public function edit(int $template): View|RedirectResponse
    {
        try {
            $templateData = $this->whatsappTemplateService->find($template);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.whatsapp-templates.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            if ($exception->statusCode() === 404) {
                abort(404);
            }

            return redirect()->route('knowledge.whatsapp-templates.index')->with('error', $exception->getMessage());
        }

        if ($templateData === []) {
            abort(404);
        }

        return view('knowledge.whatsapp-templates.edit', [
            'pageTitle' => 'Editar template de WhatsApp',
            'template' => $templateData,
            'placeholders' => $this->whatsappTemplateService->placeholders(),
        ]);
    }

    public function update(Request $request, int $template): RedirectResponse
    {
        $payload = $this->validatedTemplatePayload($request);

        try {
            $this->whatsappTemplateService->update($template, $payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.whatsapp-templates.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput($request->except('ativo'))
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput($request->except('ativo'))
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos do template de WhatsApp.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput($request->except('ativo'))
                ->with('error', 'Não foi possível atualizar o template de WhatsApp agora. Tente novamente.');
        }

        return redirect()
            ->route('knowledge.whatsapp-templates.index')
            ->with('success', 'Template de WhatsApp atualizado com sucesso.');
    }

    public function toggleActive(int $template): RedirectResponse
    {
        try {
            $this->whatsappTemplateService->toggleActive($template);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.whatsapp-templates.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('knowledge.whatsapp-templates.index')->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('knowledge.whatsapp-templates.index')
            ->with('success', 'Status atualizado com sucesso.');
    }

    public function destroy(int $template): RedirectResponse
    {
        try {
            $this->whatsappTemplateService->destroy($template);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.whatsapp-templates.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('knowledge.whatsapp-templates.index')->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('knowledge.whatsapp-templates.index')
            ->with('success', 'Template de WhatsApp excluido com sucesso.');
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
            'evento' => '',
            'conteudo' => '',
            'ativo' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedTemplatePayload(Request $request): array
    {
        $validated = $request->validate([
            'codigo' => ['required', 'string', 'max:80'],
            'nome' => ['required', 'string', 'max:140'],
            'evento' => ['nullable', 'string', 'max:80'],
            'conteudo' => ['required', 'string'],
            'ativo' => ['nullable', 'boolean'],
        ], [], [
            'codigo' => 'código',
            'nome' => 'nome',
            'evento' => 'evento',
            'conteudo' => 'conteúdo',
            'ativo' => 'status',
        ]);

        $payload = [];

        foreach ($validated as $field => $value) {
            if ($field === 'ativo') {
                continue;
            }

            $payload[$field] = $this->normalizeValue($value);
        }

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
