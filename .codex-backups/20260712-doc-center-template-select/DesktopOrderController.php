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

        $statuses = $this->resolveStatusCatalog();
        $filters = $this->syncStatusMacroFilters($filters, $statuses);
        $result = $this->orderService->paginate(array_filter($filters, static fn ($value) => $value !== '' && $value !== 0));

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
     * @param array<string, mixed> $filters
     * @param array<int, array<string, mixed>> $statuses
     * @return array<string, mixed>
     */
    private function syncStatusMacroFilters(array $filters, array $statuses): array
    {
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status === '' || $statuses === []) {
            return $filters;
        }

        foreach ($statuses as $statusOption) {
            if (trim((string) ($statusOption['codigo'] ?? '')) !== $status) {
                continue;
            }

            $macroGroup = trim((string) ($statusOption['grupo_macro'] ?? ''));
            if ($macroGroup !== '') {
                $filters['grupo_macro'] = $macroGroup;
            }

            return $filters;
        }

        return $filters;
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
            // status e dado de referencia, nao muda a cada request. A listagem de OS
            // nao deve depender da permissao administrativa de "conhecimento": usa um
            // endpoint de catalogo protegido por os:visualizar.
            return Cache::remember(
                'desktop:order_filters:status_catalog',
                60,
                fn (): array => $this->statusFlowService->statusCatalog()
            );
        } catch (ApiAuthenticationException|ApiAuthorizationException|ApiRequestException) {
            // Sem o catalogo (ex.: API indisponivel), os filtros de status e macrofase
            // caem para campo de texto livre em vez de quebrar a tela.
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
        $entryChecklistModel = $this->resolveEntryChecklistModelForEquipment($selectedEquipment);

        return view('orders.create', [
            'pageTitle' => 'Nova OS',
            'technicians' => $technicians,
            'selectedClientId' => $selectedClientId,
            'selectedEquipmentId' => $selectedEquipmentId,
            'selectedTechnicianId' => $selectedTechnicianId,
            'selectedClient' => $selectedClient,
            'selectedEquipment' => $selectedEquipment,
            'selectedTechnician' => $selectedTechnician,
            'entryChecklistModel' => $entryChecklistModel,
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

    public function entryChecklistModel(int $tipoEquipamento): JsonResponse
    {
        try {
            $modelo = $this->orderService->entryChecklistModel($tipoEquipamento);
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

        return response()->json([
            'success' => true,
            'modelo' => $modelo,
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

    /**
     * @param array<string, mixed> $equipment
     * @return array<string, mixed>
     */
    private function resolveEntryChecklistModelForEquipment(array $equipment): array
    {
        $tipoEquipamentoId = (int) ($equipment['tipo_id'] ?? data_get($equipment, 'type.id', 0));
        if ($tipoEquipamentoId <= 0) {
            return [];
        }

        try {
            $modelo = $this->orderService->entryChecklistModel($tipoEquipamentoId);
        } catch (ApiAuthenticationException|ApiAuthorizationException|ApiRequestException) {
            return [];
        }

        return is_array($modelo) ? $modelo : [];
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function buildEntryChecklistPayload(array $validated): array
    {
        $checklist = $validated['checklist_entrada'] ?? null;
        if (! is_array($checklist)) {
            return [];
        }

        $responses = [];
        foreach ((array) ($checklist['respostas'] ?? []) as $response) {
            if (! is_array($response)) {
                continue;
            }

            $itemId = (int) ($response['checklist_item_id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }

            $responses[] = [
                'checklist_item_id' => $itemId,
                'status' => trim((string) ($response['status'] ?? 'nao_verificado')),
                'observacao' => trim((string) ($response['observacao'] ?? '')),
            ];
        }

        if ($responses === []) {
            return [];
        }

        return [
            'observacoes_estado' => trim((string) ($checklist['observacoes_estado'] ?? '')),
            'respostas' => $responses,
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'cliente_id' => ['required', 'integer', 'min:1'],
            'equipamento_id' => ['required', 'integer', 'min:1'],
            'relato_cliente' => ['required', 'string', 'min:5'],
            'prioridade' => ['nullable', 'string', 'in:baixa,normal,alta,urgente'],
            'enviar_pdf_cliente' => ['nullable', 'boolean'],
            'tecnico_id' => ['nullable', 'integer', 'min:1'],
            'data_previsao' => ['nullable', 'date'],
            'observacoes_internas' => ['nullable', 'string'],
            'checklist_entrada' => ['nullable', 'array'],
            'checklist_entrada.observacoes_estado' => ['nullable', 'string', 'max:2000'],
            'checklist_entrada.respostas' => ['nullable', 'array', 'max:100'],
            'checklist_entrada.respostas.*.checklist_item_id' => ['required', 'integer', 'min:1'],
            'checklist_entrada.respostas.*.status' => ['required', 'string', 'in:ok,discrepancia,nao_verificado'],
            'checklist_entrada.respostas.*.observacao' => ['nullable', 'string', 'max:1000'],
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
        $payload['enviar_pdf_cliente'] = $request->boolean('enviar_pdf_cliente');

        $entryChecklistPayload = $this->buildEntryChecklistPayload($validated);
        if ($entryChecklistPayload !== []) {
            $payload['checklist_entrada'] = $entryChecklistPayload;
        }

        $result = $this->orderService->create(
            $payload,
            $this->extractUploadedFiles($request, 'fotos')
        );
        $order = is_array($result['order'] ?? null) ? $result['order'] : [];
        $openingDocument = is_array($result['opening_document'] ?? null) ? $result['opening_document'] : [];
        $openingDelivery = is_array($result['opening_delivery'] ?? null) ? $result['opening_delivery'] : [];

        $successMessage = 'Nova OS criada com sucesso.';
        if ((bool) ($openingDocument['generated'] ?? false)) {
            $successMessage .= ' PDF de abertura gerado.';
        }

        $warnings = [];
        if (! (bool) ($openingDocument['generated'] ?? false) && trim((string) ($openingDocument['message'] ?? '')) !== '') {
            $warnings[] = (string) $openingDocument['message'];
        }

        if ((bool) ($openingDelivery['requested'] ?? false)) {
            if ((bool) ($openingDelivery['sent'] ?? false)) {
                $successMessage .= ' Documento enviado ao cliente.';
            } elseif (trim((string) ($openingDelivery['message'] ?? '')) !== '') {
                $warnings[] = (string) $openingDelivery['message'];
            }
        }

        $redirect = redirect()
            ->route('orders.show', $order['id'] ?? 0)
            ->with('success', $successMessage);

        if ($warnings !== []) {
            $redirect = $redirect->with('warning', implode(' ', array_unique($warnings)));
        }

        return $redirect;
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
        $entryChecklistModel = data_get($orderData, 'checklist_modelo_entrada', []);
        if (! is_array($entryChecklistModel) || $entryChecklistModel === []) {
            $entryChecklistModel = $this->resolveEntryChecklistModelForEquipment($selectedEquipment);
        }

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
            'entryChecklistModel' => $entryChecklistModel,
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
            'checklist_entrada' => ['nullable', 'array'],
            'checklist_entrada.observacoes_estado' => ['nullable', 'string', 'max:2000'],
            'checklist_entrada.respostas' => ['nullable', 'array', 'max:100'],
            'checklist_entrada.respostas.*.checklist_item_id' => ['required', 'integer', 'min:1'],
            'checklist_entrada.respostas.*.status' => ['required', 'string', 'in:ok,discrepancia,nao_verificado'],
            'checklist_entrada.respostas.*.observacao' => ['nullable', 'string', 'max:1000'],
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

        $entryChecklistPayload = $this->buildEntryChecklistPayload($validated);
        if ($entryChecklistPayload !== []) {
            $payload['checklist_entrada'] = $entryChecklistPayload;
        }

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
        $classificacaoBaixa = trim((string) $request->input('classificacao_baixa', 'baixa'));
        $isBaixa = ! in_array($classificacaoBaixa, ['adiantamento', 'sinal'], true);

        $validated = $request->validate([
            'classificacao_baixa' => ['nullable', 'string', 'in:baixa,adiantamento,sinal'],
            'encerrar_como' => [$isBaixa ? 'required' : 'nullable', 'string'],
            'equipamento_entregue' => ['nullable', 'boolean'],
            'data_entrega' => [
                ($isBaixa || $request->boolean('equipamento_entregue')) ? 'required' : 'nullable',
                'date',
            ],
            'observacao' => ['nullable', 'string'],
            'notificar_cliente' => ['nullable', 'boolean'],
            'agendar_retorno' => ['nullable', 'boolean'],
            'retorno_data' => ['nullable', 'date'],
            'recebimentos' => [$isBaixa ? 'nullable' : 'required', 'array', $isBaixa ? 'sometimes' : 'min:1'],
            'recebimentos.*.valor' => ['required', 'numeric', 'min:0.01'],
            'recebimentos.*.forma_pagamento' => ['required', 'string'],
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
            'recebimentos' => 'recebimentos',
            'recebimentos.*.valor' => 'valor do recebimento',
            'recebimentos.*.forma_pagamento' => 'forma de pagamento do recebimento',
        ]);

        $payload = array_filter([
            'classificacao_baixa' => $classificacaoBaixa,
            'encerrar_como' => $validated['encerrar_como'] ?? null,
            'data_entrega' => $validated['data_entrega'] ?? null,
            'equipamento_entregue' => $request->boolean('equipamento_entregue'),
            'observacao' => $validated['observacao'] ?? null,
            'notificar_cliente' => $request->boolean('notificar_cliente'),
            'agendar_retorno' => $isBaixa && $request->boolean('agendar_retorno'),
            'retorno_data' => $validated['retorno_data'] ?? null,
            'recebimentos' => $validated['recebimentos'] ?? [],
        ], static fn ($value): bool => $value !== null && $value !== '');

        $result = $this->orderService->close($order, $payload);

        $message = $isBaixa ? 'OS encerrada com sucesso.' : 'Valor registrado com sucesso.';
        if (($result['notificacao_enviada'] ?? null) === false) {
            $message .= ' O cliente não pôde ser notificado por WhatsApp agora.';
        }

        return redirect()
            ->route('orders.show', $order)
            ->with('success', $message);
    }

    public function closureCancel(Request $request, int $order): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'admin_email' => ['required', 'string', 'email'],
            'admin_password' => ['required', 'string'],
        ], [], [
            'admin_email' => 'e-mail do administrador',
            'admin_password' => 'senha do administrador',
        ]);

        try {
            $this->orderService->cancelClosure($order, $validated['admin_email'], $validated['admin_password']);
        } catch (ApiAuthenticationException $exception) {
            if ($request->wantsJson()) {
                return response()->json(['error' => $exception->getMessage()], 401);
            }

            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            if ($request->wantsJson()) {
                return response()->json(['error' => $exception->getMessage()], 422);
            }

            // Sem withInput() de proposito: nunca refletir a senha do
            // administrador de volta para a sessao/old-input.
            return redirect()
                ->route('orders.show', $order)
                ->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            if ($request->wantsJson()) {
                return response()->json(['error' => 'Não foi possível cancelar a baixa da OS agora. Tente novamente.'], 500);
            }

            return redirect()
                ->route('orders.show', $order)
                ->with('error', 'Não foi possível cancelar a baixa da OS agora. Tente novamente.');
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Baixa cancelada: o status foi revertido e os lançamentos financeiros criados na baixa foram excluídos.',
            ]);
        }

        return redirect()
            ->route('orders.show', $order)
            ->with('success', 'Baixa cancelada: o status foi revertido e os lançamentos financeiros criados na baixa foram excluídos.');
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

    public function documentFormat(int $order, int $document, string $format): Response
    {
        $file = $this->orderService->downloadDocumentFile($order, $document, $format);

        return response($file['body'], $file['status'])
            ->withHeaders($file['headers']);
    }

    public function documentsCenter(int $order): View|RedirectResponse
    {
        try {
            $context = $this->orderService->documentsCenter($order);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return redirect()->route('orders.show', $order)->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('orders.show', $order)->with('error', 'Não foi possível carregar a central documental desta OS agora.');
        }

        return view('orders.documents-center', [
            'pageTitle' => 'Documentos do cliente',
            'order' => is_array($context['order'] ?? null) ? $context['order'] : [],
            'catalog' => is_array($context['catalog'] ?? null) ? $context['catalog'] : [],
            'documents' => is_array($context['documents'] ?? null) ? $context['documents'] : [],
            'dispatchDefaults' => is_array($context['dispatch_defaults'] ?? null) ? $context['dispatch_defaults'] : [],
            'sendHistory' => is_array($context['send_history'] ?? null) ? $context['send_history'] : [],
            'shareLinks' => is_array($context['share_links'] ?? null) ? $context['share_links'] : [],
            'limits' => is_array($context['limits'] ?? null) ? $context['limits'] : [],
        ]);
    }

    public function documentsCenterGenerate(Request $request, int $order): RedirectResponse
    {
        $validated = $request->validate([
            'tipos' => ['required', 'array', 'min:1'],
            'tipos.*' => ['required', 'string', 'max:80'],
        ]);

        try {
            $result = $this->orderService->generateDocuments($order, array_values($validated['tipos'] ?? []));
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', 'Não foi possível gerar os documentos selecionados agora.');
        }

        $documents = is_array($result['documents'] ?? null) ? $result['documents'] : [];
        $successfulDocuments = array_values(array_filter(
            $documents,
            static fn (array $document): bool => (bool) ($document['ok'] ?? false)
        ));

        if ($documents !== [] && $successfulDocuments === []) {
            $messages = array_values(array_filter(array_map(
                static fn (array $document): string => trim((string) ($document['message'] ?? '')),
                $documents
            )));

            return redirect()
                ->route('orders.documents.center', $order)
                ->with('error', $messages[0] ?? 'Nenhum documento pôde ser gerado com os dados atuais da OS.');
        }

        $successMessage = count($successfulDocuments) === 1
            ? '1 documento gerado com sucesso.'
            : count($successfulDocuments) . ' documentos gerados com sucesso.';

        if ($documents !== [] && count($successfulDocuments) < count($documents)) {
            $successMessage .= ' Alguns itens permaneceram pendentes por falta de pré-requisitos.';
        }

        return redirect()
            ->route('orders.documents.center', $order)
            ->with('success', $successMessage);
    }

    public function documentsCenterDispatch(Request $request, int $order): RedirectResponse
    {
        $action = trim((string) $request->input('dispatch_action', ''));

        return match ($action) {
            'share' => $this->documentsCenterShare($request, $order),
            'send' => $this->documentsCenterSend($request, $order),
            default => redirect()
                ->route('orders.documents.center', $order)
                ->with('error', 'A ação documental solicitada é inválida ou não foi informada.'),
        };
    }

    public function documentsCenterSend(Request $request, int $order): RedirectResponse
    {
        $validated = validator(
            [
                'document_ids' => $this->extractDocumentIdsFromRequest($request),
                'channel' => $this->firstRequestValue($request, ['send_channel', 'channel']),
                'format' => $this->firstRequestValue($request, ['send_format', 'format']),
                'destino' => $this->firstRequestValue($request, ['send_destino', 'destino']),
                'message' => $this->firstRequestValue($request, ['send_message', 'message']),
                'confirmar_destino_alternativo' => $this->firstRequestBoolean($request, ['send_confirmar_destino_alternativo', 'confirmar_destino_alternativo']),
            ],
            [
                'document_ids' => ['required', 'array', 'min:1'],
                'document_ids.*' => ['required', 'integer', 'min:1'],
                'channel' => ['nullable', 'string', 'in:whatsapp,email'],
                'format' => ['nullable', 'string', 'in:a4,80mm'],
                'destino' => ['nullable', 'string', 'max:255'],
                'message' => ['nullable', 'string', 'max:4000'],
                'confirmar_destino_alternativo' => ['nullable', 'boolean'],
            ],
            [
                'document_ids.required' => 'Selecione ao menos um documento do acervo antes de enfileirar o envio.',
                'document_ids.array' => 'Selecione ao menos um documento válido do acervo antes de enfileirar o envio.',
                'document_ids.min' => 'Selecione ao menos um documento do acervo antes de enfileirar o envio.',
                'document_ids.*.integer' => 'Existe um documento inválido na seleção do envio.',
                'document_ids.*.min' => 'Existe um documento inválido na seleção do envio.',
            ]
        )->validate();

        try {
            $this->orderService->sendDocuments($order, $validated);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', 'Não foi possível enfileirar o envio documental agora.');
        }

        return redirect()
            ->route('orders.documents.center', $order)
            ->with('success', 'Envio documental enfileirado.');
    }

    public function documentsCenterShare(Request $request, int $order): RedirectResponse
    {
        $validated = validator(
            [
                'document_ids' => $this->extractDocumentIdsFromRequest($request),
                'format' => $this->firstRequestValue($request, ['share_format', 'format']),
                'expiracao' => $this->firstRequestValue($request, ['share_expiracao', 'expiracao']),
            ],
            [
                'document_ids' => ['required', 'array', 'min:1'],
                'document_ids.*' => ['required', 'integer', 'min:1'],
                'format' => ['nullable', 'string', 'in:a4,80mm'],
                'expiracao' => ['nullable', 'string', 'in:24h,7d,30d'],
            ],
            [
                'document_ids.required' => 'Selecione ao menos um documento do acervo antes de gerar o link.',
                'document_ids.array' => 'Selecione ao menos um documento válido do acervo antes de gerar o link.',
                'document_ids.min' => 'Selecione ao menos um documento do acervo antes de gerar o link.',
                'document_ids.*.integer' => 'Existe um documento inválido na seleção do link.',
                'document_ids.*.min' => 'Existe um documento inválido na seleção do link.',
            ]
        )->validate();

        try {
            $result = $this->orderService->createShareLink($order, $validated);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', 'Não foi possível gerar o link documental agora.');
        }

        $link = is_array($result['link'] ?? null) ? $result['link'] : [];
        $message = 'Link seguro criado com sucesso.';
        if (($link['url'] ?? '') !== '') {
            $message .= ' URL: ' . $link['url'];
        }

        return redirect()
            ->route('orders.documents.center', $order)
            ->with('success', $message)
            ->with('generated_document_link', [
                'url' => (string) ($link['url'] ?? ''),
                'format' => (string) ($link['format'] ?? ($validated['format'] ?? 'a4')),
                'expires_at' => (string) ($link['expires_at'] ?? ''),
            ]);
    }

    public function documentsCenterRevokeLink(int $order, int $link): RedirectResponse
    {
        try {
            $this->orderService->revokeShareLink($order, $link);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Não foi possível revogar o link agora.');
        }

        return redirect()
            ->route('orders.documents.center', $order)
            ->with('success', 'Link revogado com sucesso.');
    }

    public function documentsCenterArchive(int $order, int $document): RedirectResponse
    {
        return $this->toggleDocumentArchive($order, $document, true);
    }

    public function documentsCenterUnarchive(int $order, int $document): RedirectResponse
    {
        return $this->toggleDocumentArchive($order, $document, false);
    }

    public function documentsCenterDownload(Request $request, int $order): Response|RedirectResponse
    {
        $documentIds = $this->extractDocumentIdsFromRequest($request);
        $format = in_array((string) $request->query('format', 'a4'), ['a4', '80mm'], true)
            ? (string) $request->query('format', 'a4')
            : 'a4';

        if ($documentIds === []) {
            return redirect()->route('orders.documents.center', $order)->with('error', 'Selecione ao menos um documento para montar o ZIP.');
        }

        try {
            $file = $this->orderService->downloadDocumentsZip($order, $documentIds, $format);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return redirect()->route('orders.documents.center', $order)->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('orders.documents.center', $order)->with('error', 'Não foi possível montar o ZIP documental agora.');
        }

        return response($file['body'], $file['status'])
            ->withHeaders($file['headers']);
    }

    public function documentsCenterPrint(Request $request, int $order): View|RedirectResponse
    {
        $documentIds = $this->extractDocumentIdsFromRequest($request);
        $format = in_array((string) $request->query('format', 'a4'), ['a4', '80mm'], true)
            ? (string) $request->query('format', 'a4')
            : 'a4';

        if ($documentIds === []) {
            return redirect()->route('orders.documents.center', $order)->with('error', 'Selecione ao menos um documento para abrir a fila de impressão.');
        }

        try {
            $bundle = $this->orderService->printDocuments($order, $documentIds, $format);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return redirect()->route('orders.documents.center', $order)->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('orders.documents.center', $order)->with('error', 'Não foi possível abrir o bundle de impressão agora.');
        }

        $documents = collect($bundle['documents'] ?? [])
            ->map(function (array $document) use ($order, $format): array {
                $document['print_url'] = route('orders.documents.files.show', [
                    'order' => $order,
                    'document' => (int) ($document['id'] ?? 0),
                    'format' => $format,
                ]);

                return $document;
            })
            ->values()
            ->all();

        return view('orders.documents-print', [
            'pageTitle' => 'Impressão documental da OS',
            'order' => is_array($bundle['order'] ?? null) ? $bundle['order'] : ['id' => $order],
            'format' => $format,
            'documents' => $documents,
        ]);
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

    private function toggleDocumentArchive(int $order, int $document, bool $archive): RedirectResponse
    {
        try {
            $this->orderService->archiveDocument($order, $document, $archive);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $archive
                ? 'Não foi possível arquivar o documento agora.'
                : 'Não foi possível reativar o documento agora.');
        }

        return redirect()
            ->route('orders.documents.center', $order)
            ->with('success', $archive ? 'Documento arquivado com sucesso.' : 'Documento reativado com sucesso.');
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, int>
     */
    private function normalizeDocumentIds(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn ($value): int => (int) $value,
            $values
        ), static fn (int $value): bool => $value > 0));
    }

    /**
     * @return array<int, int>
     */
    private function extractDocumentIdsFromRequest(Request $request): array
    {
        $values = $request->input('document_ids', $request->query('document_ids', []));

        if (is_string($values)) {
            $values = preg_split('/[\s,]+/', $values, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (! is_array($values)) {
            $values = [$values];
        }

        return $this->normalizeDocumentIds($values);
    }

    private function firstRequestValue(Request $request, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! $request->exists($key)) {
                continue;
            }

            $value = trim((string) $request->input($key));

            return $value !== '' ? $value : null;
        }

        return null;
    }

    private function firstRequestBoolean(Request $request, array $keys): ?bool
    {
        foreach ($keys as $key) {
            if (! $request->exists($key)) {
                continue;
            }

            return $request->boolean($key);
        }

        return null;
    }
}
