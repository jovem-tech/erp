<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\ClientService;
use App\Services\FinanceiroService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class FinanceiroController extends DesktopController
{
    public function __construct(
        private readonly FinanceiroService $financeiroService,
        private readonly ClientService $clientService,
    ) {
    }

    public function searchClients(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q'        => ['nullable', 'string', 'max:100'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $search  = trim((string) ($validated['q'] ?? ''));
        $page    = max(1, (int) ($validated['page'] ?? 1));
        $perPage = max(1, min(20, (int) ($validated['per_page'] ?? 10)));

        try {
            $result = $this->clientService->paginate(array_filter([
                'search'   => $search,
                'page'     => $page,
                'per_page' => $perPage,
            ], static fn ($v): bool => $v !== '' && $v !== 0));
        } catch (ApiAuthenticationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 401);
        } catch (ApiAuthorizationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        } catch (ApiRequestException $e) {
            $status = $e->statusCode() > 0 ? $e->statusCode() : 422;
            return response()->json(['success' => false, 'message' => $e->getMessage()], $status);
        }

        $clients = array_map(static function (array $c): array {
            $id    = (int) ($c['id'] ?? 0);
            $label = trim((string) ($c['nome_razao'] ?? ''));
            return [
                'id'      => $id,
                'text'    => $label !== '' ? $label : "Cliente #{$id}",
                'name'    => $label,
                'phone'   => trim((string) ($c['telefone1'] ?? '')),
                'email'   => trim((string) ($c['email'] ?? '')),
                'contact' => trim((string) ($c['nome_contato'] ?? '')),
                'city'    => trim((string) ($c['cidade'] ?? '')),
                'uf'      => trim((string) ($c['uf'] ?? '')),
            ];
        }, $result['items'] ?? []);

        return response()->json([
            'success'    => true,
            'clients'    => $clients,
            'pagination' => $result['pagination'] ?? [],
        ]);
    }

    public function index(Request $request): View
    {
        $filters = [
            'tipo' => trim((string) $request->query('tipo', '')),
            'status' => trim((string) $request->query('status', '')),
            'cliente_id' => (int) $request->query('cliente_id', 0),
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
            'cartaoDataset' => $this->financeiroService->catalogo()['cartao'],
        ]);
    }

    public function create(): View
    {
        return view('financeiro.create', [
            'pageTitle' => 'Novo lançamento',
            'lancamento' => $this->formDefaults(),
            'categorias' => $this->financeiroService->catalogo()['categorias'],
            'canQuickClient' => \App\Support\DesktopSession::can('clientes', 'criar'),
        ]);
    }

    public function show(int $financeiro): View|RedirectResponse
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

        // Dataset de operadoras/taxas só é necessário quando o modal de
        // "Registrar baixa" pode aparecer (título em aberto + permissão).
        $status = (string) ($data['lancamento']['status'] ?? 'pendente');
        $cartaoDataset = ['operadoras' => [], 'bandeiras' => [], 'taxas' => []];

        if (in_array($status, ['pendente', 'parcial'], true) && \App\Support\DesktopSession::can('financeiro', 'editar')) {
            try {
                $cartaoDataset = $this->financeiroService->catalogo()['cartao'];
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return view('financeiro.show', [
            'pageTitle' => 'Detalhes do lançamento',
            'lancamento' => $data['lancamento'],
            'resumo' => $data['resumo'] ?? [],
            'detalhes' => $data['detalhes'] ?? [],
            'cartaoDataset' => $cartaoDataset,
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
            'canQuickClient' => \App\Support\DesktopSession::can('clientes', 'criar'),
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

    public function destroy(Request $request, int $financeiro): RedirectResponse
    {
        // admin_email/admin_password só são preenchidos pelo modal de
        // confirmação (ver financeiro/_delete_admin_modal.blade.php) — a
        // exclusão exige credenciais de administrador sempre.
        $payload = array_filter([
            'admin_email' => trim((string) $request->input('admin_email', '')),
            'admin_password' => (string) $request->input('admin_password', ''),
        ], static fn ($value): bool => $value !== '');

        try {
            $this->financeiroService->destroy($financeiro, $payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('financeiro.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()
                ->to($this->successTarget($request, $financeiro))
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('financeiro.index')
            ->with('success', 'Lançamento excluído com sucesso.');
    }

    public function cancel(Request $request, int $financeiro): RedirectResponse
    {
        // motivo/admin_email/admin_password só são usados quando o lançamento
        // está vinculado a uma OS encerrada — ver
        // financeiro/_cancel_reason_modal.blade.php. Em qualquer outro caso o
        // payload vem vazio e o comportamento é idêntico ao de antes.
        $payload = array_filter([
            'motivo' => trim((string) $request->input('motivo', '')),
            'admin_email' => trim((string) $request->input('admin_email', '')),
            'admin_password' => (string) $request->input('admin_password', ''),
        ], static fn ($value): bool => $value !== '');

        try {
            $this->financeiroService->cancel($financeiro, $payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('financeiro.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()
                ->to($this->successTarget($request, $financeiro))
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->to($this->successTarget($request, $financeiro))
            ->with('success', 'Lançamento cancelado com sucesso.');
    }

    public function pay(Request $request, int $financeiro): RedirectResponse
    {
        $validated = $request->validate([
            'valor_movimento' => ['required', 'numeric', 'min:0.01'],
            'data_movimento' => ['nullable', 'date'],
            'forma_pagamento' => ['nullable', 'string', 'max:40'],
            'observacoes' => ['nullable', 'string'],
            'operadora_id' => ['nullable', 'integer', 'min:1', 'required_if:forma_pagamento,cartao_credito,cartao_debito'],
            'bandeira_id' => ['nullable', 'integer', 'min:1'],
            'modalidade' => ['nullable', 'string', 'in:credito,debito'],
            'parcelas' => ['nullable', 'integer', 'min:1', 'max:99'],
        ], [], [
            'valor_movimento' => 'valor da baixa',
            'data_movimento' => 'data do movimento',
            'forma_pagamento' => 'forma de pagamento',
            'operadora_id' => 'operadora',
            'bandeira_id' => 'bandeira',
            'modalidade' => 'modalidade',
            'parcelas' => 'parcelas',
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
            ->to($this->successTarget($request, $financeiro))
            ->with('success', 'Baixa registrada com sucesso.');
    }

    /**
     * Baixa e cancelamento podem ser disparados tanto da listagem quanto da
     * página de detalhes — o campo oculto "voltar_para=show" preserva a origem.
     */
    private function successTarget(Request $request, int $financeiro): string
    {
        if ($request->input('voltar_para') === 'show') {
            return route('financeiro.show', $financeiro);
        }

        return route('financeiro.index');
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
            'avulso' => false,
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
            'avulso' => ['nullable', 'boolean'],
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

        $validated['avulso'] = $request->boolean('avulso');

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
