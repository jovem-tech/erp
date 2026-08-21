<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\CancelFinanceiroRequest;
use App\Http\Requests\Api\V1\RegisterFinanceiroMovementRequest;
use App\Http\Requests\Api\V1\UpsertFinanceiroRequest;
use App\Models\Financeiro;
use App\Models\OrderStatus;
use App\Services\Auth\AdminCredentialVerifier;
use App\Services\Financeiro\FinanceiroCartaoCreditoService;
use App\Services\Financeiro\FinanceiroService;
use App\Services\Orders\OrderClosureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class FinanceiroController extends BaseApiController
{
    public function __construct(
        private readonly FinanceiroService $financeiroService,
        private readonly OrderClosureService $orderClosureService,
        private readonly AdminCredentialVerifier $adminCredentialVerifier
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('financeiro:visualizar');

        $paginator = $this->financeiroService->list($request->query());

        $lancamentos = collect($paginator->items())
            ->map(function (Financeiro $financeiro): array {
                $data = $financeiro->toArray();
                $data['valor_aberto'] = round((float) $this->financeiroService->movementSummary($financeiro)['valor_aberto'], 2);
                $data['origem_trilha'] = $this->financeiroService->resolveOriginTrail($financeiro);
                $data['os_is_encerrada'] = $this->resolveOsIsEncerrada($financeiro);

                return $data;
            })
            ->all();

        return $this->success(
            [
                'lancamentos' => $lancamentos,
                'status_options' => Financeiro::statusOptions(),
                'totais_despesas' => $this->financeiroService->totaisFixoVariavel($request->query()),
            ],
            meta: $this->paginationMeta($paginator),
            request: $request
        );
    }

    public function show(Request $request, Financeiro $financeiro): JsonResponse
    {
        $this->authorize('financeiro:visualizar');

        $financeiro->load([
            'order',
            'client',
            'supplier',
            'movimentos.cartao.operadora',
            'movimentos.cartao.bandeira',
            'movimentos.conta',
        ]);

        $lancamento = $financeiro->toArray();
        $lancamento['os_is_encerrada'] = $this->resolveOsIsEncerrada($financeiro);

        return $this->success(
            [
                'lancamento' => $lancamento,
                'resumo' => $this->financeiroService->movementSummary($financeiro),
                'detalhes' => $this->financeiroService->detailContext($financeiro),
            ],
            request: $request
        );
    }

    public function store(UpsertFinanceiroRequest $request): JsonResponse
    {
        $this->authorize('financeiro:criar');

        try {
            $financeiro = $this->financeiroService->create($request->validated());
        } catch (Throwable $exception) {
            return $this->error($exception->getMessage(), 422, 'FINANCEIRO_SAVE_FAILED', null, request: $request);
        }

        return $this->success(['lancamento' => $financeiro], 201, request: $request);
    }

    public function update(UpsertFinanceiroRequest $request, Financeiro $financeiro): JsonResponse
    {
        $this->authorize('financeiro:editar');

        if ($this->resolveIsReciboDeFatura($financeiro)) {
            return $this->reciboDeFaturaBlockedResponse($request, 'FINANCEIRO_UPDATE_BLOCKED_RECIBO_FATURA');
        }

        try {
            $financeiro = $this->financeiroService->update($financeiro, $request->validated());
        } catch (Throwable $exception) {
            return $this->error($exception->getMessage(), 422, 'FINANCEIRO_SAVE_FAILED', null, request: $request);
        }

        return $this->success(['lancamento' => $financeiro], request: $request);
    }

    public function destroy(CancelFinanceiroRequest $request, Financeiro $financeiro): JsonResponse
    {
        $this->authorize('financeiro:excluir');

        if ($this->resolveIsReciboDeFatura($financeiro)) {
            return $this->reciboDeFaturaBlockedResponse($request, 'FINANCEIRO_DELETE_BLOCKED_RECIBO_FATURA');
        }

        if ($this->resolveIsCartaoFatura($financeiro)) {
            return $this->error(
                'Esta despesa pertence a uma fatura de cartão de crédito e não pode ser excluída individualmente. Gerencie a fatura em Financeiro > Contas e Saldos > Cartões de crédito.',
                409,
                'FINANCEIRO_DELETE_BLOCKED_CARTAO_FATURA',
                null,
                request: $request
            );
        }

        if ($this->resolveOsIsEncerrada($financeiro)) {
            return $this->error(
                'Esta OS está encerrada. Títulos vinculados a uma OS encerrada não podem ser excluídos — use "Cancelar" para preservar o histórico e corrigir o status da OS.',
                409,
                'FINANCEIRO_DELETE_BLOCKED_OS_ENCERRADA',
                null,
                request: $request
            );
        }

        // Exclusão é irreversível e apaga o registro por completo (diferente
        // do "Cancelar", que preserva o histórico) — exige confirmação de
        // administrador sempre, não só quando a OS está encerrada.
        $validated = $request->validated();
        $email = trim((string) ($validated['admin_email'] ?? ''));
        $password = (string) ($validated['admin_password'] ?? '');

        if ($email === '' || $password === '') {
            return $this->error(
                'Para excluir um lançamento é necessário confirmar com credenciais de administrador.',
                409,
                'FINANCEIRO_DELETE_REQUIRES_ADMIN',
                null,
                request: $request
            );
        }

        $verification = $this->adminCredentialVerifier->verify(
            $email,
            $password,
            'financeiro-delete-admin-auth',
            (string) $request->ip()
        );

        $errorResponse = $this->respondToAdminVerification(
            $verification,
            $request,
            'FINANCEIRO_ADMIN_AUTH_RATE_LIMITED',
            'FINANCEIRO_ADMIN_AUTH_INVALID'
        );

        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $this->financeiroService->delete($financeiro);

        return $this->success(null, request: $request);
    }

    public function pay(RegisterFinanceiroMovementRequest $request, Financeiro $financeiro): JsonResponse
    {
        $this->authorize('financeiro:editar');

        if ($this->resolveIsReciboDeFatura($financeiro)) {
            return $this->reciboDeFaturaBlockedResponse($request, 'FINANCEIRO_BAIXA_BLOCKED_RECIBO_FATURA');
        }

        if ($this->resolveIsCartaoFatura($financeiro)) {
            return $this->error(
                'Esta despesa pertence a uma fatura de cartão de crédito e não recebe baixa individual. Registre o pagamento pela fatura do cartão.',
                409,
                'FINANCEIRO_BAIXA_BLOCKED_CARTAO_FATURA',
                null,
                request: $request
            );
        }

        try {
            $resumo = $this->financeiroService->registerMovement($financeiro, $request->validated());
        } catch (Throwable $exception) {
            return $this->error($exception->getMessage(), 422, 'FINANCEIRO_BAIXA_FAILED', null, request: $request);
        }

        return $this->success(['resumo' => $resumo, 'lancamento' => $financeiro->refresh()], request: $request);
    }

    public function cancel(CancelFinanceiroRequest $request, Financeiro $financeiro): JsonResponse
    {
        $this->authorize('financeiro:editar');

        if ($this->resolveIsReciboDeFatura($financeiro)) {
            return $this->reciboDeFaturaBlockedResponse($request, 'FINANCEIRO_CANCEL_BLOCKED_RECIBO_FATURA');
        }

        if ($this->resolveIsCartaoFatura($financeiro)) {
            return $this->error(
                'Esta despesa pertence a uma fatura de cartão de crédito e não pode ser cancelada individualmente. Gerencie a fatura em Financeiro > Contas e Saldos > Cartões de crédito.',
                409,
                'FINANCEIRO_CANCEL_BLOCKED_CARTAO_FATURA',
                null,
                request: $request
            );
        }

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        if (! $this->resolveOsIsEncerrada($financeiro)) {
            // OS aberta (ou título sem OS vinculada): fluxo simples de sempre,
            // sem motivo nem confirmação de admin.
            try {
                $financeiro = $this->financeiroService->cancel($financeiro);
            } catch (Throwable $exception) {
                return $this->error($exception->getMessage(), 422, 'FINANCEIRO_CANCEL_FAILED', null, request: $request);
            }

            return $this->success(['lancamento' => $financeiro], request: $request);
        }

        $validated = $request->validated();
        $motivo = trim((string) ($validated['motivo'] ?? ''));
        $email = trim((string) ($validated['admin_email'] ?? ''));
        $password = (string) ($validated['admin_password'] ?? '');

        if ($motivo === '' || $email === '' || $password === '') {
            return $this->error(
                'Esta OS está encerrada. Informe o motivo do cancelamento e confirme com credenciais de administrador.',
                409,
                'FINANCEIRO_CANCEL_REQUIRES_REASON_AND_ADMIN',
                null,
                request: $request
            );
        }

        $verification = $this->adminCredentialVerifier->verify(
            $email,
            $password,
            'financeiro-cancel-admin-auth',
            (string) $request->ip()
        );

        $errorResponse = $this->respondToAdminVerification(
            $verification,
            $request,
            'FINANCEIRO_ADMIN_AUTH_RATE_LIMITED',
            'FINANCEIRO_ADMIN_AUTH_INVALID'
        );

        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $admin = $verification['admin'];

        $result = $this->orderClosureService->cancelReceivableWithReason($financeiro, $motivo, $user, $admin);

        return match ($result['result'] ?? 'error') {
            'ok' => $this->success(
                [
                    'lancamento' => $result['financeiro'] ?? null,
                    'order' => $result['order'] ?? null,
                ],
                request: $request
            ),
            'not_found' => $this->error(
                'OS não encontrada.',
                404,
                'ORDER_NOT_FOUND',
                null,
                request: $request
            ),
            'forbidden' => $this->error(
                'Você não tem permissão para alterar esta OS.',
                403,
                'ORDER_FORBIDDEN',
                null,
                request: $request
            ),
            'invalid_status' => $this->error(
                'Não foi possível aplicar o novo status à OS.',
                422,
                'FINANCEIRO_CANCEL_INVALID_STATUS',
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
                'Não foi possível cancelar o título agora. Tente novamente.',
                500,
                'FINANCEIRO_CANCEL_FAILED',
                null,
                request: $request
            ),
        };
    }

    /**
     * OS encerrada com impacto financeiro real (ver
     * OrderStatus::FINANCIAL_IMPACT_CLOSURE_CODES) — cancelar o título exige
     * motivo + confirmação de administrador (ver cancel()).
     */
    private function resolveOsIsEncerrada(Financeiro $financeiro): bool
    {
        $status = trim((string) ($financeiro->order?->status ?? ''));

        return $status !== '' && in_array($status, OrderStatus::FINANCIAL_IMPACT_CLOSURE_CODES, true);
    }

    /**
     * Despesa lançada no crédito de um cartão da assistência entra no ciclo
     * de fatura (ver FinanceiroCartaoCreditoService) — baixa, cancelamento e
     * exclusão passam a ser exclusivos da fatura (FinanceiroCartaoCreditoController::faturaPagar
     * chama FinanceiroService::registerMovement() diretamente, sem passar por
     * este controller, então esse bloqueio não afeta a baixa em lote).
     */
    private function resolveIsCartaoFatura(Financeiro $financeiro): bool
    {
        return $financeiro->cartao_credito_id !== null
            && $financeiro->cartao_modalidade === FinanceiroCartaoCreditoService::MODALIDADE_CREDITO;
    }

    /**
     * O recibo de pagamento de fatura (ver
     * FinanceiroCartaoCreditoService::registerInvoicePaymentReceipt()) é um
     * registro sintético: ele existe para representar, numa linha só, uma baixa
     * em lote que já aconteceu. Quem manda nele é a fatura — pagar e cancelar a
     * baixa por lá criam e cancelam o recibo junto.
     *
     * Editar/cancelar/excluir pelas telas genéricas de Lançamentos o deixava em
     * estado inconsistente com a fatura que ele resume: salvar pelo formulário
     * comum apaga o vínculo com o cartão (resolveClassification() zera
     * cartao_credito_id quando o payload não traz cartão) e devolve o título
     * para "pendente", fazendo a fatura paga parecer não registrada.
     */
    private function resolveIsReciboDeFatura(Financeiro $financeiro): bool
    {
        return (string) $financeiro->origem_tipo === Financeiro::ORIGEM_TIPO_FATURA_CARTAO_CREDITO;
    }

    private function reciboDeFaturaBlockedResponse(Request $request, string $code): JsonResponse
    {
        return $this->error(
            'Este lançamento é o registro do pagamento de uma fatura de cartão e é gerenciado pela própria fatura. Para desfazê-lo, use "Cancelar baixa da fatura" na tela da fatura.',
            409,
            $code,
            null,
            request: $request
        );
    }
}
