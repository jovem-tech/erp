<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\CancelOrderClosureRequest;
use App\Http\Requests\Api\V1\CloseOrderRequest;
use App\Http\Requests\Api\V1\StoreOrderProcedureRequest;
use App\Http\Requests\Api\V1\UpdateOrderStatusRequest;
use App\Http\Requests\Api\V1\UpsertOrderRequest;
use App\Models\User;
use App\Services\Orders\OrderClosureService;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OrderController extends BaseApiController
{
    public function __construct(
        private readonly OrderWorkflowService $orderWorkflowService,
        private readonly OrderClosureService $orderClosureService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('os:visualizar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $paginator = $this->orderWorkflowService->paginateForUser($user, $request->query());

        return $this->success(
            ['orders' => $paginator->items()],
            meta: $this->paginationMeta($paginator),
            request: $request
        );
    }

    public function statusCatalog(Request $request): JsonResponse
    {
        $this->authorize('os:visualizar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        return $this->success(
            ['statuses' => $this->orderWorkflowService->statusCatalogOptions()],
            request: $request
        );
    }

    public function entryChecklistModel(Request $request, int $tipoEquipamento): JsonResponse
    {
        $this->authorize('os:visualizar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        return $this->success(
            ['modelo' => $this->orderWorkflowService->entryChecklistModelForEquipmentType($tipoEquipamento)],
            request: $request
        );
    }

    public function show(Request $request, int $order): JsonResponse
    {
        $this->authorize('os:visualizar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $result = $this->orderWorkflowService->showForUser($user, $order);

        return match ($result['result'] ?? 'error') {
            'ok' => $this->success(
                ['order' => $result['order'] ?? null],
                request: $request
            ),
            'forbidden' => $this->error(
                'Você não tem permissão para acessar esta OS.',
                403,
                'ORDER_FORBIDDEN',
                null,
                request: $request
            ),
            'not_found' => $this->error(
                'OS não encontrada.',
                404,
                'ORDER_NOT_FOUND',
                null,
                request: $request
            ),
            default => $this->error(
                'Falha ao carregar a OS.',
                500,
                'ORDER_SHOW_FAILED',
                null,
                request: $request
            ),
        };
    }

    public function store(UpsertOrderRequest $request): JsonResponse
    {
        $this->authorize('os:criar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $result = $this->orderWorkflowService->createOrder(
            $user,
            $request->validated(),
            $this->extractUploadedFiles($request, 'fotos')
        );

        return match ($result['result'] ?? 'error') {
            'ok' => $this->success(
                ['order' => $result['order'] ?? null],
                201,
                request: $request
            ),
            'equipment_client_mismatch' => $this->error(
                'O equipamento informado não pertence ao cliente selecionado.',
                422,
                'ORDER_EQUIPMENT_CLIENT_MISMATCH',
                null,
                request: $request
            ),
            'invalid_status' => $this->error(
                'O status informado não é válido para o catálogo atual.',
                422,
                'ORDER_STATUS_INVALID',
                null,
                request: $request
            ),
            'entry_checklist_model_not_found' => $this->error(
                'Nao existe modelo ativo de checklist de entrada para o tipo de equipamento informado.',
                422,
                'ORDER_ENTRY_CHECKLIST_MODEL_NOT_FOUND',
                null,
                request: $request
            ),
            'entry_checklist_model_empty' => $this->error(
                'O modelo de checklist de entrada nao possui itens ativos.',
                422,
                'ORDER_ENTRY_CHECKLIST_MODEL_EMPTY',
                null,
                request: $request
            ),
            'entry_checklist_invalid_items', 'entry_checklist_invalid_payload' => $this->error(
                'O checklist de entrada contem itens invalidos para o modelo vigente.',
                422,
                'ORDER_ENTRY_CHECKLIST_INVALID',
                null,
                request: $request
            ),
            default => $this->error(
                'Falha ao criar a OS.',
                500,
                'ORDER_CREATE_FAILED',
                null,
                request: $request
            ),
        };
    }

    public function update(UpsertOrderRequest $request, int $order): JsonResponse
    {
        $this->authorize('os:editar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $result = $this->orderWorkflowService->updateOrder(
            $order,
            $user,
            $request->validated(),
            $this->extractUploadedFiles($request, 'fotos')
        );

        return match ($result['result'] ?? 'error') {
            'ok' => $this->success(
                ['order' => $result['order'] ?? null],
                request: $request
            ),
            'forbidden' => $this->error(
                'Você não tem permissão para alterar esta OS.',
                403,
                'ORDER_FORBIDDEN',
                null,
                request: $request
            ),
            'not_found' => $this->error(
                'OS não encontrada.',
                404,
                'ORDER_NOT_FOUND',
                null,
                request: $request
            ),
            'equipment_client_mismatch' => $this->error(
                'O equipamento informado não pertence ao cliente selecionado.',
                422,
                'ORDER_EQUIPMENT_CLIENT_MISMATCH',
                null,
                request: $request
            ),
            'invalid_status' => $this->error(
                'O status informado não é válido para o catálogo atual.',
                422,
                'ORDER_STATUS_INVALID',
                null,
                request: $request
            ),
            'entry_checklist_model_not_found' => $this->error(
                'Nao existe modelo ativo de checklist de entrada para o tipo de equipamento informado.',
                422,
                'ORDER_ENTRY_CHECKLIST_MODEL_NOT_FOUND',
                null,
                request: $request
            ),
            'entry_checklist_model_empty' => $this->error(
                'O modelo de checklist de entrada nao possui itens ativos.',
                422,
                'ORDER_ENTRY_CHECKLIST_MODEL_EMPTY',
                null,
                request: $request
            ),
            'entry_checklist_invalid_items', 'entry_checklist_invalid_payload' => $this->error(
                'O checklist de entrada contem itens invalidos para o modelo vigente.',
                422,
                'ORDER_ENTRY_CHECKLIST_INVALID',
                null,
                request: $request
            ),
            'closure_status_requires_baixa_flow' => $this->error(
                'Este status só pode ser aplicado pela tela de baixa da OS (Encerramento), não pela edição da OS.',
                422,
                'ORDER_STATUS_CLOSURE_REQUIRES_BAIXA_FLOW',
                null,
                request: $request
            ),
            'order_is_closed' => $this->error(
                'Esta OS está encerrada. Para alterar o status, cancele a baixa da OS primeiro.',
                422,
                'ORDER_IS_CLOSED',
                null,
                request: $request
            ),
            default => $this->error(
                'Falha ao atualizar a OS.',
                500,
                'ORDER_UPDATE_FAILED',
                null,
                request: $request
            ),
        };
    }

    public function photo(Request $request, int $order, int $photo): BinaryFileResponse|JsonResponse
    {
        $this->authorize('os:visualizar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $result = $this->orderWorkflowService->resolvePhotoAccess($order, $photo, $user);
        if (($result['result'] ?? 'error') !== 'ok') {
            return $this->attachmentErrorResponse($request, $result['result'] ?? 'error', 'PHOTO');
        }

        $file = $result['file'];

        return response()->file($file['absolute_path'], [
            'Content-Type' => $file['mime_type'],
            'Content-Disposition' => 'inline; filename="' . $file['filename'] . '"',
        ]);
    }

    public function document(Request $request, int $order, int $document): BinaryFileResponse|JsonResponse
    {
        $this->authorize('os:visualizar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $result = $this->orderWorkflowService->resolveDocumentAccess($order, $document, $user);
        if (($result['result'] ?? 'error') !== 'ok') {
            return $this->attachmentErrorResponse($request, $result['result'] ?? 'error', 'DOCUMENT');
        }

        $file = $result['file'];

        return response()->file($file['absolute_path'], [
            'Content-Type' => $file['mime_type'],
            'Content-Disposition' => 'inline; filename="' . $file['filename'] . '"',
        ]);
    }

    public function updateStatus(UpdateOrderStatusRequest $request, int $order): JsonResponse
    {
        $this->authorize('os:editar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $validated = $request->validated();

        $result = $this->orderWorkflowService->updateStatus(
            $order,
            $user,
            isset($validated['status']) && $validated['status'] !== '' ? (string) $validated['status'] : null,
            isset($validated['observacao']) ? (string) $validated['observacao'] : null,
            isset($validated['diagnostico_tecnico']) ? (string) $validated['diagnostico_tecnico'] : null,
            isset($validated['solucao_aplicada']) ? (string) $validated['solucao_aplicada'] : null,
            filter_var($validated['comunicar_cliente'] ?? false, FILTER_VALIDATE_BOOL)
        );

        return match ($result['result'] ?? 'error') {
            'ok' => $this->success(
                [
                    'order' => $result['order'] ?? null,
                    'status_anterior' => $result['status_anterior'] ?? null,
                    'status_novo' => $result['status_novo'] ?? null,
                    'estado_fluxo' => $result['estado_fluxo'] ?? null,
                ],
                request: $request
            ),
            'forbidden' => $this->error(
                'Você não tem permissão para alterar esta OS.',
                403,
                'ORDER_FORBIDDEN',
                null,
                request: $request
            ),
            'not_found' => $this->error(
                'OS não encontrada.',
                404,
                'ORDER_NOT_FOUND',
                null,
                request: $request
            ),
            'invalid_status' => $this->error(
                'O status informado não é válido para o catálogo atual.',
                422,
                'ORDER_STATUS_INVALID',
                null,
                request: $request
            ),
            'invalid_transition' => $this->error(
                'A transição de status solicitada não é permitida a partir da etapa atual.',
                422,
                'ORDER_STATUS_TRANSITION_INVALID',
                ['proximas_etapas' => $result['proximas_etapas'] ?? []],
                request: $request
            ),
            'closure_status_requires_baixa_flow' => $this->error(
                'Este status só pode ser aplicado pela tela de baixa da OS (Encerramento), não por aqui.',
                422,
                'ORDER_STATUS_CLOSURE_REQUIRES_BAIXA_FLOW',
                null,
                request: $request
            ),
            'order_is_closed' => $this->error(
                'Esta OS está encerrada. Para alterá-la, cancele a baixa da OS primeiro.',
                422,
                'ORDER_IS_CLOSED',
                null,
                request: $request
            ),
            default => $this->error(
                'Falha ao atualizar o status da OS.',
                500,
                'ORDER_STATUS_UPDATE_FAILED',
                null,
                request: $request
            ),
        };
    }

    public function storeProcedure(StoreOrderProcedureRequest $request, int $order): JsonResponse
    {
        $this->authorize('os:editar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $result = $this->orderWorkflowService->addProcedureEntry(
            $order,
            $user,
            (string) $request->validated()['descricao']
        );

        return match ($result['result'] ?? 'error') {
            'ok' => $this->success(['order' => $result['order'] ?? null], request: $request),
            'forbidden' => $this->error(
                'Você não tem permissão para alterar esta OS.',
                403,
                'ORDER_FORBIDDEN',
                null,
                request: $request
            ),
            'not_found' => $this->error(
                'OS não encontrada.',
                404,
                'ORDER_NOT_FOUND',
                null,
                request: $request
            ),
            'empty_description' => $this->error(
                'Descreva o procedimento executado antes de salvar.',
                422,
                'ORDER_PROCEDURE_DESCRIPTION_REQUIRED',
                null,
                request: $request
            ),
            default => $this->error(
                'Falha ao salvar o procedimento executado.',
                500,
                'ORDER_PROCEDURE_STORE_FAILED',
                null,
                request: $request
            ),
        };
    }

    public function closureMetadata(Request $request, int $order): JsonResponse
    {
        $this->authorize('os:editar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $result = $this->orderClosureService->metadata($order, $user);

        return match ($result['result'] ?? 'error') {
            'ok' => $this->success(
                [
                    'order' => $result['order'] ?? null,
                    'cliente_telefone' => $result['cliente_telefone'] ?? '',
                    'opcoes_encerramento' => $result['opcoes_encerramento'] ?? [],
                    'financeiro' => $result['financeiro'] ?? null,
                    'custo_summary' => $result['custo_summary'] ?? null,
                    'retorno_padrao' => $result['retorno_padrao'] ?? null,
                    'cartao' => $result['cartao'] ?? null,
                    'status_pagamento_pendente' => $result['status_pagamento_pendente'] ?? null,
                    'status_sem_reparo' => $result['status_sem_reparo'] ?? [],
                ],
                request: $request
            ),
            'forbidden' => $this->error(
                'Você não tem permissão para acessar esta OS.',
                403,
                'ORDER_FORBIDDEN',
                null,
                request: $request
            ),
            'not_found' => $this->error(
                'OS não encontrada.',
                404,
                'ORDER_NOT_FOUND',
                null,
                request: $request
            ),
            default => $this->error(
                'Falha ao carregar os dados de baixa da OS.',
                500,
                'ORDER_CLOSURE_METADATA_FAILED',
                null,
                request: $request
            ),
        };
    }

    public function close(CloseOrderRequest $request, int $order): JsonResponse
    {
        $this->authorize('os:editar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $result = $this->orderClosureService->close($order, $user, $request->validated());

        return match ($result['result'] ?? 'error') {
            'ok' => $this->success(
                [
                    'order' => $result['order'] ?? null,
                    'notificacao_enviada' => $result['notificacao_enviada'] ?? null,
                ],
                request: $request
            ),
            'forbidden' => $this->error(
                'Você não tem permissão para alterar esta OS.',
                403,
                'ORDER_FORBIDDEN',
                null,
                request: $request
            ),
            'not_found' => $this->error(
                'OS não encontrada.',
                404,
                'ORDER_NOT_FOUND',
                null,
                request: $request
            ),
            'invalid_status' => $this->error(
                'O status de encerramento informado não é válido.',
                422,
                'ORDER_CLOSURE_STATUS_INVALID',
                null,
                request: $request
            ),
            'invalid_date' => $this->error(
                'A data de entrega informada é inválida.',
                422,
                'ORDER_CLOSURE_DATE_INVALID',
                null,
                request: $request
            ),
            'invalid_card_payment' => $this->error(
                (string) ($result['message'] ?? 'Recebimento em cartão inválido.'),
                422,
                'ORDER_CLOSURE_CARD_PAYMENT_INVALID',
                null,
                request: $request
            ),
            default => $this->error(
                'Falha ao concluir a baixa da OS.',
                500,
                'ORDER_CLOSURE_FAILED',
                null,
                request: $request
            ),
        };
    }

    public function cancelClosure(CancelOrderClosureRequest $request, int $order): JsonResponse
    {
        // Regra de negócio (skill sistema-erp-os-fluxo-fechamento): o botão fica
        // visível para qualquer usuário com acesso ao painel da OS, mas a ação só
        // se concretiza com credenciais de um usuário administrador (perfil=admin)
        // — o gate real de autorização é essa verificação, não a permissão do
        // usuário logado. Por isso aqui exige-se apenas 'os:visualizar'.
        $this->authorize('os:visualizar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $validated = $request->validated();
        $adminEmail = mb_strtolower(trim((string) $validated['admin_email']));
        $adminPassword = (string) $validated['admin_password'];

        $throttleKey = 'os-closure-cancel-admin-auth:' . $adminEmail . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return $this->error(
                'Muitas tentativas de verificação de administrador. Aguarde um pouco e tente novamente.',
                429,
                'ORDER_CLOSURE_CANCEL_ADMIN_AUTH_RATE_LIMITED',
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

            logger()->warning('[API V1][ORDERS][CLOSURE] Credenciais de administrador inválidas ao cancelar baixa', [
                'order_id' => $order,
                'user_id' => $user->id,
                'admin_email' => $adminEmail,
                'ip' => $request->ip(),
            ]);

            // 422, nao 401: o desktop trata QUALQUER 401 como "sessao do usuario
            // atual expirou" e forca logout (ApiClient::parseResponse). Isso e'
            // uma verificacao de credenciais de um usuario DIFERENTE (admin),
            // nao a sessao de quem esta clicando — nunca pode disparar esse logout.
            return $this->error(
                'Credenciais de administrador inválidas.',
                422,
                'ORDER_CLOSURE_CANCEL_ADMIN_AUTH_INVALID',
                null,
                request: $request
            );
        }

        RateLimiter::clear($throttleKey);

        $result = $this->orderClosureService->cancelClosure($order, $user, $admin);

        return match ($result['result'] ?? 'error') {
            'ok' => $this->success(
                [
                    'order' => $result['order'] ?? null,
                    'status_revertido' => $result['status_revertido'] ?? null,
                ],
                request: $request
            ),
            'forbidden' => $this->error(
                'Você não tem permissão para alterar esta OS.',
                403,
                'ORDER_FORBIDDEN',
                null,
                request: $request
            ),
            'not_found' => $this->error(
                'OS não encontrada.',
                404,
                'ORDER_NOT_FOUND',
                null,
                request: $request
            ),
            'not_closed' => $this->error(
                'Esta OS não está encerrada — não há baixa para cancelar.',
                422,
                'ORDER_CLOSURE_NOT_CLOSED',
                null,
                request: $request
            ),
            'cannot_resolve_previous_status' => $this->error(
                'Não foi possível identificar o status anterior à baixa desta OS. Cancele manualmente pelo modal "Alterar status".',
                422,
                'ORDER_CLOSURE_CANCEL_PREVIOUS_STATUS_UNKNOWN',
                null,
                request: $request
            ),
            default => $this->error(
                'Falha ao cancelar a baixa da OS.',
                500,
                'ORDER_CLOSURE_CANCEL_FAILED',
                null,
                request: $request
            ),
        };
    }

    private function attachmentErrorResponse(Request $request, string $result, string $kind): JsonResponse
    {
        return match ($result) {
            'forbidden' => $this->error(
                'Você não tem permissão para acessar este arquivo.',
                403,
                'ORDER_FORBIDDEN',
                null,
                request: $request
            ),
            'missing_file' => $this->error(
                'O arquivo solicitado não foi encontrado no armazenamento legado.',
                404,
                'ORDER_' . $kind . '_MISSING_FILE',
                null,
                request: $request
            ),
            'not_found' => $this->error(
                'Arquivo não encontrado.',
                404,
                'ORDER_' . $kind . '_NOT_FOUND',
                null,
                request: $request
            ),
            default => $this->error(
                'Falha ao acessar o arquivo solicitado.',
                500,
                'ORDER_' . $kind . '_ACCESS_FAILED',
                null,
                request: $request
            ),
        };
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
}
