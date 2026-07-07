<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\ClientService;
use App\Services\DesktopOrderStatusFlowService;
use App\Services\EquipmentService;
use App\Services\OrderService;
use App\Services\ReportedDefectService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Throwable;

class OrderController extends DesktopController
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly ClientService $clientService,
        private readonly EquipmentService $equipmentService,
        private readonly ReportedDefectService $reportedDefectService,
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
            // Cacheado por ser catalogo de referencia (lista de tecnicos ativos), igual
            // para qualquer usuario com acesso; evita repetir essa chamada na API a cada
            // carregamento da listagem de OS. Erros (sem permissao/API fora) nao sao
            // cacheados, entao um usuario sem acesso nunca "contamina" o cache para quem tem.
            $result = Cache::remember(
                'desktop:order_filters:technicians',
                60,
                fn (): array => $this->userService->paginate(['per_page' => 100, 'active' => 1])
            );
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
            // Mesmo raciocinio de cache curto do resolveTechnicianOptions(): catalogo de
            // status e dado de referencia, nao muda a cada request.
            return Cache::remember(
                'desktop:order_filters:status_catalog',
                60,
                fn (): array => $this->statusFlowService->index()['statuses']
            );
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
        $oldInput = $request->session()->getOldInput();

        $selectedClientId = (int) ($oldInput['cliente_id'] ?? $request->query('cliente_id', 0));
        $selectedEquipmentId = (int) ($oldInput['equipamento_id'] ?? $request->query('equipamento_id', 0));
        $selectedTechnicianId = (int) ($oldInput['tecnico_id'] ?? 0);

        $selectedEquipment = $this->resolveSelectedEquipment($selectedEquipmentId);

        if ($selectedClientId <= 0 && (int) ($selectedEquipment['cliente_id'] ?? 0) > 0) {
            $selectedClientId = (int) $selectedEquipment['cliente_id'];
        }

        $selectedClient = $this->resolveSelectedClient($selectedClientId);
        $technicians = $this->resolveTechnicianOptions();
        $selectedTechnician = $this->resolveSelectedTechnician($technicians, $selectedTechnicianId);
        $technicians = $this->ensureSelectedItemPresent($technicians, $selectedTechnicianId, $selectedTechnician);

        return view('orders.create', [
            'pageTitle' => 'Nova OS',
            'technicians' => $technicians,
            'selectedClientId' => $selectedClientId,
            'selectedEquipmentId' => $selectedEquipmentId,
            'selectedTechnicianId' => $selectedTechnicianId,
            'selectedClient' => $selectedClient,
            'selectedEquipment' => $selectedEquipment,
            'selectedTechnician' => $selectedTechnician,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSelectedClient(int $clientId): array
    {
        if ($clientId <= 0) {
            return [];
        }

        try {
            $client = $this->clientService->find($clientId);
        } catch (ApiAuthenticationException|ApiAuthorizationException|ApiRequestException) {
            $client = [];
        }

        if ($client === []) {
            $client = [
                'id' => $clientId,
                'nome_razao' => 'Cliente #' . $clientId,
                'telefone1' => '',
                'email' => '',
                'nome_contato' => '',
                'cidade' => '',
                'uf' => '',
            ];
        }

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSelectedEquipment(int $equipmentId): array
    {
        if ($equipmentId <= 0) {
            return [];
        }

        try {
            $equipment = $this->equipmentService->find($equipmentId);
        } catch (ApiAuthenticationException|ApiAuthorizationException|ApiRequestException) {
            return [];
        }

        if ($equipment !== []) {
            $equipment = $this->decorateEquipmentPhotoAccess($equipment);
        }

        return $equipment;
    }

    /**
     * @param array<int, array<string, mixed>> $technicians
     * @return array<string, mixed>
     */
    private function resolveSelectedTechnician(array $technicians, int $technicianId): array
    {
        foreach ($technicians as $technician) {
            if ((int) ($technician['id'] ?? 0) === $technicianId) {
                return $technician;
            }
        }

        if ($technicianId > 0) {
            return [
                'id' => $technicianId,
                'nome' => 'Tecnico #' . $technicianId,
                'email' => '',
            ];
        }

        return [];
    }

    public function searchEquipments(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'client_id' => ['nullable', 'integer', 'min:0'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $search = trim((string) ($validated['q'] ?? $validated['search'] ?? ''));
        $clientId = (int) ($validated['client_id'] ?? 0);
        $page = max(1, (int) ($validated['page'] ?? 1));
        $perPage = max(1, min(20, (int) ($validated['per_page'] ?? 10)));

        try {
            $result = $this->equipmentService->paginate(array_filter([
                'search' => $search,
                'client_id' => $clientId,
                'page' => $page,
                'per_page' => $perPage,
            ], static fn ($value): bool => $value !== '' && $value !== 0));
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
        }

        $items = array_map(function (array $equipment): array {
            $equipment = $this->decorateEquipmentPhotoAccess($equipment);
            $equipmentId = (int) ($equipment['id'] ?? 0);
            $summary = trim((string) ($equipment['resumo_tecnico'] ?? ''));
            $brandName = trim((string) ($equipment['marca_nome'] ?? ''));
            $modelName = trim((string) ($equipment['modelo_nome'] ?? ''));
            $brandModel = trim(implode(' / ', array_filter([
                $brandName,
                $modelName,
            ], static fn (string $value): bool => $value !== '')));
            $label = $summary !== '' ? $summary : $brandModel;

            return [
                'id' => $equipmentId,
                'label' => $label !== '' ? $label : ('Equipamento #' . $equipmentId),
                'summary' => $summary,
                'brand_name' => $brandName,
                'model_name' => $modelName,
                'serial' => trim((string) ($equipment['numero_serie'] ?? '')),
                'client_id' => (int) ($equipment['cliente_id'] ?? 0),
                'client_name' => trim((string) ($equipment['cliente_nome'] ?? '')),
                'photo_url' => trim((string) ($equipment['primary_photo_url'] ?? $equipment['photo_url'] ?? '')),
                'tipo_id' => (int) ($equipment['tipo_id'] ?? 0),
                'tipo_name' => trim((string) ($equipment['tipo_nome'] ?? '')),
            ];
        }, $result['items']);

        return response()->json([
            'success' => true,
            'equipments' => $items,
            'pagination' => $result['pagination'] ?? [],
        ]);
    }

    public function searchReportedDefects(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tipo_equipamento_id' => ['required', 'integer', 'min:1'],
        ]);

        $tipoEquipamentoId = (int) $validated['tipo_equipamento_id'];

        try {
            $result = $this->reportedDefectService->paginate([
                'tipo_equipamento_id' => $tipoEquipamentoId,
                'active' => 1,
                'per_page' => 50,
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
        }

        $items = array_map(static function (array $defeito): array {
            return [
                'id' => (int) ($defeito['id'] ?? 0),
                'categoria' => trim((string) ($defeito['categoria'] ?? '')),
                'subcategoria' => trim((string) ($defeito['subcategoria'] ?? '')),
                'texto_relato' => trim((string) ($defeito['texto_relato'] ?? '')),
                'icone' => trim((string) ($defeito['icone'] ?? '')),
                'ordem_exibicao' => (int) ($defeito['ordem_exibicao'] ?? 0),
            ];
        }, $result['items']);

        return response()->json([
            'success' => true,
            'defects' => $items,
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

        $search = trim((string) ($validated['q'] ?? $validated['search'] ?? ''));
        $page = max(1, (int) ($validated['page'] ?? 1));
        $perPage = max(1, min(20, (int) ($validated['per_page'] ?? 10)));

        try {
            $result = $this->clientService->paginate(array_filter([
                'search' => $search,
                'page' => $page,
                'per_page' => $perPage,
            ], static fn ($value): bool => $value !== '' && $value !== 0));
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
        }

        $items = array_map(static function (array $client): array {
            $clientId = (int) ($client['id'] ?? 0);
            $label = trim((string) ($client['nome_razao'] ?? ''));

            return [
                'id' => $clientId,
                'text' => $label !== '' ? $label : ('Cliente #' . $clientId),
                'name' => $label,
                'phone' => trim((string) ($client['telefone1'] ?? '')),
                'email' => trim((string) ($client['email'] ?? '')),
                'contact' => trim((string) ($client['nome_contato'] ?? '')),
                'city' => trim((string) ($client['cidade'] ?? '')),
                'uf' => trim((string) ($client['uf'] ?? '')),
            ];
        }, $result['items']);

        return response()->json([
            'success' => true,
            'clients' => $items,
            'pagination' => $result['pagination'] ?? [],
        ]);
    }

    /**
     * @param array<string, mixed> $equipment
     * @return array<string, mixed>
     */
    private function decorateEquipmentPhotoAccess(array $equipment): array
    {
        $equipmentId = (int) ($equipment['id'] ?? 0);
        $photoId = (int) ($equipment['primary_photo_id'] ?? 0);

        if ($photoId <= 0) {
            $photoId = $this->extractPhotoIdFromUrl((string) ($equipment['primary_photo_url'] ?? ''));
        }

        if ($equipmentId > 0 && $photoId > 0) {
            $desktopPhotoUrl = route('equipments.photos.show', [
                'equipment' => $equipmentId,
                'photo' => $photoId,
            ]);

            $equipment['primary_photo_id'] = $photoId;
            $equipment['primary_photo_url'] = $desktopPhotoUrl;
            $equipment['photo_url'] = $desktopPhotoUrl;

            return $equipment;
        }

        $fallbackPhotoUrl = trim((string) ($equipment['primary_photo_url'] ?? $equipment['photo_url'] ?? ''));
        $equipment['photo_url'] = $fallbackPhotoUrl;

        return $equipment;
    }

    private function extractPhotoIdFromUrl(string $url): int
    {
        $normalized = trim($url);
        if ($normalized === '') {
            return 0;
        }

        if (preg_match('~/(?:api/v1/)?equipments/\d+/photos/(\d+)(?:[/?#].*)?$~i', $normalized, $matches) === 1) {
            return (int) ($matches[1] ?? 0);
        }

        return 0;
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
            'fotos' => ['nullable', 'array', 'max:4'],
            'fotos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
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

        $order = $this->orderService->create(
            $payload,
            $this->extractUploadedFiles($request, 'fotos')
        );

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

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $selectedItem
     * @return array<int, array<string, mixed>>
     */
    private function ensureSelectedItemPresent(array $items, int $selectedId, array $selectedItem): array
    {
        if ($selectedId <= 0 || $selectedItem === []) {
            return $items;
        }

        foreach ($items as $item) {
            if ((int) ($item['id'] ?? 0) === $selectedId) {
                return $items;
            }
        }

        array_unshift($items, $selectedItem);

        return $items;
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function extractUploadedFiles(Request $request, string $key): array
    {
        $files = $request->file($key, []);

        if ($files instanceof UploadedFile) {
            return [$files];
        }

        if (! is_array($files)) {
            return [];
        }

        return array_values(array_filter(
            $files,
            static fn ($file): bool => $file instanceof UploadedFile && $file->isValid()
        ));
    }

    public function edit(Request $request, int $order): View
    {
        $orderData = $this->orderService->find($order);
        $oldInput = $request->session()->getOldInput();

        $selectedClientId = (int) ($oldInput['cliente_id'] ?? data_get($orderData, 'cliente_id', data_get($orderData, 'cliente.id', 0)));
        $selectedEquipmentId = (int) ($oldInput['equipamento_id'] ?? data_get($orderData, 'equipamento_id', data_get($orderData, 'equipamento.id', 0)));
        $selectedTechnicianId = (int) ($oldInput['tecnico_id'] ?? data_get($orderData, 'tecnico_id', data_get($orderData, 'tecnico.id', 0)));

        $selectedClient = $this->resolveSelectedClient($selectedClientId);
        $selectedEquipment = $this->resolveSelectedEquipment($selectedEquipmentId);
        $technicians = $this->resolveTechnicianOptions();
        $selectedTechnician = $this->resolveSelectedTechnician($technicians, $selectedTechnicianId);
        $technicians = $this->ensureSelectedItemPresent($technicians, $selectedTechnicianId, $selectedTechnician);

        return view('orders.edit', [
            'pageTitle' => 'Editar OS',
            'order' => $orderData,
            'technicians' => $technicians,
            'selectedClientId' => $selectedClientId,
            'selectedEquipmentId' => $selectedEquipmentId,
            'selectedTechnicianId' => $selectedTechnicianId,
            'selectedClient' => $selectedClient,
            'selectedEquipment' => $selectedEquipment,
            'selectedTechnician' => $selectedTechnician,
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
            'fotos' => ['nullable', 'array', 'max:4'],
            'fotos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
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

        $this->orderService->update(
            $order,
            $payload,
            $this->extractUploadedFiles($request, 'fotos')
        );

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

    public function statusContext(int $order): JsonResponse
    {
        try {
            $data = $this->orderService->find($order);
        } catch (ApiAuthenticationException $exception) {
            return response()->json(['error' => $exception->getMessage()], 401);
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'Não foi possível carregar os dados da OS.'], 500);
        }

        return response()->json([
            'id'                       => $data['id'] ?? $order,
            'numero_os'                => (string) ($data['numero_os'] ?? ('#' . $order)),
            'status'                   => (string) ($data['status'] ?? ''),
            'status_nome'              => (string) ($data['status_nome'] ?? ''),
            'status_cor'               => (string) ($data['status_cor'] ?? '#64748b'),
            'cliente_nome'             => (string) ($data['cliente_nome'] ?? ''),
            'cliente_telefone'         => (string) ($data['cliente']['telefone1'] ?? ''),
            'cliente_email'            => (string) ($data['cliente']['email'] ?? ''),
            'equipamento_nome'         => (string) ($data['equipamento_resumo_curto'] ?? ($data['equipamento_resumo_tecnico'] ?? '')),
            'equipamento_tipo_nome'    => (string) ($data['equipamento_tipo_nome'] ?? ''),
            'equipamento_numero_serie' => (string) ($data['equipamento_numero_serie'] ?? ''),
            'diagnostico_tecnico'      => (string) ($data['diagnostico_tecnico'] ?? ''),
            'solucao_aplicada'         => (string) ($data['solucao_aplicada'] ?? ''),
            'proximas_etapas'          => is_array($data['proximas_etapas'] ?? null) ? $data['proximas_etapas'] : [],
            'status_disponiveis'       => is_array($data['status_disponiveis'] ?? null) ? $data['status_disponiveis'] : [],
            'historico'                => array_slice(
                is_array($data['historico'] ?? null) ? $data['historico'] : [],
                0,
                8
            ),
            'procedimentos_historico'  => is_array($data['procedimentos_historico'] ?? null) ? $data['procedimentos_historico'] : [],
        ]);
    }

    public function storeProcedure(Request $request, int $order): JsonResponse
    {
        $validated = $request->validate([
            'descricao' => ['required', 'string'],
        ], [], [
            'descricao' => 'procedimento executado',
        ]);

        try {
            $updated = $this->orderService->addProcedure($order, $validated['descricao']);
        } catch (ApiAuthenticationException $exception) {
            return response()->json(['error' => $exception->getMessage()], 401);
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'Não foi possível salvar o procedimento executado.'], 500);
        }

        return response()->json([
            'message' => 'Procedimento registrado com sucesso.',
            'procedimentos_historico' => is_array($updated['procedimentos_historico'] ?? null)
                ? $updated['procedimentos_historico']
                : [],
        ]);
    }

    public function updateStatus(Request $request, int $order): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string'],
            'observacao' => ['nullable', 'string'],
            'diagnostico_tecnico' => ['nullable', 'string'],
            'solucao_aplicada' => ['nullable', 'string'],
            'comunicar_cliente' => ['nullable', 'boolean'],
        ], [], [
            'status' => 'status',
            'observacao' => 'observação',
            'diagnostico_tecnico' => 'diagnóstico',
            'solucao_aplicada' => 'solução aplicada',
            'comunicar_cliente' => 'notificar cliente',
        ]);

        try {
            $updatedOrder = $this->orderService->updateStatus(
                $order,
                $validated['status'] ?? null,
                $validated['observacao'] ?? null,
                $validated['diagnostico_tecnico'] ?? null,
                $validated['solucao_aplicada'] ?? null,
                filter_var($validated['comunicar_cliente'] ?? false, FILTER_VALIDATE_BOOL)
            );
        } catch (ApiAuthenticationException $exception) {
            if ($request->wantsJson()) {
                return response()->json(['error' => $exception->getMessage()], 401);
            }

            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            if ($request->wantsJson()) {
                return response()->json(['error' => $exception->getMessage()], 422);
            }

            return back()
                ->withInput($request->all())
                ->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            if ($request->wantsJson()) {
                return response()->json(['error' => 'Não foi possível atualizar o status da OS agora. Tente novamente.'], 500);
            }

            return back()
                ->withInput($request->all())
                ->with('error', 'Nao foi possivel atualizar o status da OS agora. Tente novamente.');
        }

        $statusNome = $updatedOrder['status_nome'] ?? 'o novo estado';
        $message = ($validated['status'] ?? null) !== null
            ? 'Status da OS atualizado para ' . $statusNome . '.'
            : 'Informações da OS salvas com sucesso.';

        if ($request->wantsJson()) {
            return response()->json([
                'success'     => true,
                'message'     => $message,
                'status_nome' => $statusNome,
                'status_cor'  => (string) ($updatedOrder['status_cor'] ?? '#64748b'),
            ]);
        }

        return redirect()
            ->route('orders.show', $order)
            ->with('success', $message);
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

    /**
     * Resposta JSON de erro padronizada (mesmo formato dos demais controllers
     * do desktop, ex.: EquipmentController). Consumida pelos endpoints AJAX
     * de busca (Select2) da Nova OS.
     */
    private function jsonFailure(string $message, int $status, ?array $errors = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }
}
