<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\FinanceiroService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class FinanceiroController extends DesktopController
{
    public function __construct(
        private readonly FinanceiroService $financeiroService
    ) {
    }

    public function index(Request $request): View
    {
        $filters = [
            'tipo' => trim((string) $request->query('tipo', '')),
            'status' => trim((string) $request->query('status', '')),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 15),
        ];

        $result = $this->financeiroService->paginate(array_filter(
            $filters,
            static fn ($value): bool => $value !== '' && $value !== 0
        ));

        return view('financeiro.index', [
            'pageTitle' => 'Financeiro',
            'lancamentos' => $result['items'],
            'pagination' => $result['pagination'],
            'statusOptions' => $result['status_options'],
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        return view('financeiro.create', [
            'pageTitle' => 'Novo lançamento',
            'lancamento' => $this->formDefaults(),
            'categorias' => $this->financeiroService->catalogo()['categorias'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validatedPayload($request);

        try {
            $this->financeiroService->create($payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('financeiro.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput()
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput()
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos do lançamento.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', 'Não foi possível criar o lançamento agora. Tente novamente.');
        }

        return redirect()
            ->route('financeiro.index')
            ->with('success', 'Lançamento criado com sucesso.');
    }

    public function edit(int $financeiro): View|RedirectResponse
    {
        try {
            $data = $this->financeiroService->find($financeiro);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('financeiro.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            if ($exception->statusCode() === 404) {
                abort(404);
            }

            return redirect()->route('financeiro.index')->with('error', $exception->getMessage());
        }

        if (($data['lancamento'] ?? null) === null) {
            abort(404);
        }

        return view('financeiro.edit', [
            'pageTitle' => 'Editar lançamento',
            'lancamento' => $data['lancamento'],
            'resumo' => $data['resumo'] ?? [],
            'categorias' => $this->financeiroService->catalogo()['categorias'],
        ]);
    }

    public function update(Request $request, int $financeiro): RedirectResponse
    {
        $payload = $this->validatedPayload($request);

        try {
            $this->financeiroService->update($financeiro, $payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('financeiro.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput()
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput()
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos do lançamento.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', 'Não foi possível atualizar o lançamento agora. Tente novamente.');
        }

        return redirect()
            ->route('financeiro.index')
            ->with('success', 'Lançamento atualizado com sucesso.');
    }

    public function destroy(int $financeiro): RedirectResponse
    {
        try {
            $this->financeiroService->destroy($financeiro);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('financeiro.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('financeiro.index')->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('financeiro.index')
            ->with('success', 'Lançamento excluído com sucesso.');
    }

    public function pay(Request $request, int $financeiro): RedirectResponse
    {
        $validated = $request->validate([
            'valor_movimento' => ['required', 'numeric', 'min:0.01'],
            'data_movimento' => ['nullable', 'date'],
            'forma_pagamento' => ['nullable', 'string', 'max:40'],
            'observacoes' => ['nullable', 'string'],
        ], [], [
            'valor_movimento' => 'valor da baixa',
            'data_movimento' => 'data do movimento',
            'forma_pagamento' => 'forma de pagamento',
        ]);

        try {
            $this->financeiroService->pay($financeiro, $validated);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('financeiro.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('financeiro.index')->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('financeiro.index')
            ->with('success', 'Baixa registrada com sucesso.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formDefaults(): array
    {
        return [
            'id' => null,
            'tipo' => 'receber',
            'categoria' => '',
            'descricao' => '',
            'valor' => '',
            'status' => 'pendente',
            'forma_pagamento' => '',
            'data_vencimento' => '',
            'data_pagamento' => '',
            'observacoes' => '',
            'os_id' => '',
            'cliente_id' => '',
            'fornecedor_id' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request): array
    {
        $validated = $request->validate([
            'tipo' => ['required', 'string', 'in:receber,pagar'],
            'categoria' => ['required', 'string', 'max:50'],
            'descricao' => ['required', 'string', 'max:255'],
            'valor' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'status' => ['nullable', 'string', 'in:pendente,parcial,pago,cancelado'],
            'forma_pagamento' => ['nullable', 'string', 'in:dinheiro,cartao_credito,cartao_debito,pix,boleto,transferencia'],
            'data_vencimento' => ['required', 'date'],
            'data_pagamento' => ['nullable', 'date'],
            'observacoes' => ['nullable', 'string'],
            'os_id' => ['nullable', 'integer', 'min:1'],
            'cliente_id' => ['nullable', 'integer', 'min:1'],
            'fornecedor_id' => ['nullable', 'integer', 'min:1'],
        ], [], [
            'tipo' => 'tipo',
            'categoria' => 'categoria',
            'descricao' => 'descrição',
            'valor' => 'valor',
            'data_vencimento' => 'data de vencimento',
            'os_id' => 'ordem de serviço',
            'cliente_id' => 'cliente',
            'fornecedor_id' => 'fornecedor',
        ]);

        foreach (['forma_pagamento', 'observacoes'] as $field) {
            if (isset($validated[$field]) && trim((string) $validated[$field]) === '') {
                $validated[$field] = null;
            }
        }

        return $validated;
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
