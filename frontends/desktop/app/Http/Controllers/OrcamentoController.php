<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\ClientService;
use App\Services\EquipmentService;
use App\Services\OrcamentoService;
use App\Services\StockService;
use App\Support\DesktopSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class OrcamentoController extends DesktopController
{
    public function __construct(
        private readonly OrcamentoService $orcamentoService,
        private readonly ClientService $clientService,
        private readonly EquipmentService $equipmentService,
        // specs/040: busca remota de peca, com saldo e disponivel.
        private readonly StockService $stockService
    ) {}

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => trim((string) $request->query('status', '')),
            'tipo' => trim((string) $request->query('tipo', '')),
            'origem' => trim((string) $request->query('origem', '')),
            'client_id' => (int) $request->query('client_id', 0),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 15),
        ];

        $result = $this->orcamentoService->paginate(array_filter(
            $filters,
            static fn ($value): bool => $value !== '' && $value !== 0
        ));

        return view('orcamentos.index', [
            'pageTitle' => 'Orçamentos',
            'budgets' => $result['items'],
            'pagination' => $result['pagination'],
            'summary' => $result['summary'],
            'statusOptions' => $result['status_options'],
            'filters' => $filters,
        ]);
    }

    public function help(): View
    {
        return view('orcamentos.help', [
            'pageTitle' => 'Ajuda de orçamentos',
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        try {
            $form = $this->orcamentoService->formData([
                'cliente_id' => (int) $request->query('cliente_id', 0),
                'os_id' => (int) $request->query('os_id', 0),
            ]);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('orcamentos.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('orcamentos.index')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('orcamentos.index')->with('error', 'Não foi possível carregar o formulário de orçamento agora.');
        }

        return view('orcamentos.create', [
            'pageTitle' => 'Novo orçamento',
            'budget' => [],
            'form' => $form,
            'quickCatalogs' => $this->quickCatalogConfig(),
            'canQuickClient' => DesktopSession::can('clientes', 'criar'),
            'canCreateEquipment' => DesktopSession::can('equipamentos', 'criar'),
            'equipmentCatalog' => $this->equipmentCatalogForForm(),
            'isEditMode' => false,
        ]);
    }

    /**
     * Busca remota de peca para o seletor do item de orcamento (specs/040).
     *
     * Substitui o catalogo estatico de 80 pecas, que nao escalava e — pior —
     * descartava o saldo no mapeamento, deixando o operador montar a proposta
     * sem ver estoque nenhum. Mesmo formato e mesmo caminho de
     * FinanceiroController::searchParts(), que ja fazia isso na entrada por
     * compra (specs/039).
     */
    public function searchParts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $search = trim((string) ($validated['q'] ?? ''));
        $page = max(1, (int) ($validated['page'] ?? 1));
        $perPage = max(1, min(20, (int) ($validated['per_page'] ?? 10)));

        try {
            $result = $this->stockService->paginate(array_filter([
                'search' => $search,
                'page' => $page,
                'per_page' => $perPage,
                // Peça encerrada não entra em proposta nova.
                'status' => 'ativo',
            ], static fn ($v): bool => $v !== '' && $v !== 0));
        } catch (ApiAuthenticationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 401);
        } catch (ApiAuthorizationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        } catch (ApiRequestException $e) {
            $status = $e->statusCode() > 0 ? $e->statusCode() : 422;

            return response()->json(['success' => false, 'message' => $e->getMessage()], $status);
        }

        $parts = array_map(static function (array $part): array {
            $id = (int) ($part['id'] ?? 0);
            $codigo = trim((string) ($part['codigo'] ?? ''));
            $nome = trim((string) ($part['nome'] ?? ''));
            $label = $codigo !== '' ? $codigo.' — '.$nome : $nome;

            return [
                'id' => $id,
                'text' => $label !== '' ? $label : ('Peça #'.$id),
                'codigo' => $codigo,
                'nome' => $nome,
                'unidade' => trim((string) ($part['unidade'] ?? 'UN')),
                'categoria_efetiva' => trim((string) ($part['categoria_efetiva'] ?? '')),
                'preco_custo' => (float) ($part['preco_custo'] ?? 0),
                'preco_venda' => (float) ($part['preco_venda'] ?? 0),
                'saldo' => (float) ($part['quantidade_atual'] ?? 0),
                // O par que o badge do item usa: quanto ja esta prometido e
                // quanto sobra de verdade.
                'reservado' => (float) ($part['quantidade_reservada'] ?? 0),
                'disponivel' => (float) ($part['quantidade_disponivel'] ?? 0),
            ];
        }, $result['items'] ?? []);

        $pagination = $result['pagination'] ?? [];

        return response()->json([
            'success' => true,
            'results' => $parts,
            'pagination' => [
                'more' => (int) ($pagination['current_page'] ?? 1) < (int) ($pagination['last_page'] ?? 1),
            ],
        ]);
    }

    public function searchClients(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);
        $page = max(1, (int) ($validated['page'] ?? 1));
        $perPage = max(1, min(20, (int) ($validated['per_page'] ?? 15)));

        try {
            $result = $this->orcamentoService->clientOptions([
                'q' => trim((string) ($validated['q'] ?? $validated['search'] ?? '')),
                'page' => $page,
                'per_page' => $perPage,
            ]);
        } catch (ApiAuthenticationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 401);
        } catch (ApiAuthorizationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 403);
        } catch (ApiRequestException $exception) {
            return $this->jsonFailure(
                $exception->getMessage(),
                $exception->statusCode() > 0 ? $exception->statusCode() : 422,
                $exception->details()
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->jsonFailure('Não foi possível pesquisar os clientes agora.', 500);
        }

        $items = array_values(array_filter(array_map(static function (array $client): array {
            $id = (int) ($client['id'] ?? 0);
            $name = trim((string) ($client['nome_razao'] ?? ''));
            $phone = trim((string) ($client['telefone1'] ?? ''));

            return [
                'id' => $id,
                'text' => implode(' - ', array_values(array_filter([
                    $name !== '' ? $name : ($id > 0 ? 'Cliente #'.$id : ''),
                    $phone,
                ]))),
                'name' => $name,
                'phone' => $phone,
                'email' => trim((string) ($client['email'] ?? '')),
            ];
        }, (array) ($result['items'] ?? [])), static fn (array $item): bool => $item['id'] > 0));
        $pagination = is_array($result['pagination'] ?? null) ? $result['pagination'] : [];
        $currentPage = (int) ($pagination['current_page'] ?? $page);
        $lastPage = (int) ($pagination['last_page'] ?? $currentPage);

        return response()->json([
            'success' => true,
            'results' => $items,
            'pagination' => ['more' => $currentPage < $lastPage],
        ]);
    }

    public function clientContext(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cliente_id' => ['required', 'integer', 'min:1'],
            'orcamento_id' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $context = $this->orcamentoService->clientContext(
                (int) $validated['cliente_id'],
                (int) ($validated['orcamento_id'] ?? 0)
            );
        } catch (ApiAuthenticationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 401);
        } catch (ApiAuthorizationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 403);
        } catch (ApiRequestException $exception) {
            return $this->jsonFailure(
                $exception->getMessage(),
                $exception->statusCode() > 0 ? $exception->statusCode() : 422,
                $exception->details()
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->jsonFailure('Não foi possível carregar as OS e equipamentos do cliente agora.', 500);
        }

        $orders = array_values(array_filter(array_map(static function (array $order): array {
            $id = (int) ($order['id'] ?? 0);
            $numero = trim((string) ($order['numero_os'] ?? ''));
            $clientName = trim((string) ($order['cliente_nome'] ?? ''));
            $label = $numero !== '' ? $numero : ('OS #'.$id);

            return [
                'id' => $id,
                'cliente_id' => (int) ($order['cliente_id'] ?? 0),
                // Equipamento vinculado à OS: usado para pré-selecionar
                // automaticamente o "Equipamento cadastrado" ao escolher a OS.
                'equipamento_id' => (int) ($order['equipamento_id'] ?? 0),
                // Defeito relatado na OS: usado para preencher automaticamente
                // "Relato do cliente / defeito relatado" ao escolher a OS.
                'relato_cliente' => trim((string) ($order['relato_cliente'] ?? '')),
                'label' => $label.($clientName !== '' ? ' - '.$clientName : ''),
            ];
        }, (array) ($context['orders'] ?? [])), static fn (array $order): bool => $order['id'] > 0));

        $equipments = array_values(array_filter(array_map(function (array $equipment): array {
            $id = (int) ($equipment['id'] ?? 0);
            $tipo = trim((string) ($equipment['tipo_nome'] ?? ''));
            $marcaModelo = trim(implode(' ', array_filter([
                trim((string) ($equipment['marca_nome'] ?? '')),
                trim((string) ($equipment['modelo_nome'] ?? '')),
            ])));
            $label = trim(implode(' - ', array_filter([$tipo, $marcaModelo])));
            if ($label === '') {
                $label = trim((string) ($equipment['resumo_tecnico'] ?? ''));
            }
            if ($label === '') {
                $label = 'Equipamento #'.$id;
            }

            $serial = trim((string) ($equipment['numero_serie'] ?? ''));
            $clientName = trim((string) ($equipment['cliente_nome'] ?? ''));
            $fullLabel = $label
                .($serial !== '' ? ' · S/N '.$serial : '')
                .($clientName !== '' ? ' · '.$clientName : '');

            $photoId = (int) ($equipment['foto_principal_id'] ?? 0);

            return [
                'id' => $id,
                'cliente_id' => (int) ($equipment['cliente_id'] ?? 0),
                'label' => $fullLabel,
                'foto_url' => $photoId > 0 ? route('equipments.photos.show', [$id, $photoId]) : null,
            ];
        }, (array) ($context['equipments'] ?? [])), static fn (array $equipment): bool => $equipment['id'] > 0));

        return response()->json([
            'success' => true,
            'orders' => $orders,
            'equipments' => $equipments,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $submissionMode = $this->resolveSubmissionMode($request);
        $dispatchChannel = $this->resolveDispatchChannel($request);
        $payload = $this->validatedBudgetPayload($request, requireComplete: true);

        try {
            $budget = $this->orcamentoService->create($payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('orcamentos.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput($request->except('admin_password'))
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput($request->except('admin_password'))
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos do orçamento.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput($request->except('admin_password'))
                ->with('error', 'Não foi possível criar o orçamento agora. Tente novamente.');
        }

        return $this->redirectAfterPersist($budget, $submissionMode, true, 0, $dispatchChannel);
    }

    public function show(int $orcamento): View|RedirectResponse
    {
        try {
            $budget = $this->orcamentoService->find($orcamento);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('orcamentos.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            if ($exception->statusCode() === 404) {
                abort(404);
            }

            return redirect()->route('orcamentos.index')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('orcamentos.index')->with('error', 'Não foi possível carregar o orçamento.');
        }

        if ($budget === []) {
            abort(404);
        }

        return view('orcamentos.show', [
            'pageTitle' => 'Detalhe do orçamento',
            'budget' => $budget,
        ]);
    }

    /**
     * Reaplica no orçamento os dados de contato do cliente cadastrado.
     *
     * O orçamento guarda telefone/e-mail como snapshot do momento em que foi
     * montado — se o cadastro do cliente mudar depois, a proposta continua
     * mostrando o contato velho. Este botão traz a atualização sem obrigar a
     * reabrir o formulário inteiro (o backend aceita payload parcial em
     * BudgetWorkflowService::updateBudget()).
     *
     * Não serve para orçamento com cliente eventual: sem cliente_id não há
     * cadastro de onde sincronizar — nesse caso o caminho é vincular um cliente
     * pela edição do orçamento, que é também o que a OS vai exigir.
     */
    public function syncClient(int $orcamento): RedirectResponse
    {
        try {
            $budget = $this->orcamentoService->find($orcamento);

            if ($budget === []) {
                abort(404);
            }

            $clientId = (int) ($budget['cliente']['id'] ?? $budget['cliente_id'] ?? 0);

            if ($clientId <= 0) {
                return redirect()
                    ->route('orcamentos.show', $orcamento)
                    ->with('error', 'Este orçamento usa cliente eventual. Vincule um cliente cadastrado na edição antes de sincronizar os dados.');
            }

            $client = $this->clientService->find($clientId);

            if ($client === []) {
                return redirect()
                    ->route('orcamentos.show', $orcamento)
                    ->with('error', 'O cliente vinculado a este orçamento não foi encontrado no cadastro.');
            }

            // Campo vazio no cadastro não apaga o que já existe no orçamento:
            // sincronizar é trazer atualização, não zerar contato válido.
            $payload = [];
            $phone = trim((string) ($client['telefone1'] ?? ''));
            $email = trim((string) ($client['email'] ?? ''));

            if ($phone !== '' && $phone !== trim((string) ($budget['telefone_contato'] ?? ''))) {
                $payload['telefone_contato'] = $phone;
            }

            if ($email !== '' && $email !== trim((string) ($budget['email_contato'] ?? ''))) {
                $payload['email_contato'] = $email;
            }

            if ($payload === []) {
                return redirect()
                    ->route('orcamentos.show', $orcamento)
                    ->with('info', 'Os dados de contato do orçamento já estão iguais aos do cadastro do cliente.');
            }

            $this->orcamentoService->update($orcamento, $payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('orcamentos.show', $orcamento)->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            if ($exception->statusCode() === 404) {
                abort(404);
            }

            return redirect()->route('orcamentos.show', $orcamento)->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('orcamentos.show', $orcamento)
                ->with('error', 'Não foi possível sincronizar os dados do cliente agora.');
        }

        return redirect()
            ->route('orcamentos.show', $orcamento)
            ->with('success', 'Dados de contato atualizados a partir do cadastro do cliente.');
    }

    public function edit(int $orcamento): View|RedirectResponse
    {
        try {
            $budget = $this->orcamentoService->find($orcamento);
            $form = $this->orcamentoService->formData([
                'cliente_id' => (int) ($budget['cliente']['id'] ?? $budget['cliente_id'] ?? 0),
                'os_id' => (int) ($budget['os']['id'] ?? $budget['os_id'] ?? 0),
                // Preserva a própria OS vinculada na lista de "OS vinculada" —
                // ela não deve sumir por já "ter orçamento" (é este mesmo).
                'orcamento_id' => $orcamento,
            ]);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('orcamentos.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            if ($exception->statusCode() === 404) {
                abort(404);
            }

            return redirect()->route('orcamentos.index')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('orcamentos.index')->with('error', 'Não foi possível carregar a edição do orçamento.');
        }

        if ($budget === []) {
            abort(404);
        }

        return view('orcamentos.edit', [
            'pageTitle' => 'Editar orçamento',
            'budget' => $budget,
            'form' => $form,
            'quickCatalogs' => $this->quickCatalogConfig(),
            'canQuickClient' => DesktopSession::can('clientes', 'criar'),
            'canCreateEquipment' => DesktopSession::can('equipamentos', 'criar'),
            'equipmentCatalog' => $this->equipmentCatalogForForm(),
            'isEditMode' => true,
        ]);
    }

    public function update(Request $request, int $orcamento): RedirectResponse
    {
        $submissionMode = $this->resolveSubmissionMode($request);
        $dispatchChannel = $this->resolveDispatchChannel($request);
        $payload = $this->validatedBudgetPayload($request);

        try {
            $budget = $this->orcamentoService->update($orcamento, $payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('orcamentos.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput($request->except('admin_password'))
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput($request->except('admin_password'))
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos do orçamento.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput($request->except('admin_password'))
                ->with('error', 'Não foi possível atualizar o orçamento agora. Tente novamente.');
        }

        // Mudança de valor/cliente num orçamento convertido gera uma revisão
        // separada (o orçamento base nunca sai de "convertido" — ver
        // BudgetWorkflowService::updateConvertedBudget()). Redireciona para a
        // revisão, não para o orçamento base inalterado, senão parece que
        // nada aconteceu.
        $revision = is_array($budget['_revision'] ?? null) ? $budget['_revision'] : null;
        if ($revision !== null && (int) ($revision['id'] ?? 0) > 0) {
            return redirect()
                ->route('orcamentos.show', (int) $revision['id'])
                ->with('success', 'Revisão criada. Envie para o cliente aprovar as novas condições.');
        }

        return $this->redirectAfterPersist($budget, $submissionMode, false, $orcamento, $dispatchChannel);
    }

    public function sendApproval(Request $request, int $orcamento): RedirectResponse
    {
        // Esta mesma ação atende dois fluxos: pedir aprovação (orçamento ainda em
        // decisão) ou só compartilhar o PDF/link para o cliente consultar
        // (orçamento já aprovado). Quem decide qual dos dois é o backend
        // (BudgetApprovalService::dispatchForApproval), então as mensagens aqui
        // ficam neutras — "ao cliente" em vez de "para aprovação" — para não
        // contradizer um envio de consulta.
        try {
            $result = $this->orcamentoService->sendForApproval($orcamento, [
                'canal' => $this->resolveDispatchChannel($request),
            ]);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()
                ->route('orcamentos.show', $orcamento)
                ->with('error', 'O seu usuário não tem permissão para enviar este orçamento ao cliente.');
        } catch (ApiRequestException $exception) {
            return redirect()
                ->route('orcamentos.show', $orcamento)
                ->with('error', $this->approvalDispatchWarning($exception, 'O envio ao cliente não foi concluído.'));
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('orcamentos.show', $orcamento)
                ->with('error', 'Não foi possível enviar o orçamento ao cliente agora. Tente novamente.');
        }

        return redirect()
            ->route('orcamentos.show', $orcamento)
            ->with('success', trim((string) ($result['message'] ?? '')) !== '' ? $result['message'] : 'Orçamento enviado ao cliente.');
    }

    public function approve(Request $request, int $orcamento): RedirectResponse
    {
        // Orçamento com níveis de manutenção: a opção que o cliente escolheu
        // por outros meios vem do select do confirm (ver show.blade.php).
        $nivel = $request->integer('nivel');
        $nivel = $nivel >= 1 && $nivel <= 3 ? $nivel : null;

        return $this->handleStaffDecision(
            $request,
            $orcamento,
            fn (?string $note) => $this->orcamentoService->approve($orcamento, $note, $nivel),
            'observacao',
            'Aprovação registrada com sucesso.'
        );
    }

    public function reject(Request $request, int $orcamento): RedirectResponse
    {
        return $this->handleStaffDecision(
            $request,
            $orcamento,
            fn (?string $reason) => $this->orcamentoService->reject($orcamento, $reason),
            'motivo',
            'Rejeição registrada com sucesso.'
        );
    }

    public function cancel(Request $request, int $orcamento): RedirectResponse
    {
        return $this->handleStaffDecision(
            $request,
            $orcamento,
            fn (?string $reason) => $this->orcamentoService->cancel($orcamento, $reason),
            'motivo',
            'Orçamento cancelado com sucesso.'
        );
    }

    /**
     * Fluxo comum das ações de decisão do técnico (aprovar/rejeitar/cancelar):
     * lê o motivo/observação, chama o serviço e redireciona com feedback.
     */
    private function handleStaffDecision(
        Request $request,
        int $orcamento,
        callable $action,
        string $reasonField,
        string $successMessage
    ): RedirectResponse {
        $reason = trim((string) $request->input($reasonField, ''));
        $reason = $reason !== '' ? $reason : null;

        try {
            $action($reason);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()
                ->route('orcamentos.show', $orcamento)
                ->with('error', 'O seu usuário não tem permissão para esta ação no orçamento.');
        } catch (ApiRequestException $exception) {
            return redirect()
                ->route('orcamentos.show', $orcamento)
                ->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('orcamentos.show', $orcamento)
                ->with('error', 'Não foi possível concluir a ação agora. Tente novamente.');
        }

        return redirect()
            ->route('orcamentos.show', $orcamento)
            ->with('success', $successMessage);
    }

    public function destroy(int $orcamento): RedirectResponse
    {
        try {
            $this->orcamentoService->destroy($orcamento);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('orcamentos.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('orcamentos.index')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('orcamentos.index')->with('error', 'Não foi possível excluir o orçamento agora.');
        }

        return redirect()
            ->route('orcamentos.index')
            ->with('success', 'Orçamento excluído com sucesso.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedBudgetPayload(Request $request, bool $requireComplete = false): array
    {
        $normalized = $this->normalizeMoneyPayload(
            $request->all(),
            ['subtotal', 'desconto', 'acrescimo', 'total'],
            ['itens' => ['valor_unitario', 'desconto', 'acrescimo']]
        );

        $normalized = $this->normalizeDecimalPayload(
            $normalized,
            ['desconto_percentual', 'acrescimo_percentual'],
            ['itens' => ['desconto_percentual', 'acrescimo_percentual']]
        );

        $request->replace($normalized);

        $validated = $request->validate([
            'numero' => ['nullable', 'string', 'max:50'],
            'versao' => ['nullable', 'integer', 'min:1'],
            'tipo_orcamento' => ['required', 'string', Rule::in(['previo', 'assistencia'])],
            'status' => ['nullable', 'string', Rule::in([
                'rascunho',
                'pendente_envio',
                'enviado',
                'aguardando_resposta',
                'aguardando_pacote',
                'pacote_aprovado',
                'pendente',
                'aprovado',
                'reenviar_orcamento',
                'rejeitado',
                'vencido',
                'cancelado',
            ])],
            'origem' => ['nullable', 'string', Rule::in(['manual', 'os', 'conversa', 'cliente'])],
            'cliente_id' => ['nullable', 'integer', 'min:1'],
            'cliente_nome_avulso' => ['nullable', 'string', 'max:255', Rule::requiredIf(fn () => ! $request->filled('cliente_id'))],
            'telefone_contato' => array_values(array_filter([
                $requireComplete ? 'required' : 'nullable',
                'string',
                'max:30',
                $requireComplete
                    ? static function (string $attribute, mixed $value, \Closure $fail): void {
                        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

                        if (strlen($digits) < 10 || strlen($digits) > 11) {
                            $fail('Informe um telefone de contato válido, com DDD.');
                        }
                    }
                    : null,
            ])),
            'email_contato' => ['nullable', 'email', 'max:255'],
            'os_id' => ['nullable', 'integer', 'min:1'],
            'equipamento_id' => ['nullable', 'integer', 'min:1'],
            'envolve_equipamento' => ['nullable', 'boolean'],
            // Equipamento eventual (aparelho sem cadastro). Modelo é obrigatório
            // quando o orçamento é para reparo de um equipamento e não há
            // equipamento cadastrado nem OS vinculada.
            'equipamento_tipo_avulso' => ['nullable', 'string', 'max:120', Rule::requiredIf(
                fn (): bool => $requireComplete
                    && $request->boolean('envolve_equipamento')
                    && ! $request->filled('equipamento_id')
                    && ! $request->filled('os_id')
            )],
            'equipamento_marca_avulso' => ['nullable', 'string', 'max:120', Rule::requiredIf(
                fn (): bool => $requireComplete
                    && $request->boolean('envolve_equipamento')
                    && ! $request->filled('equipamento_id')
                    && ! $request->filled('os_id')
            )],
            'equipamento_modelo_avulso' => ['nullable', 'string', 'max:120', Rule::requiredIf(
                fn (): bool => $request->boolean('envolve_equipamento')
                    && ! $request->filled('equipamento_id')
                    && ! $request->filled('os_id')
            )],
            'equipamento_cor' => ['nullable', 'string', 'max:100', Rule::requiredIf(
                fn (): bool => $requireComplete
                    && $request->boolean('envolve_equipamento')
                    && ! $request->filled('equipamento_id')
                    && ! $request->filled('os_id')
            )],
            'titulo' => ['nullable', 'string', 'max:255'],
            // Relato do cliente / defeito relatado: obrigatório em qualquer
            // orçamento novo (inclusive serviço sem aparelho).
            'relato_cliente' => ['nullable', 'string', 'max:5000', Rule::requiredIf(
                fn (): bool => $requireComplete
            )],
            'validade_dias' => ['nullable', 'integer', 'min:0'],
            'validade_data' => ['nullable', 'date'],
            // Obrigatório em qualquer orçamento novo.
            'prazo_execucao' => ['nullable', 'string', 'max:255', Rule::requiredIf(
                fn (): bool => $requireComplete
            )],
            'observacoes' => ['nullable', 'string'],
            'condicoes' => ['nullable', 'string'],
            // Condições comerciais estruturadas. O marcador vazio enviado pelo
            // formulário (formas_pagamento[] = '') é filtrado abaixo, para
            // desmarcar tudo chegar ao backend como lista vazia de verdade.
            'formas_pagamento' => ['nullable', 'array'],
            'formas_pagamento.*' => ['nullable', 'string', 'max:40'],
            'garantia_dias' => ['nullable', 'integer', Rule::in([90, 180, 365, 730])],
            'parcelas_sem_juros' => ['nullable', 'integer', 'min:2', 'max:24'],
            'entrega_domicilio' => ['nullable', 'boolean'],
            // Sem entrada em niveis_condicoes.*: emissão de NFS-e não varia
            // por opção de manutenção, então não tem override por nível.
            'emite_nota_fiscal' => ['nullable', 'boolean'],
            // Níveis de manutenção: recomendação é opcional; o backend descarta
            // quando o orçamento não tem níveis.
            'nivel_recomendado' => ['nullable', 'integer', Rule::in([1, 2, 3])],
            // Interruptor "Oferecer opções de manutenção" do formulário. Não
            // vai ao backend: '0' força todo item em [1] aqui mesmo (verdade
            // no servidor, mesmo se o JS falhar); '1' exige que todo item
            // esteja em ao menos uma opção (ver normalização abaixo).
            'oferece_opcoes' => ['nullable', Rule::in(['0', '1', 0, 1])],
            // Condições por opção de manutenção (chave = nível). Campo vazio
            // herda o padrão; entrega é tri-state ('' herda, '1' sim, '0' não);
            // diferenciais chegam como textarea "um por linha".
            'niveis_condicoes' => ['nullable', 'array'],
            'niveis_condicoes.*.garantia_dias' => ['nullable', 'integer', Rule::in([90, 180, 365, 730])],
            'niveis_condicoes.*.parcelas_sem_juros' => ['nullable', 'integer', 'min:2', 'max:24'],
            'niveis_condicoes.*.entrega_domicilio' => ['nullable', Rule::in(['0', '1', 0, 1])],
            'niveis_condicoes.*.formas_pagamento' => ['nullable', 'array'],
            'niveis_condicoes.*.formas_pagamento.*' => ['nullable', 'string', 'max:40'],
            'niveis_condicoes.*.beneficios' => ['nullable', 'string', 'max:1500'],
            'subtotal' => ['nullable', 'numeric'],
            'desconto' => ['nullable', 'numeric'],
            'desconto_tipo' => ['nullable', 'string', Rule::in(['valor', 'percentual'])],
            'desconto_percentual' => ['nullable', 'numeric', 'min:0'],
            'acrescimo' => ['nullable', 'numeric'],
            'acrescimo_tipo' => ['nullable', 'string', Rule::in(['valor', 'percentual'])],
            'acrescimo_percentual' => ['nullable', 'numeric', 'min:0'],
            'total' => $requireComplete
                ? ['required', 'numeric', 'gt:0']
                : ['nullable', 'numeric'],
            'itens' => $requireComplete
                ? ['required', 'array', 'min:1']
                : ['nullable', 'array'],
            'itens.*.tipo_item' => ['required_with:itens', 'string', Rule::in(['servico', 'peca'])],
            'itens.*.referencia_id' => ['nullable', 'integer', 'min:1'],
            'itens.*.descricao' => $requireComplete
                ? ['required', 'string', 'max:255']
                : ['nullable', 'string', 'max:255'],
            'itens.*.quantidade' => $requireComplete
                ? ['required', 'numeric', 'gt:0']
                : ['nullable', 'numeric', 'min:0'],
            'itens.*.valor_unitario' => $requireComplete
                ? ['required', 'numeric', 'gt:0']
                : ['nullable', 'numeric', 'min:0'],
            'itens.*.desconto' => ['nullable', 'numeric', 'min:0'],
            'itens.*.desconto_tipo' => ['nullable', 'string', Rule::in(['valor', 'percentual'])],
            'itens.*.desconto_percentual' => ['nullable', 'numeric', 'min:0'],
            'itens.*.acrescimo' => ['nullable', 'numeric', 'min:0'],
            'itens.*.acrescimo_tipo' => ['nullable', 'string', Rule::in(['valor', 'percentual'])],
            'itens.*.acrescimo_percentual' => ['nullable', 'numeric', 'min:0'],
            'itens.*.observacoes' => ['nullable', 'string'],
            'itens.*.modo_precificacao' => ['nullable', 'string', 'max:50'],
            'itens.*.niveis' => ['nullable', 'array'],
            'itens.*.niveis.*' => ['integer', Rule::in([1, 2, 3])],
            // Só usados quando a OS vinculada está encerrada — ver
            // orcamentos/_admin_confirm_modal.blade.php.
            'admin_email' => ['nullable', 'string'],
            'admin_password' => ['nullable', 'string'],
            // Confirma proposta de revisão de valor/cliente num orçamento
            // convertido — ver orcamentos/form.blade.php (botão "Propor nova
            // versão") e BudgetWorkflowService::updateConvertedBudget().
            'propor_revisao' => ['nullable', 'boolean'],
        ], [], [
            'numero' => 'número',
            'versao' => 'versão',
            'tipo_orcamento' => 'tipo de orçamento',
            'status' => 'status',
            'origem' => 'origem',
            'cliente_id' => 'cliente',
            'cliente_nome_avulso' => 'nome do cliente avulso',
            'telefone_contato' => 'telefone de contato',
            'email_contato' => 'e-mail de contato',
            'os_id' => 'OS',
            'equipamento_id' => 'equipamento',
            'equipamento_tipo_avulso' => 'tipo do equipamento',
            'equipamento_marca_avulso' => 'marca do equipamento',
            'equipamento_modelo_avulso' => 'modelo do equipamento',
            'equipamento_cor' => 'cor do equipamento',
            'relato_cliente' => 'relato do cliente',
            'titulo' => 'título',
            'validade_dias' => 'validade em dias',
            'validade_data' => 'validade',
            'prazo_execucao' => 'prazo de execução',
            'observacoes' => 'observações',
            'condicoes' => 'condições',
            'formas_pagamento' => 'formas de pagamento aceitas',
            'garantia_dias' => 'garantia',
            'parcelas_sem_juros' => 'parcelamento sem juros',
            'nivel_recomendado' => 'opção recomendada',
            'oferece_opcoes' => 'oferecer opções de manutenção',
            'subtotal' => 'subtotal',
            'desconto' => 'desconto',
            'desconto_tipo' => 'tipo do desconto',
            'desconto_percentual' => 'percentual do desconto',
            'acrescimo' => 'acréscimo',
            'acrescimo_tipo' => 'tipo do acréscimo',
            'acrescimo_percentual' => 'percentual do acréscimo',
            'total' => 'total',
        ]);

        // Antes do filter()/values() abaixo: o índice original do item precisa
        // sobreviver para a chave do erro bater com o campo do formulário.
        $this->applyMaintenanceOptionsSwitch($validated);

        $validated['itens'] = collect($validated['itens'] ?? [])
            ->filter(fn (array $item): bool => $this->itemHasMeaningfulContent($item))
            ->values()
            ->all();

        $validated['formas_pagamento'] = collect($validated['formas_pagamento'] ?? [])
            ->map(static fn ($code): string => trim((string) $code))
            ->filter(static fn (string $code): bool => $code !== '')
            ->unique()
            ->values()
            ->all();

        // Campo travado (orçamento convertido em modo revisão) não é enviado
        // e não pode virar false por engano: só normaliza quando veio.
        if (array_key_exists('entrega_domicilio', $validated)) {
            $validated['entrega_domicilio'] = filter_var($validated['entrega_domicilio'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('emite_nota_fiscal', $validated)) {
            $validated['emite_nota_fiscal'] = filter_var($validated['emite_nota_fiscal'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('niveis_condicoes', $validated)) {
            $validated['niveis_condicoes'] = collect(is_array($validated['niveis_condicoes']) ? $validated['niveis_condicoes'] : [])
                ->map(function (mixed $linha): array {
                    $linha = is_array($linha) ? $linha : [];
                    $linha['formas_pagamento'] = collect($linha['formas_pagamento'] ?? [])
                        ->map(static fn ($code): string => trim((string) $code))
                        ->filter(static fn (string $code): bool => $code !== '')
                        ->unique()
                        ->values()
                        ->all();
                    $entrega = $linha['entrega_domicilio'] ?? null;
                    $linha['entrega_domicilio'] = $entrega === null || $entrega === ''
                        ? null
                        : (string) $entrega === '1';
                    $linha['beneficios'] = $this->splitTextLines((string) ($linha['beneficios'] ?? ''));

                    return $linha;
                })
                ->all();
        }

        if ($requireComplete) {
            $this->ensureCompleteBudgetFinancials($validated);
        }

        return $validated;
    }

    /**
     * Textarea "um por linha" → lista sem vazios (mesma ideia de
     * OrderPdfContextFactory::splitTextList no backend, sem quebrar em vírgula
     * — um diferencial pode ter vírgula no texto).
     *
     * @return array<int, string>
     */
    private function splitTextLines(string $value): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($value)) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $line): string => trim($line), $lines),
            static fn (string $line): bool => $line !== ''
        ));
    }

    /**
     * Recalcula os totais com os mesmos modos de ajuste usados pelo backend
     * central. O total enviado pelo navegador nunca é usado como prova de que o
     * orçamento está completo.
     *
     * @param  array<string, mixed>  $payload
     */
    private function ensureCompleteBudgetFinancials(array $payload): void
    {
        $subtotal = 0.0;
        $errors = [];

        foreach ((array) ($payload['itens'] ?? []) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $base = round(
                max(0, (float) ($item['quantidade'] ?? 0))
                    * max(0, (float) ($item['valor_unitario'] ?? 0)),
                2
            );
            $discount = $this->resolveBudgetAdjustment($base, $item, 'desconto');
            $addition = $this->resolveBudgetAdjustment($base, $item, 'acrescimo');
            $itemTotal = round($base - $discount + $addition, 2);

            if ($itemTotal <= 0) {
                $errors["itens.$index.valor_unitario"] = [
                    'Os ajustes deste item não podem resultar em total igual ou menor que zero.',
                ];
            }

            $subtotal += $itemTotal;
        }

        $subtotal = round($subtotal, 2);
        $discount = $this->resolveBudgetAdjustment($subtotal, $payload, 'desconto');
        $addition = $this->resolveBudgetAdjustment($subtotal, $payload, 'acrescimo');
        $total = round($subtotal - $discount + $addition, 2);

        if ($total <= 0) {
            $errors['total'] = ['O total final do orçamento deve ser maior que zero.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function resolveBudgetAdjustment(float $base, array $scope, string $prefix): float
    {
        $mode = trim((string) ($scope[$prefix.'_tipo'] ?? 'valor'));

        if ($mode === 'percentual') {
            $percent = max(0, (float) ($scope[$prefix.'_percentual'] ?? 0));

            return round($base * ($percent / 100), 2);
        }

        return round(max(0, (float) ($scope[$prefix] ?? 0)), 2);
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

    /**
     * @param  array<string, mixed>  $budget
     */
    private function redirectAfterPersist(
        array $budget,
        string $submissionMode,
        bool $created,
        int $fallbackBudgetId = 0,
        string $dispatchChannel = 'whatsapp'
    ): RedirectResponse {
        $budgetId = (int) ($budget['id'] ?? $fallbackBudgetId);
        $successMessage = $created
            ? 'Orçamento criado com sucesso.'
            : 'Orçamento atualizado com sucesso.';

        // Valor alterado após aprovação (ver BudgetWorkflowService::updateBudget()):
        // o botão "Reenviar para aprovação" já aparece sozinho em orcamentos/show.blade.php
        // quando o status é reenviar_orcamento — só falta explicar o porquê.
        $resendInfo = ! $created && (string) ($budget['status'] ?? '') === 'reenviar_orcamento'
            ? 'Valor do orçamento alterado — reenvie para nova aprovação do cliente.'
            : null;

        if ($budgetId <= 0 || $submissionMode !== 'send_for_approval') {
            $redirect = redirect()
                ->route('orcamentos.show', $budgetId)
                ->with('success', $successMessage);

            return $resendInfo !== null ? $redirect->with('info', $resendInfo) : $redirect;
        }

        try {
            $this->orcamentoService->sendForApproval($budgetId, ['canal' => $dispatchChannel]);

            return redirect()
                ->route('orcamentos.show', $budgetId)
                ->with('success', $created
                    ? 'Orçamento criado e enviado para aprovação do cliente.'
                    : 'Orçamento atualizado e enviado para aprovação do cliente.');
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()
                ->route('orcamentos.show', $budgetId)
                ->with('success', $successMessage)
                ->with('warning', 'O orçamento foi salvo, mas o seu usuário não tem permissão para enviá-lo para aprovação.');
        } catch (ApiRequestException $exception) {
            return redirect()
                ->route('orcamentos.show', $budgetId)
                ->with('success', $successMessage)
                ->with('warning', $this->approvalDispatchWarning($exception));
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('orcamentos.show', $budgetId)
                ->with('success', $successMessage)
                ->with('warning', 'O orçamento foi salvo, mas não foi possível concluir o envio para aprovação agora.');
        }
    }

    private function resolveSubmissionMode(Request $request): string
    {
        return $request->input('submission_mode') === 'send_for_approval'
            ? 'send_for_approval'
            : 'save_only';
    }

    /**
     * Meio de envio da proposta ao cliente (WhatsApp, e-mail ou ambos) —
     * escolhido no seletor de canal exibido quando há e-mail cadastrado.
     */
    private function resolveDispatchChannel(Request $request): string
    {
        $canal = trim((string) $request->input('canal', $request->input('canal_envio', 'whatsapp')));

        return in_array($canal, ['whatsapp', 'email', 'ambos'], true) ? $canal : 'whatsapp';
    }

    private function approvalDispatchWarning(
        ApiRequestException $exception,
        string $prefix = 'O orçamento foi salvo, mas o envio para aprovação não foi concluído.'
    ): string {
        $messages = collect($this->formatApiErrors($exception))
            ->flatMap(static fn (array $items): array => $items)
            ->filter(static fn ($message): bool => is_string($message) && trim($message) !== '')
            ->values();

        $details = $messages->isNotEmpty()
            ? ' Pendências: '.$messages->implode(' | ')
            : '';

        return $prefix
            .($exception->getMessage() !== '' ? ' '.$exception->getMessage() : '')
            .$details;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemHasMeaningfulContent(array $item): bool
    {
        $description = trim((string) ($item['descricao'] ?? ''));
        $notes = trim((string) ($item['observacoes'] ?? ''));
        $referenceId = (int) ($item['referencia_id'] ?? 0);
        $quantity = (float) ($item['quantidade'] ?? 1);
        $unitPrice = (float) ($item['valor_unitario'] ?? 0);
        $discount = (float) ($item['desconto'] ?? 0);
        $discountPercent = (float) ($item['desconto_percentual'] ?? 0);
        $addition = (float) ($item['acrescimo'] ?? 0);
        $additionPercent = (float) ($item['acrescimo_percentual'] ?? 0);

        return $description !== ''
            || $notes !== ''
            || $referenceId > 0
            || abs($quantity - 1.0) > 0.0001
            || $unitPrice > 0
            || $discount > 0
            || $discountPercent > 0
            || $addition > 0
            || $additionPercent > 0;
    }

    /**
     * Interruptor "Oferecer opções de manutenção" (chave `oferece_opcoes`,
     * nunca repassada ao backend):
     *
     * - '0' → orçamento comum: todo item fica só na Básica e a recomendação
     *   cai, independentemente do que o formulário mandou nos checkboxes
     *   ocultos (o JS já faz isso; aqui é a garantia no servidor);
     * - '1' → item com conteúdo e sem nível nenhum é erro de composição — o
     *   backend o gravaria em [1] em silêncio, e o técnico não decidiu isso.
     *
     * Sem a chave (formulário antigo, revisão travada) nada muda.
     *
     * @param  array<string, mixed>  $validated
     */
    private function applyMaintenanceOptionsSwitch(array &$validated): void
    {
        if (! array_key_exists('oferece_opcoes', $validated)) {
            return;
        }

        $offersOptions = $validated['oferece_opcoes'] === null ? null : (string) $validated['oferece_opcoes'] === '1';
        unset($validated['oferece_opcoes']);

        if ($offersOptions === null) {
            return;
        }

        $items = is_array($validated['itens'] ?? null) ? $validated['itens'] : [];

        if ($offersOptions === false) {
            foreach ($items as $index => $item) {
                if (is_array($item)) {
                    $items[$index]['niveis'] = [1];
                }
            }
            $validated['itens'] = $items;
            $validated['nivel_recomendado'] = null;

            return;
        }

        $errors = [];
        foreach ($items as $index => $item) {
            if (! is_array($item) || ! $this->itemHasMeaningfulContent($item)) {
                continue;
            }

            $niveis = is_array($item['niveis'] ?? null) ? $item['niveis'] : [];
            if ($niveis !== []) {
                continue;
            }

            $description = trim((string) ($item['descricao'] ?? ''));
            $label = $description !== '' ? '"' . $description . '"' : (string) ((int) $index + 1);
            $errors['itens.' . $index . '.niveis'] = [
                'O item ' . $label . ' não está em nenhuma opção de manutenção — inclua-o em uma opção ou exclua o item.',
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Catálogo de tipo/marca/modelo (o mesmo usado na abertura de OS e no
     * cadastro de equipamento) para o Select2 de "Equipamento eventual": marca
     * e modelo digitados aqui são casados contra o catálogo real em vez de
     * texto solto, e o operador pode cadastrar marca/modelo novos direto no
     * catálogo (equipments.brands.quick.store / equipments.models.quick.store)
     * quando o desejado não existir — melhorando a base para todo o sistema.
     *
     * Falha ao buscar não deve impedir a página de orçamento de carregar: o
     * catálogo é uma melhoria de UX sobre os campos de texto, não um
     * pré-requisito. Em falha, os campos voltam a aceitar texto livre.
     *
     * @return array<string, mixed>
     */
    private function equipmentCatalogForForm(): array
    {
        try {
            $form = $this->equipmentService->formData();
        } catch (Throwable $exception) {
            report($exception);

            return ['types' => [], 'brands' => [], 'models' => [], 'catalog_relations' => []];
        }

        return [
            'types' => $form['types'] ?? [],
            'brands' => $form['brands'] ?? [],
            'models' => $form['models'] ?? [],
            'catalog_relations' => $form['catalog_relations'] ?? [],
        ];
    }

    private function quickCatalogConfig(): array
    {
        return [
            'service' => [
                'enabled' => DesktopSession::can('servicos', 'criar'),
                'label' => 'Serviço',
                'title' => 'Cadastro rápido de serviço',
                'submit_label' => 'Cadastrar serviço',
                'store_url' => route('servicos.quick.store'),
                'full_url' => route('servicos.create'),
            ],
            'part' => [
                'enabled' => DesktopSession::can('estoque', 'criar'),
                'label' => 'Peça',
                'title' => 'Cadastro rápido de peça',
                'submit_label' => 'Cadastrar peça',
                'store_url' => route('estoque.quick.store'),
                'full_url' => route('estoque.create'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    private function jsonFailure(string $message, int $status, ?array $details = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $details ?? [],
        ], $status);
    }
}
