<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\ClientService;
use App\Services\EquipmentService;
use App\Services\OrcamentoService;
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
        private readonly EquipmentService $equipmentService
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
        return $this->handleStaffDecision(
            $request,
            $orcamento,
            fn (?string $note) => $this->orcamentoService->approve($orcamento, $note),
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
            'subtotal' => 'subtotal',
            'desconto' => 'desconto',
            'desconto_tipo' => 'tipo do desconto',
            'desconto_percentual' => 'percentual do desconto',
            'acrescimo' => 'acréscimo',
            'acrescimo_tipo' => 'tipo do acréscimo',
            'acrescimo_percentual' => 'percentual do acréscimo',
            'total' => 'total',
        ]);

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

        if ($requireComplete) {
            $this->ensureCompleteBudgetFinancials($validated);
        }

        return $validated;
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
     * @return array<string, array<string, mixed>>
     */
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
