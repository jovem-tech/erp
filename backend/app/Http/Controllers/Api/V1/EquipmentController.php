<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\RevealEquipmentPasswordRequest;
use App\Http\Requests\Api\V1\StoreEquipmentBrandRequest;
use App\Http\Requests\Api\V1\StoreEquipmentModelRequest;
use App\Http\Requests\Api\V1\StoreEquipmentRequest;
use App\Http\Requests\Api\V1\UpdateEquipmentRequest;
use App\Models\Equipment;
use App\Models\EquipmentBrand;
use App\Models\EquipmentCollectorPairing;
use App\Models\EquipmentModel;
use App\Models\EquipmentPhoto;
use App\Models\User;
use App\Services\EquipmentWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EquipmentController extends BaseApiController
{
    public function __construct(
        private readonly EquipmentWorkflowService $equipmentWorkflowService
    ) {
    }

    public function formData(Request $request): JsonResponse
    {
        $this->authorizeEquipmentFormAccess($request);

        return $this->success([
            'form' => $this->equipmentWorkflowService->formData(),
        ], request: $request);
    }

    public function suggestModels(Request $request): JsonResponse
    {
        $this->authorizeEquipmentFormAccess($request);

        $query = trim((string) $request->query('nome', $request->query('q', '')));
        $brandName = trim((string) $request->query('marca_nome', ''));
        $typeName = trim((string) $request->query('tipo_nome', ''));

        return $this->success([
            'suggestions' => $this->equipmentWorkflowService->suggestModels($query, $brandName, $typeName),
        ], request: $request);
    }

    public function storeBrand(StoreEquipmentBrandRequest $request): JsonResponse
    {
        $this->authorize('equipamentos:criar');

        $validated = $request->validated();
        $brand = $this->equipmentWorkflowService->createBrand(
            (string) $validated['nome'],
            (int) $validated['tipo_id']
        );

        return $this->success([
            'brand' => [
                'id' => (int) $brand->id,
                'tipo_id' => (int) $validated['tipo_id'],
                'nome' => (string) $brand->nome,
            ],
        ], Response::HTTP_CREATED, request: $request);
    }

    public function storeModel(StoreEquipmentModelRequest $request): JsonResponse
    {
        $this->authorize('equipamentos:criar');

        $validated = $request->validated();
        $model = $this->equipmentWorkflowService->createModel(
            (int) $validated['marca_id'],
            (string) $validated['nome'],
            (int) $validated['tipo_id']
        );

        return $this->success([
            'model' => [
                'id' => (int) $model->id,
                'tipo_id' => (int) $validated['tipo_id'],
                'marca_id' => (int) $model->marca_id,
                'nome' => (string) $model->nome,
            ],
        ], Response::HTTP_CREATED, request: $request);
    }

    public function createCollectorPairing(Request $request): JsonResponse
    {
        $this->authorizeEquipmentFormAccess($request);

        $pairing = $this->equipmentWorkflowService->createCollectorPairing($this->authenticatedUser($request));

        return $this->success([
            'pairing' => $this->mapCollectorPairing($pairing),
            // So' aqui, na criacao — o tecnico ja autenticado precisa do
            // token pra montar o comando/pacote do coletor na maquina do
            // cliente. Nao repetimos isto em showCollectorPairing (polling)
            // para nao reexpor sem necessidade. Token de uso unico deste
            // pareamento (nao um segredo global compartilhado) — ver
            // EquipmentWorkflowService::createCollectorPairing().
            'submission_token' => (string) ($pairing->submission_token ?? ''),
        ], Response::HTTP_CREATED, request: $request);
    }

    public function showCollectorPairing(Request $request, string $code): JsonResponse
    {
        $this->authorizeEquipmentFormAccess($request);

        $pairing = $this->equipmentWorkflowService->getCollectorPairing($code);
        if (! $pairing instanceof EquipmentCollectorPairing) {
            return $this->error(
                'Pareamento nao encontrado.',
                404,
                'COLLECTOR_PAIRING_NOT_FOUND',
                null,
                request: $request
            );
        }

        return $this->success([
            'pairing' => $this->mapCollectorPairing($pairing),
        ], request: $request);
    }

    /**
     * Zip com o coletor Windows ja personalizado (codigo, URL do ERP e
     * token embutidos) + atalho .bat — baixa e roda com dois cliques, sem
     * precisar digitar comando nenhum na maquina do cliente.
     */
    public function downloadWindowsCollectorPackage(Request $request, string $code): Response|JsonResponse
    {
        $this->authorizeEquipmentFormAccess($request);

        try {
            $package = $this->equipmentWorkflowService->buildWindowsCollectorDownloadPackage($code);
        } catch (Throwable $exception) {
            return $this->error(
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Nao foi possivel gerar o pacote do coletor.',
                422,
                'COLLECTOR_PACKAGE_FAILED',
                null,
                request: $request
            );
        }

        return response($package['content'], Response::HTTP_OK, [
            'Content-Type' => $package['mime'],
            'Content-Disposition' => 'attachment; filename="' . $package['filename'] . '"',
        ]);
    }

    public function localCollectorSnapshot(Request $request): JsonResponse
    {
        $this->authorizeEquipmentFormAccess($request);

        try {
            $collector = $this->equipmentWorkflowService->readLocalCollectorSnapshot();
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Nao foi possivel ler o snapshot local do coletor.',
                $this->resolveCollectorErrorStatus($exception),
                'COLLECTOR_LOCAL_SNAPSHOT_FAILED',
                null,
                request: $request
            );
        }

        return $this->success([
            'collector' => $collector,
        ], request: $request);
    }

    public function localCollectorCollect(Request $request): JsonResponse
    {
        $this->authorizeEquipmentFormAccess($request);

        try {
            $collector = $this->equipmentWorkflowService->collectLocalCollectorSnapshot();
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Nao foi possivel executar a coleta local do coletor.',
                $this->resolveCollectorErrorStatus($exception),
                'COLLECTOR_LOCAL_COLLECT_FAILED',
                null,
                request: $request
            );
        }

        return $this->success([
            'collector' => $collector,
        ], request: $request);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('equipamentos:visualizar');

        $search = trim((string) $request->query('search', $request->query('q', '')));
        $perPage = max(1, min(50, (int) $request->query('per_page', 15)));
        $clientId = (int) $request->query('client_id', 0);

        $query = Equipment::query()
            ->with([
                'client',
                'type',
                'brand',
                'model',
                'photos' => static function ($photoQuery): void {
                    $photoQuery->select(['id', 'equipamento_id', 'is_principal']);
                },
            ])
            ->withCount('orders');

        if ($clientId > 0) {
            $query->where('cliente_id', $clientId);
        }

        if ($search !== '') {
            $term = '%' . mb_strtolower($search) . '%';
            $query->where(static function ($builder) use ($term): void {
                $builder
                    ->whereRaw('LOWER(COALESCE(resumo_tecnico, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(numero_serie, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(imei, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(cor, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(observacoes, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(desktop_modalidade, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(status_operacional, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(status, \'\')) LIKE ?', [$term])
                    ->orWhereHas('type', static function ($typeQuery) use ($term): void {
                        $typeQuery->whereRaw('LOWER(COALESCE(nome, \'\')) LIKE ?', [$term]);
                    })
                    ->orWhereHas('brand', static function ($brandQuery) use ($term): void {
                        $brandQuery->whereRaw('LOWER(COALESCE(nome, \'\')) LIKE ?', [$term]);
                    })
                    ->orWhereHas('model', static function ($modelQuery) use ($term): void {
                        $modelQuery->whereRaw('LOWER(COALESCE(nome, \'\')) LIKE ?', [$term]);
                    })
                    ->orWhereHas('client', static function ($clientQuery) use ($term): void {
                        $clientQuery
                            ->whereRaw('LOWER(COALESCE(nome_razao, \'\')) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(COALESCE(cpf_cnpj, \'\')) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(COALESCE(telefone1, \'\')) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(COALESCE(telefone2, \'\')) LIKE ?', [$term]);
                    });
            });
        }

        $paginator = $query
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Equipment $equipment): array => $this->mapEquipmentSummary($equipment))
        );

        return $this->success(
            ['equipments' => $paginator->items()],
            meta: $this->paginationMeta($paginator),
            request: $request
        );
    }

    public function store(StoreEquipmentRequest $request): JsonResponse
    {
        $this->authorize('equipamentos:criar');

        $validated = $request->validated();
        $files = $request->file('fotos', []);

        try {
            $equipment = $this->equipmentWorkflowService->createEquipment(
                $validated,
                is_array($files) ? $files : []
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Nao foi possivel cadastrar o equipamento.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'EQUIPMENT_STORE_FAILED',
                null,
                request: $request
            );
        }

        return $this->success([
            'equipment' => $this->mapEquipmentDetail($equipment),
        ], Response::HTTP_CREATED, request: $request);
    }

    public function update(UpdateEquipmentRequest $request, int $equipment): JsonResponse
    {
        $this->authorize('equipamentos:editar');

        $validated = $request->validated();
        $files = $request->file('fotos', []);

        try {
            $equipmentModel = $this->equipmentWorkflowService->updateEquipment(
                $equipment,
                $validated,
                is_array($files) ? $files : []
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Nao foi possivel atualizar o equipamento.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'EQUIPMENT_UPDATE_FAILED',
                null,
                request: $request
            );
        }

        return $this->success([
            'equipment' => $this->mapEquipmentDetail($equipmentModel),
        ], request: $request);
    }

    public function show(Request $request, int $equipment): JsonResponse
    {
        $this->authorizeEquipmentViewAccess($request);

        $equipmentModel = Equipment::query()
            ->with([
                'client',
                'type',
                'brand',
                'model',
                'photos' => static function ($photoQuery): void {
                    $photoQuery->select(['id', 'equipamento_id', 'is_principal']);
                },
            ])
            ->withCount('orders')
            ->find($equipment);

        if (! $equipmentModel instanceof Equipment) {
            return $this->error(
                'Equipamento não encontrado.',
                404,
                'EQUIPMENT_NOT_FOUND',
                null,
                request: $request
            );
        }

        return $this->success(
            ['equipment' => $this->mapEquipmentDetail($equipmentModel)],
            request: $request
        );
    }

    /**
     * Revela a senha de acesso do equipamento mediante step-up de
     * administrador (padrao sistema-erp-autenticacao-step-up, mesmo desenho do
     * "Cancelar baixa"): o botao fica acessivel a quem visualiza equipamentos,
     * mas a senha so e' retornada com credenciais validas de um admin —
     * a API nunca mais expoe senha_acesso em nenhum outro payload.
     */
    public function revealPassword(RevealEquipmentPasswordRequest $request, int $equipment): JsonResponse
    {
        // Mesma audiencia da visualizacao do equipamento — o gate real e' o
        // step-up de admin abaixo, nao a permissao de quem clicou.
        $this->authorizeEquipmentViewAccess($request);

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $equipmentModel = Equipment::query()->find($equipment);
        if (! $equipmentModel instanceof Equipment) {
            return $this->error('Equipamento não encontrado.', 404, 'EQUIPMENT_NOT_FOUND', null, request: $request);
        }

        $validated = $request->validated();
        $adminEmail = mb_strtolower(trim((string) $validated['admin_email']));
        $adminPassword = (string) $validated['admin_password'];

        $throttleKey = 'equipment-password-reveal-admin-auth:' . $adminEmail . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return $this->error(
                'Muitas tentativas de verificação de administrador. Aguarde um pouco e tente novamente.',
                429,
                'EQUIPMENT_PASSWORD_REVEAL_RATE_LIMITED',
                ['retry_after' => RateLimiter::availableIn($throttleKey)],
                request: $request
            );
        }

        $admin = User::query()->where('email', $adminEmail)->first();

        if (
            ! $admin instanceof User
            || ! (bool) $admin->ativo
            || mb_strtolower(trim((string) ($admin->perfil ?? ''))) !== 'admin'
            || ! Hash::check($adminPassword, (string) $admin->senha)
        ) {
            RateLimiter::hit($throttleKey, 60);

            logger()->warning('[API V1][EQUIPMENTS] Credenciais de administrador inválidas ao revelar senha de equipamento', [
                'equipment_id' => $equipment,
                'user_id' => $user->id,
                'admin_email' => $adminEmail,
                'ip' => $request->ip(),
            ]);

            // 422, nao 401: o desktop trata QUALQUER 401 como "sessao do usuario
            // atual expirou" e forca logout (ApiClient::parseResponse). Esta e'
            // uma verificacao de credenciais de um usuario DIFERENTE (admin).
            return $this->error(
                'Credenciais de administrador inválidas.',
                422,
                'EQUIPMENT_PASSWORD_REVEAL_ADMIN_AUTH_INVALID',
                null,
                request: $request
            );
        }

        RateLimiter::clear($throttleKey);

        logger()->info('[API V1][EQUIPMENTS] Senha de equipamento revelada com autorização de administrador', [
            'equipment_id' => $equipment,
            'user_id' => (int) $user->id,
            'admin_id' => (int) $admin->id,
            'ip' => $request->ip(),
        ]);

        return $this->success(
            ['senha_acesso' => (string) ($equipmentModel->senha_acesso ?? '')],
            request: $request
        );
    }

    public function photo(Request $request, int $equipment, int $photo): Response|JsonResponse
    {
        $this->authorizeEquipmentViewAccess($request);

        $resolution = $this->equipmentWorkflowService->resolvePhotoAccess($equipment, $photo);

        if (($resolution['result'] ?? '') === 'not_found') {
            return $this->error(
                'Foto do equipamento nao encontrada.',
                404,
                'EQUIPMENT_PHOTO_NOT_FOUND',
                null,
                request: $request
            );
        }

        if (($resolution['result'] ?? '') === 'missing_file') {
            return $this->error(
                'Arquivo da foto nao encontrado no storage privado.',
                404,
                'EQUIPMENT_PHOTO_FILE_MISSING',
                null,
                request: $request
            );
        }

        /** @var array<string, string> $file */
        $file = $resolution['file'];

        return response()->file($file['absolute_path'], [
            'Content-Type' => $file['mime_type'],
            'Content-Disposition' => 'inline; filename="' . $file['filename'] . '"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapEquipmentSummary(Equipment $equipment): array
    {
        $primaryPhoto = $this->resolvePrimaryPhoto($equipment);

        return [
            'id' => (int) $equipment->id,
            'cliente_id' => (int) ($equipment->cliente_id ?? 0),
            'cliente_nome' => (string) ($equipment->client?->nome_razao ?? ''),
            'tipo_id' => (int) ($equipment->tipo_id ?? 0),
            'tipo_nome' => (string) ($equipment->type?->nome ?? ''),
            'marca_nome' => (string) ($equipment->brand?->nome ?? ''),
            'modelo_nome' => (string) ($equipment->model?->nome ?? ''),
            'resumo_tecnico' => (string) ($equipment->resumo_tecnico ?? ''),
            'numero_serie' => (string) ($equipment->numero_serie ?? ''),
            'imei' => (string) ($equipment->imei ?? ''),
            'desktop_modalidade' => (string) ($equipment->desktop_modalidade ?? ''),
            'status_operacional' => (string) ($equipment->status_operacional ?? ''),
            'orders_count' => (int) ($equipment->orders_count ?? 0),
            'primary_photo_id' => $primaryPhoto?->id !== null ? (int) $primaryPhoto->id : null,
            'primary_photo_url' => $primaryPhoto instanceof EquipmentPhoto
                ? $this->buildPhotoUrl((int) $equipment->id, (int) $primaryPhoto->id)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapEquipmentDetail(Equipment $equipment): array
    {
        $primaryPhoto = $this->resolvePrimaryPhoto($equipment);
        $photos = $this->mapEquipmentPhotos($equipment);

        return [
            'id' => (int) $equipment->id,
            'cliente_id' => (int) ($equipment->cliente_id ?? 0),
            'client' => $equipment->client ? [
                'id' => (int) $equipment->client->id,
                'nome_razao' => (string) ($equipment->client->nome_razao ?? ''),
                'cpf_cnpj' => (string) ($equipment->client->cpf_cnpj ?? ''),
                'telefone1' => (string) ($equipment->client->telefone1 ?? ''),
                'email' => (string) ($equipment->client->email ?? ''),
                'cidade' => (string) ($equipment->client->cidade ?? ''),
                'uf' => (string) ($equipment->client->uf ?? ''),
            ] : null,
            'tipo_id' => (int) ($equipment->tipo_id ?? 0),
            'tipo_nome' => (string) ($equipment->type?->nome ?? ''),
            'marca_id' => (int) ($equipment->marca_id ?? 0),
            'marca_nome' => (string) ($equipment->brand?->nome ?? ''),
            'modelo_id' => (int) ($equipment->modelo_id ?? 0),
            'modelo_nome' => (string) ($equipment->model?->nome ?? ''),
            'cor' => (string) ($equipment->cor ?? ''),
            'cor_hex' => (string) ($equipment->cor_hex ?? ''),
            'cor_rgb' => (string) ($equipment->cor_rgb ?? ''),
            'numero_serie' => (string) ($equipment->numero_serie ?? ''),
            'imei' => (string) ($equipment->imei ?? ''),
            'senha_acesso' => '',
            'senha_acesso_configurada' => trim((string) ($equipment->senha_acesso ?? '')) !== '',
            'estado_fisico' => (string) ($equipment->estado_fisico ?? ''),
            'observacoes' => (string) ($equipment->observacoes ?? ''),
            'desktop_modalidade' => (string) ($equipment->desktop_modalidade ?? ''),
            'gabinete_tipo' => (string) ($equipment->gabinete_tipo ?? ''),
            'gabinete_identificacao_status' => (string) ($equipment->gabinete_identificacao_status ?? ''),
            'gabinete_observacao' => (string) ($equipment->gabinete_observacao ?? ''),
            'placa_mae' => (string) ($equipment->placa_mae ?? ''),
            'chipset' => (string) ($equipment->chipset ?? ''),
            'processador' => (string) ($equipment->processador ?? ''),
            'memoria_ram' => (string) ($equipment->memoria_ram ?? ''),
            'armazenamento' => (string) ($equipment->armazenamento ?? ''),
            'placa_video' => (string) ($equipment->placa_video ?? ''),
            'fonte_alimentacao' => (string) ($equipment->fonte_alimentacao ?? ''),
            'resumo_tecnico' => (string) ($equipment->resumo_tecnico ?? ''),
            'status_operacional' => (string) ($equipment->status_operacional ?? ''),
            'status' => (string) ($equipment->status ?? ''),
            'orders_count' => (int) ($equipment->orders_count ?? 0),
            'primary_photo_id' => $primaryPhoto?->id !== null ? (int) $primaryPhoto->id : null,
            'primary_photo_url' => $primaryPhoto instanceof EquipmentPhoto
                ? $this->buildPhotoUrl((int) $equipment->id, (int) $primaryPhoto->id)
                : null,
            'photos' => $photos,
            'created_at' => $this->formatDateTime($equipment->created_at),
            'updated_at' => $this->formatDateTime($equipment->updated_at),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapEquipmentPhotos(Equipment $equipment): array
    {
        return $equipment->photos
            ->sort(static function (EquipmentPhoto $left, EquipmentPhoto $right): int {
                $principalComparison = ((int) $right->is_principal) <=> ((int) $left->is_principal);

                if ($principalComparison !== 0) {
                    return $principalComparison;
                }

                return ((int) $left->id) <=> ((int) $right->id);
            })
            ->values()
            ->map(fn (EquipmentPhoto $photo): array => [
                'id' => (int) $photo->id,
                'is_principal' => (bool) $photo->is_principal,
                'url' => $this->buildPhotoUrl((int) $equipment->id, (int) $photo->id),
            ])
            ->all();
    }

    private function resolvePrimaryPhoto(Equipment $equipment): ?EquipmentPhoto
    {
        $primaryPhoto = $equipment->photos->first(static fn (EquipmentPhoto $photo): bool => (bool) $photo->is_principal);

        if ($primaryPhoto instanceof EquipmentPhoto) {
            return $primaryPhoto;
        }

        $firstPhoto = $equipment->photos->sortBy('id')->first();

        return $firstPhoto instanceof EquipmentPhoto ? $firstPhoto : null;
    }

    private function buildPhotoUrl(int $equipmentId, int $photoId): string
    {
        return route('api.v1.equipments.photos.show', [
            'equipment' => $equipmentId,
            'photo' => $photoId,
        ]);
    }

    private function resolveCollectorErrorStatus(Throwable $exception): int
    {
        $status = (int) $exception->getCode();

        return $status >= 400 && $status <= 599 ? $status : 500;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCollectorPairing(EquipmentCollectorPairing $pairing): array
    {
        return [
            'code' => (string) $pairing->code,
            'expires_at' => $pairing->expires_at?->toDateTimeString(),
            'snapshot_received_at' => $pairing->snapshot_received_at?->toDateTimeString(),
            'consumed_at' => $pairing->consumed_at?->toDateTimeString(),
            'source' => (string) ($pairing->source ?? ''),
            'agent_version' => (string) ($pairing->agent_version ?? ''),
            'hostname' => (string) ($pairing->hostname ?? ''),
            'status' => $pairing->consumed_at !== null
                ? 'consumed'
                : ($pairing->snapshot_received_at !== null ? 'ready' : ($pairing->expires_at !== null && $pairing->expires_at->isPast() ? 'expired' : 'waiting')),
            'snapshot' => is_array($pairing->snapshot_normalized) ? $pairing->snapshot_normalized : null,
        ];
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->toDateTimeString();
        }

        return (string) $value;
    }

    private function authorizeEquipmentFormAccess(Request $request): void
    {
        $user = $request->user();

        if ($user !== null && ($user->can('equipamentos:criar') || $user->can('equipamentos:editar'))) {
            return;
        }

        $this->authorize('equipamentos:criar');
    }

    private function authorizeEquipmentViewAccess(Request $request): void
    {
        $user = $request->user();

        if ($user !== null && ($user->can('equipamentos:visualizar') || $user->can('equipamentos:editar'))) {
            return;
        }

        $this->authorize('equipamentos:visualizar');
    }
}
