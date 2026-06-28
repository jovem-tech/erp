<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\DefectService;
use App\Services\KnowledgeLookupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class DefectController extends DesktopController
{
    public function __construct(
        private readonly DefectService $defectService,
        private readonly KnowledgeLookupService $knowledgeLookupService
    ) {
    }

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'tipo_id' => $request->query('tipo_id', ''),
            'classificacao' => trim((string) $request->query('classificacao', '')),
            'active' => $request->query('active', ''),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 15),
        ];

        $result = $this->defectService->paginate(array_filter(
            $filters,
            static fn ($value): bool => $value !== '' && $value !== 0
        ));

        return view('knowledge.defects.index', [
            'pageTitle' => 'Base de Defeitos',
            'defeitos' => $result['items'],
            'pagination' => $result['pagination'],
            'filters' => $filters,
            'equipmentTypes' => $this->knowledgeLookupService->equipmentTypes(),
        ]);
    }

    public function create(): View
    {
        return view('knowledge.defects.create', [
            'pageTitle' => 'Novo defeito',
            'defeito' => $this->defeitoFormDefaults(),
            'equipmentTypes' => $this->knowledgeLookupService->equipmentTypes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validatedDefeitoPayload($request);

        try {
            $this->defectService->create($payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.defects.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput($request->except('ativo'))
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput($request->except('ativo'))
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos do defeito.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput($request->except('ativo'))
                ->with('error', 'Não foi possível cadastrar o defeito agora. Tente novamente.');
        }

        return redirect()
            ->route('knowledge.defects.index')
            ->with('success', 'Defeito cadastrado com sucesso.');
    }

    public function edit(int $defeito): View|RedirectResponse
    {
        try {
            $defeitoData = $this->defectService->find($defeito);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.defects.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            if ($exception->statusCode() === 404) {
                abort(404);
            }

            return redirect()->route('knowledge.defects.index')->with('error', $exception->getMessage());
        }

        if ($defeitoData === []) {
            abort(404);
        }

        return view('knowledge.defects.edit', [
            'pageTitle' => 'Editar defeito',
            'defeito' => $defeitoData,
            'equipmentTypes' => $this->knowledgeLookupService->equipmentTypes(),
        ]);
    }

    public function update(Request $request, int $defeito): RedirectResponse
    {
        $payload = $this->validatedDefeitoPayload($request);

        try {
            $this->defectService->update($defeito, $payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.defects.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput($request->except('ativo'))
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput($request->except('ativo'))
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos do defeito.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput($request->except('ativo'))
                ->with('error', 'Não foi possível atualizar o defeito agora. Tente novamente.');
        }

        return redirect()
            ->route('knowledge.defects.edit', $defeito)
            ->with('success', 'Defeito atualizado com sucesso.');
    }

    public function toggleActive(int $defeito): RedirectResponse
    {
        try {
            $this->defectService->toggleActive($defeito);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.defects.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('knowledge.defects.index')->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('knowledge.defects.index')
            ->with('success', 'Status atualizado com sucesso.');
    }

    public function destroy(int $defeito): RedirectResponse
    {
        try {
            $this->defectService->destroy($defeito);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.defects.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('knowledge.defects.index')->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('knowledge.defects.index')
            ->with('success', 'Defeito excluído com sucesso.');
    }

    public function storeProcedure(Request $request, int $defeito): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'descricao' => ['required', 'string', 'max:255'],
            ]);
        } catch (ValidationException $exception) {
            return back()
                ->withErrors($exception->errors())
                ->with('error', 'Verifique o campo do procedimento.');
        }

        try {
            $this->defectService->addProcedure($defeito, $validated);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.defects.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Não foi possível adicionar o procedimento agora.');
        }

        return redirect()
            ->route('knowledge.defects.edit', $defeito)
            ->with('success', 'Procedimento adicionado.');
    }

    public function updateProcedure(Request $request, int $defeito, int $procedimento): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'descricao' => ['required', 'string', 'max:255'],
            ]);
        } catch (ValidationException $exception) {
            return back()
                ->withErrors($exception->errors())
                ->with('error', 'Verifique o campo do procedimento.');
        }

        try {
            $this->defectService->updateProcedure($defeito, $procedimento, $validated);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.defects.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Não foi possível atualizar o procedimento agora.');
        }

        return redirect()
            ->route('knowledge.defects.edit', $defeito)
            ->with('success', 'Procedimento atualizado.');
    }

    public function destroyProcedure(Request $request, int $defeito, int $procedimento): RedirectResponse
    {
        try {
            $this->defectService->destroyProcedure($defeito, $procedimento);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.defects.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('knowledge.defects.edit', $defeito)->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('knowledge.defects.edit', $defeito)
            ->with('success', 'Procedimento removido.');
    }

    public function moveProcedure(Request $request, int $defeito, int $procedimento): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'direction' => ['required', 'string', 'in:up,down'],
            ]);
        } catch (ValidationException $exception) {
            return back()
                ->withErrors($exception->errors())
                ->with('error', 'Direção de movimentação inválida.');
        }

        try {
            $this->defectService->moveProcedure($defeito, $procedimento, $validated['direction']);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.defects.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('knowledge.defects.edit', $defeito)->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('knowledge.defects.edit', $defeito)
            ->with('success', 'Ordem atualizada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function defeitoFormDefaults(): array
    {
        return [
            'id' => null,
            'nome' => '',
            'tipo_id' => null,
            'classificacao' => 'hardware',
            'descricao' => '',
            'ativo' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedDefeitoPayload(Request $request): array
    {
        $validated = $request->validate([
            'nome' => ['required', 'string', 'max:150'],
            'tipo_id' => ['required', 'integer'],
            'classificacao' => ['required', 'string', 'in:hardware,software'],
            'descricao' => ['nullable', 'string'],
            'ativo' => ['nullable', 'boolean'],
        ], [], [
            'nome' => 'nome',
            'tipo_id' => 'tipo de equipamento',
            'classificacao' => 'classificação',
            'descricao' => 'descrição',
            'ativo' => 'status',
        ]);

        $payload = [];

        foreach ($validated as $field => $value) {
            if ($field === 'ativo') {
                continue;
            }

            $payload[$field] = $this->normalizeValue($value);
        }

        $payload['tipo_id'] = (int) $payload['tipo_id'];
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
