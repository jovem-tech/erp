<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\ClientService;
use App\Services\FinanceiroService;
use App\Services\OrderService;
use App\Services\SupplierService;
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
        private readonly OrderService $orderService,
        private readonly SupplierService $supplierService,
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

    /**
     * Busca OS's em aberto para vincular ao lançamento. Filtra por cliente
     * quando informado, pois a compra de peças quase sempre é para
     * finalizar a OS de um cliente específico, raramente para estoque.
     */
    public function searchOrders(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q'         => ['nullable', 'string', 'max:100'],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'page'      => ['nullable', 'integer', 'min:1'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $search   = trim((string) ($validated['q'] ?? ''));
        $clientId = (int) ($validated['client_id'] ?? 0);
        $page     = max(1, (int) ($validated['page'] ?? 1));
        $perPage  = max(1, min(20, (int) ($validated['per_page'] ?? 10)));

        try {
            $result = $this->orderService->paginate(array_filter([
                'search'       => $search,
                'client_id'    => $clientId,
                'status_scope' => 'open',
                'page'         => $page,
                'per_page'     => $perPage,
            ], static fn ($v): bool => $v !== '' && $v !== 0));
        } catch (ApiAuthenticationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 401);
        } catch (ApiAuthorizationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        } catch (ApiRequestException $e) {
            $status = $e->statusCode() > 0 ? $e->statusCode() : 422;
            return response()->json(['success' => false, 'message' => $e->getMessage()], $status);
        }

        $orders = array_map(static function (array $order): array {
            $id          = (int) ($order['id'] ?? 0);
            $numero      = trim((string) ($order['numero_os'] ?? ''));
            $clienteNome = trim((string) ($order['cliente_nome'] ?? ''));
            $equipamento = trim((string) ($order['equipamento_resumo_curto'] ?? ''));
            $label       = $numero !== '' ? 'OS '.$numero : ('OS #'.$id);

            return [
                'id'           => $id,
                'text'         => implode(' — ', array_filter([$label, $clienteNome, $equipamento])),
                'numero_os'    => $numero,
                'cliente_id'   => (int) ($order['cliente_id'] ?? 0),
                'cliente_nome' => $clienteNome,
                'equipamento'  => $equipamento,
                'status_nome'  => trim((string) ($order['status_nome'] ?? '')),
            ];
        }, $result['items'] ?? []);

        return response()->json([
            'success'    => true,
            'orders'     => $orders,
            'pagination' => $result['pagination'] ?? [],
        ]);
    }

    public function searchSuppliers(Request $request): JsonResponse
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
            $result = $this->supplierService->paginate(array_filter([
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

        $suppliers = array_map(static function (array $supplier): array {
            $id    = (int) ($supplier['id'] ?? 0);
            $label = trim((string) ($supplier['nome_fantasia'] ?? '')) ?: trim((string) ($supplier['razao_social'] ?? ''));

            return [
                'id'    => $id,
                'text'  => $label !== '' ? $label : ('Fornecedor #'.$id),
                'name'  => $label,
                'phone' => trim((string) ($supplier['telefone1'] ?? '')),
            ];
        }, $result['items'] ?? []);

        return response()->json([
            'success'    => true,
            'suppliers'  => $suppliers,
            'pagination' => $result['pagination'] ?? [],
        ]);
    }

    public function index(Request $request): View
    {
        $filters = [
            'tipo' => trim((string) $request->query('tipo', '')),
            'status' => trim((string) $request->query('status', '')),
            'cliente_id' => (int) $request->query('cliente_id', 0),
            'dre_fixo_mensal' => trim((string) $request->query('dre_fixo_mensal', '')),
            'mes' => trim((string) $request->query('mes', '')),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 15),
        ];

        $result = $this->financeiroService->paginate(array_filter(
            $filters,
            static fn ($value): bool => $value !== '' && $value !== 0
        ));

        $catalog = $this->financeiroService->catalogo();

        return view('financeiro.index', [
            'pageTitle' => 'Financeiro',
            'lancamentos' => $result['items'],
            'pagination' => $result['pagination'],
            'statusOptions' => $result['status_options'],
            'totaisDespesas' => $result['totais_despesas'],
            'filters' => $filters,
            'cartaoDataset' => $catalog['cartao'],
            'accountDataset' => $catalog['contas_financeiras'],
        ]);
    }

    /**
     * Tela dedicada de Despesas: mesma tabela/serviço de Lançamentos, mas
     * com tipo=pagar sempre forçado — cobre TODAS as contas a pagar (fixas
     * e variáveis), com "Tipo de despesa" (fixa/variável) como filtro
     * opcional, para ter um lugar próprio de acompanhar as despesas da
     * assistência sem precisar lembrar de filtrar toda vez na listagem
     * geral de Lançamentos (que continua cobrindo a pagar + a receber).
     */
    public function despesasFixas(Request $request): View
    {
        $mesFiltro = trim((string) $request->query('mes', ''));
        $statusFiltro = trim((string) $request->query('status', ''));
        $dreFixoMensalFiltro = trim((string) $request->query('dre_fixo_mensal', ''));
        // Sem mês, status ou tipo de despesa escolhidos pelo usuário = visão
        // padrão da tela: mês corrente (qualquer status) + pendências
        // atrasadas de meses anteriores. Assim que o usuário filtra
        // explicitamente, essa visão inteligente some e o filtro passa a
        // valer literalmente (como em Lançamentos).
        $isDefaultView = $mesFiltro === '' && $statusFiltro === '' && $dreFixoMensalFiltro === '';

        $filters = [
            'tipo' => 'pagar',
            'status' => $statusFiltro,
            'dre_fixo_mensal' => $dreFixoMensalFiltro,
            'mes' => $mesFiltro,
            'periodo_atual_e_atrasadas' => $isDefaultView ? '1' : '',
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 15),
        ];

        $result = $this->financeiroService->paginate(array_filter(
            $filters,
            static fn ($value): bool => $value !== '' && $value !== 0
        ));

        $catalog = $this->financeiroService->catalogo();

        return view('financeiro.despesas-fixas', [
            'pageTitle' => 'Despesas',
            'lancamentos' => $result['items'],
            'pagination' => $result['pagination'],
            'statusOptions' => $result['status_options'],
            'totaisDespesas' => $result['totais_despesas'],
            'filters' => $filters,
            'cartaoDataset' => $catalog['cartao'],
            'accountDataset' => $catalog['contas_financeiras'],
        ]);
    }

    public function create(Request $request): View
    {
        $catalog = $this->financeiroService->catalogo();
        // Vindo do botão "Nova despesa" da tela de Despesas: trava o
        // lançamento em "a pagar" — essa entrada só serve para despesas. A
        // listagem geral de Lançamentos continua sendo o único lugar para
        // criar tanto a pagar quanto a receber.
        $tipoLocked = trim((string) $request->query('tipo', '')) === 'pagar';

        $lancamento = $this->formDefaults();
        if ($tipoLocked) {
            $lancamento['tipo'] = 'pagar';
        }

        return view('financeiro.create', [
            'pageTitle' => $tipoLocked ? 'Nova despesa' : 'Novo lançamento',
            'lancamento' => $lancamento,
            'tipoLocked' => $tipoLocked,
            'categorias' => $catalog['categorias'],
            'accountDataset' => $catalog['contas_financeiras'],
            'formasPagamento' => $catalog['formas_pagamento'],
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
        $accountDataset = ['contas' => [], 'contas_padrao' => [], 'tipos' => []];

        if (in_array($status, ['pendente', 'parcial'], true) && \App\Support\DesktopSession::can('financeiro', 'editar')) {
            try {
                $catalog = $this->financeiroService->catalogo();
                $cartaoDataset = $catalog['cartao'];
                $accountDataset = $catalog['contas_financeiras'];
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
            'accountDataset' => $accountDataset,
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

        $mensagem = $payload['repetir_proximos_meses'] ?? false
            ? 'Lançamento criado com sucesso, com mais 11 lançamentos futuros gerados (um por mês, pendentes).'
            : 'Lançamento criado com sucesso.';

        return redirect()
            ->to($this->successTarget($request, null))
            ->with('success', $mensagem);
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

        $catalog = $this->financeiroService->catalogo();

        return view('financeiro.edit', [
            'pageTitle' => 'Editar lançamento',
            'lancamento' => $data['lancamento'],
            'resumo' => $data['resumo'] ?? [],
            'categorias' => $catalog['categorias'],
            'accountDataset' => $catalog['contas_financeiras'],
            'formasPagamento' => $catalog['formas_pagamento'],
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
            'conta_financeira_id' => ['nullable', 'integer', 'min:1'],
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
     * Baixa, cancelamento e criação podem ser disparados a partir de mais de
     * uma tela — o campo oculto "voltar_para" preserva a origem ("show" na
     * página de detalhes, "despesas_fixas" no fluxo de Nova despesa vindo de
     * Despesas). $financeiro é null em store(), onde o lançamento ainda não
     * tem id no momento do redirect.
     */
    private function successTarget(Request $request, ?int $financeiro): string
    {
        if ($financeiro !== null && $request->input('voltar_para') === 'show') {
            return route('financeiro.show', $financeiro);
        }

        if ($request->input('voltar_para') === 'despesas_fixas') {
            return route('financeiro.despesas-fixas.index');
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
            // null (não false): o form novo usa um select "Todas as
            // categorias" / "Despesa fixa" / "Despesa variável" — null faz
            // isset() cair em '' (sem escolha), diferente de um valor
            // explícito false vindo de um lançamento real em edição.
            'dre_fixo_mensal' => null,
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
            'conta_financeira_id' => ['nullable', 'integer', 'min:1'],
            'data_vencimento' => ['required', 'date'],
            'data_pagamento' => ['nullable', 'date'],
            'observacoes' => ['nullable', 'string'],
            'os_id' => ['nullable', 'integer', 'min:1'],
            'cliente_id' => ['nullable', 'integer', 'min:1'],
            'fornecedor_id' => ['nullable', 'integer', 'min:1'],
            'avulso' => ['nullable', 'boolean'],
            'dre_fixo_mensal' => ['nullable', 'boolean'],
            'repetir_proximos_meses' => ['nullable', 'boolean'],
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

        // O select "Despesa fixa?" tem uma opção vazia ("Todas as
        // categorias") que significa "sem escolha explícita" — nesse caso
        // omitimos a chave para o backend aplicar o padrão da categoria
        // (resolveClassification()), em vez de forçar false só porque o
        // campo veio vazio no POST.
        $dreFixoMensalRaw = trim((string) $request->input('dre_fixo_mensal', ''));
        if ($validated['tipo'] === 'pagar' && $dreFixoMensalRaw !== '') {
            $validated['dre_fixo_mensal'] = $request->boolean('dre_fixo_mensal');
        } else {
            unset($validated['dre_fixo_mensal']);
        }

        // "Repetir" só existe no formulário de criação (form.blade.php só
        // renderiza o checkbox quando $lancamento['id'] está vazio) — em
        // edição, mesmo que o campo chegasse marcado, não faria sentido
        // regenerar títulos futuros a cada alteração salva.
        if ($validated['tipo'] === 'pagar' && $request->isMethod('post')) {
            $validated['repetir_proximos_meses'] = $request->boolean('repetir_proximos_meses');
        } else {
            unset($validated['repetir_proximos_meses']);
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
