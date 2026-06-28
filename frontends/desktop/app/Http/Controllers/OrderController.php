<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\ClientService;
use App\Services\DesktopOrderStatusFlowService;
use App\Services\EquipmentService;
use App\Services\OrderService;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class OrderController extends DesktopController
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly ClientService $clientService,
        private readonly EquipmentService $equipmentService,
        private readonly UserService $userService,
        private readonly DesktopOrderStatusFlowService $statusFlowService
    ) {
    }

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => trim((string) $request->query('status', '')),
            'status_scope' => trim((string) $request->query('status_scope', '')),
            'client_id' => (int) $request->query('client_id', 0),
            'equipment_id' => (int) $request->query('equipment_id', 0),
            'technician_id' => (int) $request->query('technician_id', 0),
            'grupo_macro' => trim((string) $request->query('grupo_macro', '')),
            'data_abertura_de' => trim((string) $request->query('data_abertura_de', '')),
            'data_abertura_ate' => trim((string) $request->query('data_abertura_ate', '')),
            'valor_min' => trim((string) $request->query('valor_min', '')),
            'valor_max' => trim((string) $request->query('valor_max', '')),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 15),
        ];

        if ($this->shouldDefaultToOpenScope($filters)) {
            $filters['status_scope'] = 'open';
        }

        $result = $this->orderService->paginate(array_filter($filters, static fn ($value) => $value !== '' && $value !== 0));
        $statuses = $this->resolveStatusCatalog();

        return view('orders.index', [
            'pageTitle' => 'Ordens de Serviço',
            'orders' => $result['items'],
            'pagination' => $result['pagination'],
            'filters' => $filters,
            'technicians' => $this->resolveTechnicianOptions(),
            'statusOptions' => $statuses,
            'macroGroupOptions' => $this->resolveMacroGroupOptions($statuses),
        ]);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function shouldDefaultToOpenScope(array $filters): bool
    {
        return trim((string) ($filters['status_scope'] ?? '')) === ''
            && trim((string) ($filters['search'] ?? '')) === ''
            && trim((string) ($filters['status'] ?? '')) === ''
            && (int) ($filters['client_id'] ?? 0) <= 0
            && (int) ($filters['equipment_id'] ?? 0) <= 0
            && (int) ($filters['technician_id'] ?? 0) <= 0
            && trim((string) ($filters['grupo_macro'] ?? '')) === ''
            && trim((string) ($filters['data_abertura_de'] ?? '')) === ''
            && trim((string) ($filters['data_abertura_ate'] ?? '')) === ''
            && trim((string) ($filters['valor_min'] ?? '')) === ''
            && trim((string) ($filters['valor_max'] ?? '')) === '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveTechnicianOptions(): array
    {
        try {
            $result = $this->userService->paginate(['per_page' => 100, 'active' => 1]);
        } catch (ApiAuthenticationException|ApiAuthorizationException|ApiRequestException) {
            // O filtro de tecnico e um complemento operacional: se o usuario atual nao
            // tem permissao de visualizar usuarios (ou a API falhar), a listagem de OS
            // continua funcionando normalmente, apenas sem opcoes nesse filtro.
            return [];
        }

        return array_values(array_filter(
            $result['items'],
            static fn (array $user): bool => mb_strtolower(trim((string) ($user['perfil'] ?? ''))) === 'tecnico'
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveStatusCatalog(): array
    {
        try {
            return $this->statusFlowService->index()['statuses'];
        } catch (ApiAuthenticationException|ApiAuthorizationException|ApiRequestException) {
            // Sem o catalogo (ex.: usuario sem permissao de conhecimento), os filtros de
            // status e macrofase caem para campo de texto livre em vez de quebrar a tela.
            return [];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $statuses
     * @return array<int, string>
     */
    private function resolveMacroGroupOptions(array $statuses): array
    {
        $groups = [];

        foreach ($statuses as $status) {
            $group = trim((string) ($status['grupo_macro'] ?? ''));
            if ($group !== '') {
                $groups[$group] = $group;
            }
        }

        return array_values($groups);
    }

    public function create(Request $request): View
    {
        $selectedClientId = (int) $request->query('cliente_id', 0);
        $selectedEquipmentId = (int) $request->query('equipamento_id', 0);

        $clients = $this->clientService->paginate([
            'per_page' => 100,
        ]);

        $equipmentFilters = [
            'per_page' => 100,
        ];

        if ($selectedClientId > 0) {
            $equipmentFilters['client_id'] = $selectedClientId;
        }

        $equipments = $this->equipmentService->paginate($equipmentFilters);

        return view('orders.create', [
            'pageTitle' => 'Nova OS',
            'clients' => $clients['items'],
            'equipments' => $equipments['items'],
            'selectedClientId' => $selectedClientId,
            'selectedEquipmentId' => $selectedEquipmentId,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'cliente_id' => ['required', 'integer', 'min:1'],
            'equipamento_id' => ['required', 'integer', 'min:1'],
            'relato_cliente' => ['required', 'string', 'min:5'],
            'prioridade' => ['nullable', 'string', 'in:baixa,normal,alta,urgente'],
            'tecnico_id' => ['nullable', 'integer', 'min:1'],
            'data_previsao' => ['nullable', 'date'],
            'observacoes_internas' => ['nullable', 'string'],
        ], [], [
            'cliente_id' => 'cliente',
            'equipamento_id' => 'equipamento',
            'relato_cliente' => 'relato do cliente',
            'prioridade' => 'prioridade',
            'tecnico_id' => 'técnico',
            'data_previsao' => 'data de previsão',
            'observacoes_internas' => 'observações internas',
        ]);

        $payload = array_filter([
            'cliente_id' => (int) $validated['cliente_id'],
            'equipamento_id' => (int) $validated['equipamento_id'],
            'relato_cliente' => trim((string) $validated['relato_cliente']),
            'prioridade' => $validated['prioridade'] ?? null,
            'tecnico_id' => isset($validated['tecnico_id']) ? (int) $validated['tecnico_id'] : null,
            'data_previsao' => $validated['data_previsao'] ?? null,
            'observacoes_internas' => trim((string) ($validated['observacoes_internas'] ?? '')),
        ], static fn ($value): bool => $value !== null && $value !== '');

        $order = $this->orderService->create($payload);

        return redirect()
            ->route('orders.show', $order['id'] ?? 0)
            ->with('success', 'Nova OS criada com sucesso.');
    }

    public function show(int $order): View
    {
        return view('orders.show', [
            'pageTitle' => 'Detalhe da OS',
            'order' => $this->orderService->find($order),
        ]);
    }

    public function edit(int $order): View
    {
        $orderData = $this->orderService->find($order);
        $selectedClientId = (int) ($orderData['cliente_id'] ?? 0);

        $clients = $this->clientService->paginate([
            'per_page' => 100,
        ]);

        $equipmentFilters = [
            'per_page' => 100,
        ];

        if ($selectedClientId > 0) {
            $equipmentFilters['client_id'] = $selectedClientId;
        }

        $equipments = $this->equipmentService->paginate($equipmentFilters);

        return view('orders.edit', [
            'pageTitle' => 'Editar OS',
            'order' => $orderData,
            'clients' => $clients['items'],
            'equipments' => $equipments['items'],
        ]);
    }

    public function update(Request $request, int $order): RedirectResponse
    {
        $validated = $request->validate([
            'cliente_id' => ['required', 'integer', 'min:1'],
            'equipamento_id' => ['required', 'integer', 'min:1'],
            'relato_cliente' => ['required', 'string', 'min:5'],
            'prioridade' => ['nullable', 'string', 'in:baixa,normal,alta,urgente'],
            'tecnico_id' => ['nullable', 'integer', 'min:1'],
            'data_previsao' => ['nullable', 'date'],
            'observacoes_internas' => ['nullable', 'string'],
        ], [], [
            'cliente_id' => 'cliente',
            'equipamento_id' => 'equipamento',
            'relato_cliente' => 'relato do cliente',
            'prioridade' => 'prioridade',
            'tecnico_id' => 'técnico',
            'data_previsao' => 'data de previsão',
            'observacoes_internas' => 'observações internas',
        ]);

        $payload = [
            'cliente_id' => (int) $validated['cliente_id'],
            'equipamento_id' => (int) $validated['equipamento_id'],
            'relato_cliente' => trim((string) $validated['relato_cliente']),
            'prioridade' => $validated['prioridade'] ?? null,
            'tecnico_id' => isset($validated['tecnico_id']) ? (int) $validated['tecnico_id'] : null,
            'data_previsao' => $validated['data_previsao'] ?? null,
            'observacoes_internas' => trim((string) ($validated['observacoes_internas'] ?? '')),
        ];

        $this->orderService->update($order, $payload);

        return redirect()
            ->route('orders.show', $order)
            ->with('success', 'OS atualizada com sucesso.');
    }

    public function closureShow(int $order): View
    {
        return view('orders.closure', [
            'pageTitle' => 'Baixa da OS',
            'order' => $this->orderService->find($order),
            'closure' => $this->orderService->closureMetadata($order),
        ]);
    }

    public function closureStore(Request $request, int $order): RedirectResponse
    {
        $validated = $request->validate([
            'encerrar_como' => ['required', 'string'],
            'data_entrega' => ['required', 'date'],
            'observacao' => ['nullable', 'string'],
            'notificar_cliente' => ['nullable', 'boolean'],
            'agendar_retorno' => ['nullable', 'boolean'],
            'retorno_data' => ['nullable', 'date'],
            'recebimentos' => ['nullable', 'array'],
            'recebimentos.*.valor' => ['required', 'numeric', 'min:0.01'],
            'recebimentos.*.classificacao_recebimento' => ['nullable', 'string'],
            'recebimentos.*.forma_pagamento' => ['nullable', 'string'],
            'recebimentos.*.data_pagamento' => ['nullable', 'date'],
            'recebimentos.*.observacoes' => ['nullable', 'string'],
            'recebimentos.*.operadora_id' => ['nullable', 'integer'],
            'recebimentos.*.bandeira_id' => ['nullable', 'integer'],
            'recebimentos.*.modalidade' => ['nullable', 'string'],
            'recebimentos.*.parcelas' => ['nullable', 'integer'],
        ], [], [
            'encerrar_como' => 'forma de encerramento',
            'data_entrega' => 'data de entrega',
            'observacao' => 'observação',
            'retorno_data' => 'data de retorno',
            'recebimentos.*.valor' => 'valor do recebimento',
        ]);

        $payload = array_filter([
            'encerrar_como' => $validated['encerrar_como'],
            'data_entrega' => $validated['data_entrega'],
            'observacao' => $validated['observacao'] ?? null,
            'notificar_cliente' => $request->boolean('notificar_cliente'),
            'agendar_retorno' => $request->boolean('agendar_retorno'),
            'retorno_data' => $validated['retorno_data'] ?? null,
            'recebimentos' => $validated['recebimentos'] ?? [],
        ], static fn ($value): bool => $value !== null && $value !== '');

        $result = $this->orderService->close($order, $payload);

        $message = 'OS encerrada com sucesso.';
        if (($result['notificacao_enviada'] ?? null) === false) {
            $message .= ' O cliente não pôde ser notificado por WhatsApp agora.';
        }

        return redirect()
            ->route('orders.show', $order)
            ->with('success', $message);
    }

    public function preview(int $order): View
    {
        return view('orders.preview', [
            'pageTitle' => 'Pré-visualização da OS',
            'order' => $this->orderService->find($order),
        ]);
    }

    public function updateStatus(Request $request, int $order): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string'],
            'observacao' => ['nullable', 'string'],
        ], [], [
            'status' => 'status',
            'observacao' => 'observação',
        ]);

        $updatedOrder = $this->orderService->updateStatus(
            $order,
            $validated['status'],
            $validated['observacao'] ?? null
        );

        return redirect()
            ->route('orders.show', $order)
            ->with('success', 'Status da OS atualizado para ' . ($updatedOrder['status_nome'] ?? 'o novo estado') . '.');
    }

    public function photo(int $order, int $photo): Response
    {
        $file = $this->orderService->downloadPhoto($order, $photo);

        return response($file['body'], $file['status'])
            ->withHeaders($file['headers']);
    }

    public function document(int $order, int $document): Response
    {
        $file = $this->orderService->downloadDocument($order, $document);

        return response($file['body'], $file['status'])
            ->withHeaders($file['headers']);
    }
}
