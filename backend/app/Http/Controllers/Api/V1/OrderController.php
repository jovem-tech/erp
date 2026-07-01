<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\CloseOrderRequest;
use App\Http\Requests\Api\V1\UpdateOrderStatusRequest;
use App\Http\Requests\Api\V1\UpsertOrderRequest;
use App\Services\Orders\OrderClosureService;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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

        $result = $this->orderWorkflowService->updateStatus(
            $order,
            $user,
            (string) $request->validated()['status'],
            isset($request->validated()['observacao']) ? (string) $request->validated()['observacao'] : null
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
            default => $this->error(
                'Falha ao atualizar o status da OS.',
                500,
                'ORDER_STATUS_UPDATE_FAILED',
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
