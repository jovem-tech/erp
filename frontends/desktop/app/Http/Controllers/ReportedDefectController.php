<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\KnowledgeLookupService;
use App\Services\ReportedDefectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class ReportedDefectController extends DesktopController
{
    public function __construct(
        private readonly ReportedDefectService $reportedDefectService,
        private readonly KnowledgeLookupService $knowledgeLookupService
    ) {
    }

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'tipo_equipamento_id' => $request->query('tipo_equipamento_id', ''),
            'categoria' => trim((string) $request->query('categoria', '')),
            'subcategoria' => trim((string) $request->query('subcategoria', '')),
            'active' => $request->query('active', ''),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 15),
        ];

        $result = $this->reportedDefectService->paginate(array_filter(
            $filters,
            static fn ($value): bool => $value !== '' && $value !== 0
        ));

        return view('knowledge.reported-defects.index', [
            'pageTitle' => 'Defeitos Relatados',
            'defeitos' => $result['items'],
            'pagination' => $result['pagination'],
            'filters' => $filters,
            'equipmentTypes' => $this->knowledgeLookupService->equipmentTypes(),
        ]);
    }

    public function create(): View
    {
        return view('knowledge.reported-defects.create', [
            'pageTitle' => 'Novo defeito relatado',
            'defeito' => $this->defeitoFormDefaults(),
            'equipmentTypes' => $this->knowledgeLookupService->equipmentTypes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validatedDefeitoPayload($request);

        try {
            $this->reportedDefectService->create($payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.reported-defects.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput($request->except('ativo'))
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput($request->except('ativo'))
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos do defeito relatado.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput($request->except('ativo'))
                ->with('error', 'Não foi possível cadastrar o defeito relatado agora. Tente novamente.');
        }

        return redirect()
            ->route('knowledge.reported-defects.index')
            ->with('success', 'Defeito relatado cadastrado com sucesso.');
    }

    public function edit(int $defeito): View|RedirectResponse
    {
        try {
            $defeitoData = $this->reportedDefectService->find($defeito);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.reported-defects.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            if ($exception->statusCode() === 404) {
                abort(404);
            }

            return redirect()->route('knowledge.reported-defects.index')->with('error', $exception->getMessage());
        }

        if ($defeitoData === []) {
            abort(404);
        }

        return view('knowledge.reported-defects.edit', [
            'pageTitle' => 'Editar defeito relatado',
            'defeito' => $defeitoData,
            'equipmentTypes' => $this->knowledgeLookupService->equipmentTypes(),
        ]);
    }

    public function update(Request $request, int $defeito): RedirectResponse
    {
        $payload = $this->validatedDefeitoPayload($request);

        try {
            $this->reportedDefectService->update($defeito, $payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.reported-defects.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput($request->except('ativo'))
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput($request->except('ativo'))
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos do defeito relatado.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput($request->except('ativo'))
                ->with('error', 'Não foi possível atualizar o defeito relatado agora. Tente novamente.');
        }

        return redirect()
            ->route('knowledge.reported-defects.index')
            ->with('success', 'Defeito relatado atualizado com sucesso.');
    }

    public function toggleActive(int $defeito): RedirectResponse
    {
        try {
            $this->reportedDefectService->toggleActive($defeito);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.reported-defects.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('knowledge.reported-defects.index')->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('knowledge.reported-defects.index')
            ->with('success', 'Status atualizado com sucesso.');
    }

    public function destroy(int $defeito): RedirectResponse
    {
        try {
            $this->reportedDefectService->destroy($defeito);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.reported-defects.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('knowledge.reported-defects.index')->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('knowledge.reported-defects.index')
            ->with('success', 'Defeito relatado excluido com sucesso.');
    }

    /**
     * @return array<string, mixed>
     */
    private function defeitoFormDefaults(): array
    {
        return [
            'id' => null,
            'tipo_equipamento_id' => null,
            'categoria' => '',
            'subcategoria' => '',
            'texto_relato' => '',
            'icone' => '',
            'ordem_exibicao' => 0,
            'observacoes' => '',
            'ativo' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedDefeitoPayload(Request $request): array
    {
        $validated = $request->validate([
            'tipo_equipamento_id' => ['nullable', 'integer'],
            'categoria' => ['required', 'string', 'max:80'],
            'subcategoria' => ['nullable', 'string', 'max:80'],
            'texto_relato' => ['required', 'string', 'max:255'],
            'icone' => ['nullable', 'string', 'max:20'],
            'ordem_exibicao' => ['nullable', 'integer'],
            'observacoes' => ['nullable', 'string'],
            'ativo' => ['nullable', 'boolean'],
        ], [], [
            'tipo_equipamento_id' => 'tipo de equipamento',
            'categoria' => 'categoria',
            'subcategoria' => 'subcategoria',
            'texto_relato' => 'relato',
            'icone' => 'ícone',
            'ordem_exibicao' => 'ordem de exibição',
            'observacoes' => 'observações',
            'ativo' => 'status',
        ]);

        $payload = [];

        foreach ($validated as $field => $value) {
            if ($field === 'ativo') {
                continue;
            }

            $payload[$field] = $this->normalizeValue($value);
        }

        $payload['ordem_exibicao'] = (int) ($payload['ordem_exibicao'] ?? 0);
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
