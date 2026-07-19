<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\ClientService;
use App\Services\EquipmentService;
use App\Services\OrderService;
use App\Support\DesktopSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;
use Throwable;

class EquipmentController extends DesktopController
{
    public function __construct(
        private readonly EquipmentService $equipmentService,
        private readonly OrderService $orderService,
        private readonly ClientService $clientService
    ) {
    }

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'client_id' => (int) $request->query('client_id', 0),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 15),
        ];

        $result = $this->equipmentService->paginate(array_filter($filters, static fn ($value) => $value !== '' && $value !== 0));

        return view('equipments.index', [
            'pageTitle' => 'Aparelhos / Equipamentos',
            'equipments' => array_map(
                fn (array $equipment): array => $this->decorateEquipmentPhotoAccess($equipment),
                $result['items']
            ),
            'pagination' => $result['pagination'],
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        try {
            $form = $this->equipmentService->formData();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('equipments.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('equipments.index')->with('error', $exception->getMessage());
        }

        $clientId = (int) $request->query('cliente_id', 0);
        $clientLabel = trim((string) $request->query('cliente_busca_label', ''));
        $embedded = $request->boolean('embedded');
        $equipment = $this->equipmentFormDefaults();

        if ($clientId > 0) {
            $equipment['cliente_id'] = $clientId;
            $equipment['cliente_busca_label'] = $clientLabel !== '' ? $clientLabel : ('Cliente #' . $clientId);
        }

        return $this->renderEquipmentFormView(
            'Novo equipamento',
            $form,
            $equipment,
            route('equipments.store'),
            'Criar equipamento',
            route('equipments.index'),
            false,
            $embedded
        );
    }

    public function edit(int $equipment): View|RedirectResponse
    {
        try {
            $form = $this->equipmentService->formData();
            $equipmentData = $this->equipmentService->find($equipment);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('equipments.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            if ($exception->statusCode() === 404) {
                abort(404);
            }

            return redirect()->route('equipments.index')->with('error', $exception->getMessage());
        }

        if ($equipmentData === []) {
            abort(404);
        }

        return $this->renderEquipmentFormView(
            'Editar equipamento',
            $form,
            $this->equipmentFormFromApi($this->decorateEquipmentPhotoAccess($equipmentData)),
            route('equipments.update', $equipment),
            'Salvar alterações',
            DesktopSession::can('equipamentos', 'visualizar')
                ? route('equipments.show', $equipment)
                : route('equipments.index'),
            true
        );
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $payload = $this->validatedEquipmentPayload($request);

        /** @var array<int, UploadedFile> $photos */
        $photos = array_values(array_filter(
            (array) $request->file('fotos', []),
            static fn ($file): bool => $file instanceof UploadedFile
        ));

        try {
            $equipment = $this->equipmentService->create($payload, $photos);
        } catch (ApiAuthenticationException $exception) {
            if ($request->expectsJson()) {
                return $this->jsonFailure($exception->getMessage(), 401);
            }

            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            if ($request->expectsJson()) {
                return $this->jsonFailure($exception->getMessage(), 403);
            }

            return redirect()->route('equipments.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            if ($request->expectsJson()) {
                return $this->jsonFailure(
                    $exception->getMessage(),
                    $exception->statusCode() > 0 ? $exception->statusCode() : 422,
                    $exception->details()
                );
            }

            return back()
                ->withInput($request->except('fotos'))
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Equipamento cadastrado com sucesso.',
                'equipment' => $this->buildEquipmentSelectionPayload($equipment),
            ], 201);
        }

        return redirect()
            ->route('equipments.show', (int) ($equipment['id'] ?? 0))
            ->with('success', 'Equipamento cadastrado com sucesso.');
    }

    public function update(Request $request, int $equipment): RedirectResponse
    {
        $payload = $this->validatedEquipmentPayload($request, true);

        /** @var array<int, UploadedFile> $photos */
        $photos = array_values(array_filter(
            (array) $request->file('fotos', []),
            static fn ($file): bool => $file instanceof UploadedFile
        ));

        try {
            $equipmentData = $this->equipmentService->update($equipment, $payload, $photos);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('equipments.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput($request->except('fotos'))
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        }

        $equipmentId = (int) ($equipmentData['id'] ?? $equipment);

        return redirect()
            ->to(DesktopSession::can('equipamentos', 'visualizar')
                ? route('equipments.show', $equipmentId)
                : route('equipments.edit', $equipmentId))
            ->with('success', 'Equipamento atualizado com sucesso.');
    }

    public function help(): View
    {
        return view('equipments.help', [
            'pageTitle' => 'Ajuda do cadastro de equipamento',
        ]);
    }

    public function searchClients(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = (int) ($validated['page'] ?? 1);

        try {
            $result = $this->clientService->paginate([
                'search' => trim((string) ($validated['q'] ?? '')),
                'per_page' => 8,
                'page' => $page,
            ]);
        } catch (ApiAuthenticationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 401);
        } catch (ApiAuthorizationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 403);
        } catch (ApiRequestException $exception) {
            return $this->jsonFailure($exception->getMessage(), $exception->statusCode() > 0 ? $exception->statusCode() : 422, $exception->details());
        }

        $items = array_map(static function (array $client): array {
            return [
                'id' => (int) ($client['id'] ?? 0),
                'nome_razao' => (string) ($client['nome_razao'] ?? ''),
                'telefone1' => (string) ($client['telefone1'] ?? ''),
                'email' => (string) ($client['email'] ?? ''),
                'cpf_cnpj' => (string) ($client['cpf_cnpj'] ?? ''),
            ];
        }, $result['items']);

        return response()->json([
            'success' => true,
            'clients' => $items,
        ]);
    }

    public function quickStoreBrand(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'tipo_id' => ['required', 'integer', 'min:1'],
            'nome' => ['required', 'string', 'max:120'],
        ]);

        try {
            $brand = $this->equipmentService->createBrand($payload);
        } catch (ApiAuthenticationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 401);
        } catch (ApiAuthorizationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 403);
        } catch (ApiRequestException $exception) {
            return $this->jsonFailure($exception->getMessage(), $exception->statusCode() > 0 ? $exception->statusCode() : 422, $exception->details());
        }

        return response()->json([
            'success' => true,
            'brand' => $brand,
        ], 201);
    }

    public function quickStoreModel(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'tipo_id' => ['required', 'integer', 'min:1'],
            'marca_id' => ['required', 'integer', 'min:1'],
            'nome' => ['required', 'string', 'max:120'],
        ]);

        try {
            $model = $this->equipmentService->createModel($payload);
        } catch (ApiAuthenticationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 401);
        } catch (ApiAuthorizationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 403);
        } catch (ApiRequestException $exception) {
            return $this->jsonFailure($exception->getMessage(), $exception->statusCode() > 0 ? $exception->statusCode() : 422, $exception->details());
        }

        return response()->json([
            'success' => true,
            'model' => $model,
        ], 201);
    }

    public function suggestModels(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'nome' => ['required', 'string', 'max:160'],
            'marca_nome' => ['nullable', 'string', 'max:120'],
            'tipo_nome' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $suggestions = $this->equipmentService->suggestModels($payload);
        } catch (ApiAuthenticationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 401);
        } catch (ApiAuthorizationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 403);
        } catch (ApiRequestException $exception) {
            return $this->jsonFailure($exception->getMessage(), $exception->statusCode() > 0 ? $exception->statusCode() : 422, $exception->details());
        }

        return response()->json([
            'success' => true,
            'suggestions' => $suggestions,
        ]);
    }

    public function localCollectorSnapshot(): JsonResponse
    {
        try {
            $collector = $this->equipmentService->readLocalCollectorSnapshot();
        } catch (ApiAuthenticationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 401);
        } catch (ApiAuthorizationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 403);
        } catch (ApiRequestException $exception) {
            return $this->jsonFailure($exception->getMessage(), $exception->statusCode() > 0 ? $exception->statusCode() : 422, $exception->details());
        }

        return response()->json([
            'success' => true,
            'collector' => $collector,
        ]);
    }

    public function localCollectorCollect(): JsonResponse
    {
        try {
            $collector = $this->equipmentService->collectLocalCollectorSnapshot();
        } catch (ApiAuthenticationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 401);
        } catch (ApiAuthorizationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 403);
        } catch (ApiRequestException $exception) {
            return $this->jsonFailure($exception->getMessage(), $exception->statusCode() > 0 ? $exception->statusCode() : 422, $exception->details());
        }

        return response()->json([
            'success' => true,
            'collector' => $collector,
        ]);
    }

    public function createCollectorPairing(): JsonResponse
    {
        try {
            $pairing = $this->equipmentService->createCollectorPairing();
        } catch (ApiAuthenticationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 401);
        } catch (ApiAuthorizationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 403);
        } catch (ApiRequestException $exception) {
            return $this->jsonFailure($exception->getMessage(), $exception->statusCode() > 0 ? $exception->statusCode() : 422, $exception->details());
        }

        return response()->json([
            'success' => true,
            'pairing' => $pairing,
        ], 201);
    }

    public function showCollectorPairing(string $code): JsonResponse
    {
        try {
            $pairing = $this->equipmentService->getCollectorPairing($code);
        } catch (ApiAuthenticationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 401);
        } catch (ApiAuthorizationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 403);
        } catch (ApiRequestException $exception) {
            return $this->jsonFailure($exception->getMessage(), $exception->statusCode() > 0 ? $exception->statusCode() : 422, $exception->details());
        }

        return response()->json([
            'success' => true,
            'pairing' => $pairing,
        ]);
    }

    public function downloadWindowsCollectorPackage(string $code)
    {
        try {
            $download = $this->equipmentService->downloadWindowsCollectorPackage($code);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('equipments.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            abort($exception->statusCode() > 0 ? $exception->statusCode() : 422, $exception->getMessage());
        }

        return response($download['body'], $download['status'], $download['headers']);
    }

    public function photo(int $equipment, int $photo)
    {
        try {
            $download = $this->equipmentService->downloadPhoto($equipment, $photo);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('equipments.show', $equipment)->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            abort($exception->statusCode() > 0 ? $exception->statusCode() : 404, $exception->getMessage());
        }

        return response($download['body'], $download['status'], $download['headers']);
    }

    public function show(int $equipment): View
    {
        $equipmentData = $this->equipmentService->find($equipment);
        abort_if($equipmentData === [], 404);

        $canViewOrders = DesktopSession::can('os', 'visualizar');

        $orders = $canViewOrders ? $this->orderService->paginate([
            'equipment_id' => $equipment,
            'per_page' => 5,
        ]) : [
            'items' => [],
            'pagination' => [
                'total' => 0,
            ],
        ];

        return view('equipments.show', [
            'pageTitle' => 'Detalhe do Equipamento',
            'equipment' => $this->decorateEquipmentPhotoAccess($equipmentData),
            'orders' => $orders['items'],
            'ordersPagination' => $orders['pagination'],
            'canViewOrders' => $canViewOrders,
            'newOrderUrl' => route('orders.create', [
                'cliente_id' => (int) ($equipmentData['cliente_id'] ?? 0),
                'equipamento_id' => $equipment,
            ]),
            'ordersIndexUrl' => route('orders.index', ['equipment_id' => $equipment]),
            'clientUrl' => (int) ($equipmentData['cliente_id'] ?? 0) > 0
                ? route('clients.show', (int) $equipmentData['cliente_id'])
                : null,
        ]);
    }

    /**
     * Revela a senha de acesso do equipamento mediante confirmação de
     * credenciais de administrador (step-up, padrão do "Cancelar baixa" —
     * skill sistema-erp-autenticacao-step-up). Sempre consumido via fetch
     * (JSON) pelo modal da tela de detalhe.
     */
    public function revealPassword(Request $request, int $equipment): JsonResponse
    {
        $validated = $request->validate([
            'admin_email' => ['required', 'string', 'email'],
            'admin_password' => ['required', 'string'],
        ], [], [
            'admin_email' => 'e-mail do administrador',
            'admin_password' => 'senha do administrador',
        ]);

        try {
            $senha = $this->equipmentService->revealPassword(
                $equipment,
                $validated['admin_email'],
                $validated['admin_password']
            );
        } catch (ApiAuthenticationException $exception) {
            return response()->json(['error' => $exception->getMessage()], 401);
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            // 422 tambem para credenciais de admin invalidas — nunca 401, que
            // deslogaria a sessao de quem clicou (ver skill de step-up).
            return response()->json(['error' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'Não foi possível revelar a senha agora. Tente novamente.'], 500);
        }

        return response()->json(['senha_acesso' => $senha]);
    }

    /**
     * @return array<string, mixed>
     */
    private function equipmentFormDefaults(): array
    {
        return [
            'id' => null,
            'cliente_id' => null,
            'cliente_busca_label' => '',
            'tipo_id' => null,
            'marca_id' => null,
            'modelo_id' => null,
            'numero_serie' => '',
            'senha_tipo' => 'desenho',
            'senha_acesso' => '',
            'senha_desenho' => '',
            'cor' => '',
            'cor_hex' => '#64748b',
            'cor_rgb' => '100, 116, 139',
            'estado_fisico' => '',
            'observacoes' => '',
            'desktop_modalidade' => 'montado',
            'gabinete_tipo' => '',
            'gabinete_identificacao_status' => 'a_confirmar',
            'gabinete_observacao' => '',
            'placa_mae' => '',
            'chipset' => '',
            'processador' => '',
            'memoria_ram' => '',
            'armazenamento' => '',
            'placa_video' => '',
            'fonte_alimentacao' => '',
            'foto_principal_index' => 0,
            'foto_principal_existente_id' => null,
            'collector_pairing_code' => '',
            'photos' => [],
            'primary_photo_id' => null,
            'primary_photo_url' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedEquipmentPayload(Request $request, bool $isUpdate = false): array
    {
        $validated = $request->validate([
            'cliente_id' => ['required', 'integer', 'min:1'],
            'cliente_busca_label' => ['nullable', 'string', 'max:160'],
            'tipo_id' => ['required', 'integer', 'min:1'],
            'marca_id' => ['nullable', 'integer', 'min:1'],
            'modelo_id' => ['nullable', 'integer', 'min:1'],
            'numero_serie_visual' => ['nullable', 'string', 'max:100'],
            'senha_tipo' => ['nullable', 'string', 'max:20'],
            'senha_acesso' => ['nullable', 'string', 'max:255'],
            'senha_desenho' => ['nullable', 'string', 'max:255'],
            'cor' => ['nullable', 'string', 'max:50'],
            'cor_hex' => ['nullable', 'string', 'max:7'],
            'cor_rgb' => ['nullable', 'string', 'max:30'],
            'estado_fisico' => ['nullable', 'string'],
            'observacoes' => ['nullable', 'string'],
            'desktop_modalidade' => ['nullable', 'string', 'max:20'],
            'gabinete_tipo' => ['nullable', 'string', 'max:120'],
            'gabinete_identificacao_status' => ['nullable', 'string', 'max:30'],
            'gabinete_observacao' => ['nullable', 'string'],
            'placa_mae' => ['nullable', 'string', 'max:255'],
            'chipset' => ['nullable', 'string', 'max:255'],
            'processador' => ['nullable', 'string', 'max:255'],
            'memoria_ram' => ['nullable', 'string', 'max:255'],
            'armazenamento' => ['nullable', 'string', 'max:255'],
            'placa_video' => ['nullable', 'string', 'max:255'],
            'fonte_alimentacao' => ['nullable', 'string', 'max:255'],
            'collector_pairing_code' => ['nullable', 'string', 'max:32'],
            'foto_principal_index' => ['nullable', 'integer', 'min:0', 'max:3'],
            'foto_principal_existente_id' => ['nullable', 'integer', 'min:1'],
            'existing_photo_sync' => ['nullable', 'boolean'],
            'existing_photo_ids' => ['nullable', 'array'],
            'existing_photo_ids.*' => ['integer', 'min:1'],
            'fotos' => $isUpdate ? ['nullable', 'array', 'max:4'] : ['required', 'array', 'min:1', 'max:4'],
            'fotos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [], [
            'cliente_id' => 'cliente',
            'tipo_id' => 'tipo',
            'marca_id' => 'marca',
            'modelo_id' => 'modelo',
            'numero_serie_visual' => 'nº série ou IMEI',
            'senha_tipo' => 'modo de senha',
            'senha_acesso' => 'senha em texto',
            'senha_desenho' => 'senha por desenho',
            'cor' => 'cor',
            'estado_fisico' => 'estado físico',
            'observacoes' => 'observações',
            'desktop_modalidade' => 'modalidade',
            'gabinete_tipo' => 'tipo do gabinete',
            'gabinete_identificacao_status' => 'status de identificação do gabinete',
            'gabinete_observacao' => 'observação do gabinete',
            'placa_mae' => 'placa mãe',
            'chipset' => 'chipset',
            'processador' => 'processador',
            'memoria_ram' => 'memória RAM',
            'armazenamento' => 'armazenamento',
            'placa_video' => 'placa de vídeo',
            'fonte_alimentacao' => 'fonte de alimentação',
            'collector_pairing_code' => 'código do coletor',
            'foto_principal_index' => 'foto principal',
            'fotos' => 'fotos do equipamento',
        ]);

        $payload = [];

        foreach ([
            'cliente_id',
            'tipo_id',
            'marca_id',
            'modelo_id',
            'senha_tipo',
            'senha_acesso',
            'senha_desenho',
            'cor',
            'cor_hex',
            'cor_rgb',
            'estado_fisico',
            'observacoes',
            'desktop_modalidade',
            'gabinete_tipo',
            'gabinete_identificacao_status',
            'gabinete_observacao',
            'placa_mae',
            'chipset',
            'processador',
            'memoria_ram',
            'armazenamento',
            'placa_video',
            'fonte_alimentacao',
            'collector_pairing_code',
            'foto_principal_index',
            'foto_principal_existente_id',
            'existing_photo_sync',
            'existing_photo_ids',
        ] as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }

            $payload[$field] = $this->normalizeValue($validated[$field]);
        }

        $payload['numero_serie'] = $this->normalizeValue($validated['numero_serie_visual'] ?? null);
        $payload['status_operacional'] = 'ativo';
        $payload['status'] = 'ativo';

        return $payload;
    }

    /**
     * @param array<string, mixed> $equipment
     * @return array<string, mixed>
     */
    private function equipmentFormFromApi(array $equipment): array
    {
        $client = is_array($equipment['client'] ?? null) ? $equipment['client'] : [];
        $passwordFields = $this->extractPasswordFields((string) ($equipment['senha_acesso'] ?? ''));

        return array_merge($this->equipmentFormDefaults(), [
            'id' => (int) ($equipment['id'] ?? 0) > 0 ? (int) $equipment['id'] : null,
            'cliente_id' => (int) ($equipment['cliente_id'] ?? 0) > 0 ? (int) $equipment['cliente_id'] : null,
            'cliente_busca_label' => $this->buildClientSearchLabel($client),
            'tipo_id' => (int) ($equipment['tipo_id'] ?? 0) > 0 ? (int) $equipment['tipo_id'] : null,
            'marca_id' => (int) ($equipment['marca_id'] ?? 0) > 0 ? (int) $equipment['marca_id'] : null,
            'modelo_id' => (int) ($equipment['modelo_id'] ?? 0) > 0 ? (int) $equipment['modelo_id'] : null,
            'numero_serie' => trim((string) (($equipment['numero_serie'] ?? '') !== '' ? $equipment['numero_serie'] : ($equipment['imei'] ?? ''))),
            'senha_tipo' => $passwordFields['senha_tipo'],
            'senha_acesso' => $passwordFields['senha_acesso'],
            'senha_desenho' => $passwordFields['senha_desenho'],
            'cor' => (string) ($equipment['cor'] ?? ''),
            'cor_hex' => (string) (($equipment['cor_hex'] ?? '') !== '' ? $equipment['cor_hex'] : '#64748b'),
            'cor_rgb' => (string) (($equipment['cor_rgb'] ?? '') !== '' ? $equipment['cor_rgb'] : '100, 116, 139'),
            'estado_fisico' => (string) ($equipment['estado_fisico'] ?? ''),
            'observacoes' => (string) ($equipment['observacoes'] ?? ''),
            'desktop_modalidade' => (string) (($equipment['desktop_modalidade'] ?? '') !== '' ? $equipment['desktop_modalidade'] : 'montado'),
            'gabinete_tipo' => (string) ($equipment['gabinete_tipo'] ?? ''),
            'gabinete_identificacao_status' => (string) (($equipment['gabinete_identificacao_status'] ?? '') !== '' ? $equipment['gabinete_identificacao_status'] : 'a_confirmar'),
            'gabinete_observacao' => (string) ($equipment['gabinete_observacao'] ?? ''),
            'placa_mae' => (string) ($equipment['placa_mae'] ?? ''),
            'chipset' => (string) ($equipment['chipset'] ?? ''),
            'processador' => (string) ($equipment['processador'] ?? ''),
            'memoria_ram' => (string) ($equipment['memoria_ram'] ?? ''),
            'armazenamento' => (string) ($equipment['armazenamento'] ?? ''),
            'placa_video' => (string) ($equipment['placa_video'] ?? ''),
            'fonte_alimentacao' => (string) ($equipment['fonte_alimentacao'] ?? ''),
            'photos' => is_array($equipment['photos'] ?? null) ? $equipment['photos'] : [],
            'primary_photo_id' => (int) ($equipment['primary_photo_id'] ?? 0) > 0 ? (int) $equipment['primary_photo_id'] : null,
            'primary_photo_url' => (string) ($equipment['primary_photo_url'] ?? ''),
        ]);
    }

    /**
     * @param array<string, mixed> $equipment
     * @return array<string, mixed>
     */
    private function buildEquipmentSelectionPayload(array $equipment): array
    {
        $decoratedEquipment = $this->decorateEquipmentPhotoAccess($equipment);
        $equipmentId = (int) ($decoratedEquipment['id'] ?? 0);
        $client = is_array($decoratedEquipment['client'] ?? null) ? $decoratedEquipment['client'] : [];

        $clientId = (int) ($decoratedEquipment['cliente_id'] ?? 0);
        if ($clientId <= 0) {
            $clientId = (int) ($decoratedEquipment['client_id'] ?? 0);
        }
        if ($clientId <= 0) {
            $clientId = (int) ($client['id'] ?? 0);
        }

        $clientName = trim((string) (
            $client['nome_razao']
            ?? $decoratedEquipment['cliente_nome']
            ?? $decoratedEquipment['client_name']
            ?? ''
        ));
        if ($clientName === '') {
            $clientName = $this->buildClientSearchLabel($client);
        }
        if ($clientName === '' && $clientId > 0) {
            $clientName = 'Cliente #' . $clientId;
        }

        $brandName = trim((string) (
            $decoratedEquipment['marca_nome']
            ?? $decoratedEquipment['brand_name']
            ?? ''
        ));
        $modelName = trim((string) (
            $decoratedEquipment['modelo_nome']
            ?? $decoratedEquipment['model_name']
            ?? ''
        ));
        $summary = trim((string) (
            $decoratedEquipment['equipamento_resumo_tecnico']
            ?? $decoratedEquipment['equipamento_resumo_curto']
            ?? $decoratedEquipment['summary']
            ?? ''
        ));
        $serial = trim((string) (
            $decoratedEquipment['numero_serie']
            ?? $decoratedEquipment['serial']
            ?? $decoratedEquipment['imei']
            ?? ''
        ));
        $photoUrl = trim((string) (
            $decoratedEquipment['primary_photo_url']
            ?? $decoratedEquipment['photo_url']
            ?? ''
        ));

        $label = $summary !== ''
            ? $summary
            : implode(' / ', array_values(array_filter([$brandName, $modelName])));

        if ($label === '') {
            $label = $serial !== ''
                ? $serial
                : ($equipmentId > 0 ? 'Equipamento #' . $equipmentId : '');
        }

        return [
            'id' => $equipmentId,
            'label' => $label,
            'text' => $label,
            'summary' => $summary,
            'brandName' => $brandName,
            'brand_name' => $brandName,
            'modelName' => $modelName,
            'model_name' => $modelName,
            'serial' => $serial,
            'photoUrl' => $photoUrl,
            'photo_url' => $photoUrl,
            'clientId' => $clientId,
            'client_id' => $clientId,
            'clientName' => $clientName,
            'client_name' => $clientName,
        ];
    }

    /**
     * @return array{senha_tipo:string,senha_acesso:string,senha_desenho:string}
     */
    private function extractPasswordFields(string $storedPassword): array
    {
        $normalized = trim($storedPassword);

        if ($normalized !== '' && str_starts_with($normalized, 'desenho_')) {
            return [
                'senha_tipo' => 'desenho',
                'senha_acesso' => '',
                'senha_desenho' => substr($normalized, strlen('desenho_')),
            ];
        }

        return [
            'senha_tipo' => $normalized !== '' ? 'texto' : 'desenho',
            'senha_acesso' => $normalized,
            'senha_desenho' => '',
        ];
    }

    /**
     * @param array<string, mixed> $client
     */
    private function buildClientSearchLabel(array $client): string
    {
        return implode(' - ', array_values(array_filter([
            trim((string) ($client['nome_razao'] ?? '')),
            trim((string) ($client['cpf_cnpj'] ?? '')),
            trim((string) ($client['telefone1'] ?? '')),
            trim((string) ($client['telefone2'] ?? '')),
            trim((string) ($client['email'] ?? '')),
        ])));
    }

    /**
     * @param array<string, mixed> $formData
     * @param array<string, mixed> $equipment
     */
    private function renderEquipmentFormView(
        string $pageTitle,
        array $formData,
        array $equipment,
        string $formAction,
        string $submitLabel,
        string $cancelUrl,
        bool $isEdit = false,
        bool $embedded = false
    ): View {
        return view('equipments.create', [
            'pageTitle' => $pageTitle,
            'formData' => $formData,
            'equipment' => $equipment,
            'formAction' => $formAction,
            'submitLabel' => $submitLabel,
            'cancelUrl' => $cancelUrl,
            'isEdit' => $isEdit,
            'embedded' => $embedded,
        ]);
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param array<string, mixed> $equipment
     * @return array<string, mixed>
     */
    private function decorateEquipmentPhotoAccess(array $equipment): array
    {
        $equipmentId = (int) ($equipment['id'] ?? 0);
        $primaryPhotoId = (int) ($equipment['primary_photo_id'] ?? 0);
        $photos = is_array($equipment['photos'] ?? null) ? $equipment['photos'] : [];

        if ($equipmentId <= 0) {
            return $equipment;
        }

        $equipment['primary_photo_url'] = $primaryPhotoId > 0
            ? route('equipments.photos.show', [
                'equipment' => $equipmentId,
                'photo' => $primaryPhotoId,
            ])
            : null;

        $equipment['photos'] = array_map(function (mixed $photo) use ($equipmentId): array {
            $photoData = is_array($photo) ? $photo : [];
            $photoId = (int) ($photoData['id'] ?? 0);

            if ($photoId > 0) {
                $photoData['url'] = route('equipments.photos.show', [
                    'equipment' => $equipmentId,
                    'photo' => $photoId,
                ]);
            }

            return $photoData;
        }, $photos);

        return $equipment;
    }

    /**
     * @return array<string, string>
     */
    private function formatApiErrors(ApiRequestException $exception): array
    {
        $details = $exception->details() ?? [];

        if (! is_array($details) || $details === []) {
            return ['form' => $exception->getMessage()];
        }

        $formatted = [];

        foreach ($details as $field => $messages) {
            if (is_array($messages)) {
                $formatted[$field] = (string) ($messages[0] ?? 'Valor inválido.');
                continue;
            }

            $formatted[$field] = (string) $messages;
        }

        return $formatted;
    }

    /**
     * @param array<string, mixed>|null $errors
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
