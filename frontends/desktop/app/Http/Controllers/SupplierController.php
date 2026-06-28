<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\SupplierService;
use App\Support\DesktopSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class SupplierController extends DesktopController
{
    public function __construct(
        private readonly SupplierService $supplierService
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

        $result = $this->supplierService->paginate(array_filter(
            $filters,
            static fn ($value): bool => $value !== '' && $value !== 0
        ));

        return view('suppliers.index', [
            'pageTitle' => 'Fornecedores',
            'suppliers' => $result['items'],
            'pagination' => $result['pagination'],
            'filters' => $filters,
        ]);
    }

    public function help(): View
    {
        return view('suppliers.help', [
            'pageTitle' => 'Ajuda de fornecedores',
        ]);
    }

    public function create(): View
    {
        return view('suppliers.create', [
            'pageTitle' => 'Novo fornecedor',
            'supplier' => $this->supplierFormDefaults(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validatedSupplierPayload($request);

        try {
            $supplier = $this->supplierService->create($payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('suppliers.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput($request->except('ativo'))
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput($request->except('ativo'))
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos do fornecedor.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput($request->except('ativo'))
                ->with('error', 'Não foi possível cadastrar o fornecedor agora. Tente novamente.');
        }

        return redirect()
            ->route('suppliers.index')
            ->with('success', 'Fornecedor cadastrado com sucesso.');
    }

    public function edit(int $supplier): View|RedirectResponse
    {
        try {
            $supplierData = $this->supplierService->find($supplier);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('suppliers.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            if ($exception->statusCode() === 404) {
                abort(404);
            }

            return redirect()->route('suppliers.index')->with('error', $exception->getMessage());
        }

        if ($supplierData === []) {
            abort(404);
        }

        return view('suppliers.edit', [
            'pageTitle' => 'Editar fornecedor',
            'supplier' => $supplierData,
        ]);
    }

    public function update(Request $request, int $supplier): RedirectResponse
    {
        $payload = $this->validatedSupplierPayload($request);

        try {
            $updated = $this->supplierService->update($supplier, $payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('suppliers.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput($request->except('ativo'))
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput($request->except('ativo'))
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos do fornecedor.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput($request->except('ativo'))
                ->with('error', 'Não foi possível atualizar o fornecedor agora. Tente novamente.');
        }

        return redirect()
            ->route('suppliers.index', ['search' => trim((string) ($updated['nome_fantasia'] ?? ''))])
            ->with('success', 'Fornecedor atualizado com sucesso.');
    }

    public function close(int $supplier): RedirectResponse
    {
        try {
            $this->supplierService->close($supplier);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('suppliers.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('suppliers.index')->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('suppliers.index')
            ->with('success', 'Fornecedor encerrado com sucesso.');
    }

    public function destroy(int $supplier): RedirectResponse
    {
        try {
            $this->supplierService->destroy($supplier);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('suppliers.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('suppliers.index')->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('suppliers.index')
            ->with('success', 'Fornecedor excluído com sucesso.');
    }

    public function lookupCnpj(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'cnpj' => ['required', 'string', 'max:32'],
            ]);
        } catch (ValidationException $exception) {
            return $this->jsonFailure('Informe um CNPJ válido para consulta.', 422, $exception->errors());
        }

        try {
            $lookup = $this->supplierService->lookupCnpj((string) $validated['cnpj']);
        } catch (ApiAuthenticationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 401);
        } catch (ApiAuthorizationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 403);
        } catch (ApiRequestException $exception) {
            return $this->jsonFailure(
                $exception->getMessage() ?: 'Não foi possível consultar o CNPJ agora.',
                $exception->statusCode() > 0 ? $exception->statusCode() : 422,
                $exception->details()
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->jsonFailure('Não foi possível consultar o CNPJ agora.', 500);
        }

        return response()->json([
            'success' => true,
            'lookup' => $lookup,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierFormDefaults(): array
    {
        return [
            'id' => null,
            'tipo_pessoa' => 'juridica',
            'nome_fantasia' => '',
            'razao_social' => '',
            'cnpj_cpf' => '',
            'ie_rg' => '',
            'email' => '',
            'telefone1' => '',
            'telefone2' => '',
            'cep' => '',
            'endereco' => '',
            'numero' => '',
            'complemento' => '',
            'bairro' => '',
            'cidade' => '',
            'uf' => '',
            'observacoes' => '',
            'ativo' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedSupplierPayload(Request $request): array
    {
        $validated = $request->validate([
            'tipo_pessoa' => ['required', 'string', 'in:juridica,fisica'],
            'nome_fantasia' => ['required', 'string', 'max:100'],
            'razao_social' => ['nullable', 'string', 'max:100'],
            'cnpj_cpf' => ['nullable', 'string', 'max:20'],
            'ie_rg' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:100'],
            'telefone1' => ['required', 'string', 'max:20'],
            'telefone2' => ['nullable', 'string', 'max:20'],
            'cep' => ['nullable', 'string', 'max:10'],
            'endereco' => ['nullable', 'string', 'max:100'],
            'numero' => ['nullable', 'string', 'max:10'],
            'complemento' => ['nullable', 'string', 'max:50'],
            'bairro' => ['nullable', 'string', 'max:50'],
            'cidade' => ['nullable', 'string', 'max:50'],
            'uf' => ['nullable', 'string', 'max:2'],
            'observacoes' => ['nullable', 'string'],
            'ativo' => ['nullable', 'boolean'],
        ], [], [
            'tipo_pessoa' => 'tipo de pessoa',
            'nome_fantasia' => 'nome fantasia',
            'razao_social' => 'razão social',
            'cnpj_cpf' => 'CNPJ/CPF',
            'ie_rg' => 'inscrição estadual',
            'email' => 'e-mail',
            'telefone1' => 'telefone principal',
            'telefone2' => 'telefone secundário',
            'cep' => 'CEP',
            'endereco' => 'endereço',
            'numero' => 'número',
            'complemento' => 'complemento',
            'bairro' => 'bairro',
            'cidade' => 'cidade',
            'uf' => 'UF',
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

        $payload['tipo_pessoa'] = $payload['tipo_pessoa'] !== null && $payload['tipo_pessoa'] !== ''
            ? $payload['tipo_pessoa']
            : 'juridica';
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
     * @param array<string, mixed>|null $details
     */
    private function jsonFailure(string $message, int $status = 422, ?array $details = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $details ?? [],
        ], $status);
    }

    /**
     * @param array<string, mixed> $exceptionDetails
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
