<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreSaleReturnRequest;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\Auth\AdminCredentialVerifier;
use App\Services\Sales\SaleReturnReceiptService;
use App\Services\Sales\SaleReturnService;
use App\Services\Sales\SaleWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Devolução e troca de venda — specs/029-devolucao-troca/spec.md.
 *
 * Devolver é ação do módulo `vendas`: não há permissão própria.
 */
class SaleReturnController extends BaseApiController
{
    public function __construct(
        private readonly SaleReturnService $saleReturnService,
        private readonly SaleWorkflowService $saleWorkflowService,
        private readonly SaleReturnReceiptService $saleReturnReceiptService,
        private readonly AdminCredentialVerifier $adminCredentialVerifier
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('vendas:visualizar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $paginator = $this->saleReturnService->paginate($request->query());

        return $this->success(
            ['devolucoes' => $paginator->items()],
            meta: $this->paginationMeta($paginator),
            request: $request
        );
    }

    public function show(Request $request, int $devolucao): JsonResponse
    {
        $this->authorize('vendas:visualizar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        return $this->success(
            ['devolucao' => $this->saleReturnService->mapDetail($this->saleReturnService->loadOrFail($devolucao))],
            request: $request
        );
    }

    /**
     * Saldo devolvível da venda: o que ainda pode voltar, por item.
     */
    public function returnableItems(Request $request, int $venda): JsonResponse
    {
        $this->authorize('vendas:visualizar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $sale = $this->saleWorkflowService->loadSaleOrFail($venda);

        return $this->success(
            [
                'venda' => [
                    'id' => (int) $sale->id,
                    'numero' => (string) $sale->numero,
                    'total' => round((float) $sale->total, 2),
                    'valor_pago' => round((float) $sale->valor_pago, 2),
                    'cancelada' => $sale->isCancelled(),
                ],
                'itens' => $this->saleReturnService->returnableItems($sale),
                'exige_autorizacao' => $this->saleReturnService->requiresAdminApproval($sale),
                'prazo_livre_dias' => SaleReturn::PRAZO_LIVRE_DIAS,
            ],
            request: $request
        );
    }

    public function store(StoreSaleReturnRequest $request, int $venda): JsonResponse
    {
        $this->authorize('vendas:criar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $sale = $this->saleWorkflowService->loadSaleOrFail($venda);
        $authorizedBy = null;

        // Devolver venda antiga mexe em caixa e DRE de período já conferido.
        if ($this->saleReturnService->requiresAdminApproval($sale)) {
            $email = trim((string) $request->input('admin_email', ''));
            $password = (string) $request->input('admin_password', '');

            if ($email === '' || $password === '') {
                return $this->error(
                    sprintf(
                        'Devolver uma venda com mais de %d dias exige confirmação de um administrador.',
                        SaleReturn::PRAZO_LIVRE_DIAS
                    ),
                    422,
                    'DEVOLUCAO_ADMIN_AUTH_REQUIRED',
                    null,
                    request: $request
                );
            }

            $verification = $this->adminCredentialVerifier->verify(
                $email,
                $password,
                'venda-devolucao-admin-auth',
                (string) $request->ip()
            );

            $errorResponse = $this->respondToAdminVerification(
                $verification,
                $request,
                'DEVOLUCAO_ADMIN_AUTH_RATE_LIMITED',
                'DEVOLUCAO_ADMIN_AUTH_INVALID'
            );

            if ($errorResponse !== null) {
                return $errorResponse;
            }

            $authorizedBy = $verification['admin'] ?? null;
        }

        try {
            $result = $this->saleReturnService->create($user, $sale, $request->validated(), $authorizedBy);
        } catch (RuntimeException $exception) {
            return $this->error(
                $exception->getMessage(),
                422,
                'DEVOLUCAO_INVALIDA',
                null,
                request: $request
            );
        }

        if (($result['result'] ?? '') === 'idempotency_conflict') {
            return $this->error(
                'Esta chave de criação já foi usada para outra devolução.',
                409,
                'DEVOLUCAO_IDEMPOTENCY_CONFLICT',
                null,
                request: $request
            );
        }

        // Troca: vincula a venda nova que o cliente levou no lugar.
        $exchangeId = (int) $request->input('venda_troca_id', 0);

        if ($exchangeId > 0) {
            try {
                $devolucao = $this->saleReturnService->loadOrFail((int) $result['devolucao']['id']);
                $novaVenda = $this->saleWorkflowService->loadSaleOrFail($exchangeId);
                $this->saleReturnService->linkExchange($devolucao, $novaVenda);
                $result['devolucao'] = $this->saleReturnService->mapDetail($devolucao->refresh());
            } catch (RuntimeException $exception) {
                return $this->error(
                    $exception->getMessage(),
                    422,
                    'DEVOLUCAO_TROCA_INVALIDA',
                    null,
                    request: $request
                );
            }
        }

        return $this->success(
            [
                'devolucao' => $result['devolucao'],
                'idempotent_replay' => (bool) ($result['idempotent_replay'] ?? false),
            ],
            Response::HTTP_CREATED,
            request: $request
        );
    }

    /**
     * Comprovante da devolução. Padrão 80 mm; `?formato=a4` para folha inteira.
     */
    public function receipt(Request $request, int $devolucao): Response
    {
        $this->authorize('vendas:visualizar');

        $model = $this->saleReturnService->loadOrFail($devolucao);

        $format = (string) $request->query('formato', '80mm');
        $format = in_array($format, ['80mm', 'a4'], true) ? $format : '80mm';

        try {
            return $this->saleReturnReceiptService->stream($model, $format);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(
                'Não foi possível gerar o comprovante desta devolução.',
                422,
                'DEVOLUCAO_COMPROVANTE_INDISPONIVEL',
                null,
                request: $request
            );
        }
    }
}
