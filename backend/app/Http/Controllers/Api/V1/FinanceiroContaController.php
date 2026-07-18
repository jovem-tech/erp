<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\CancelFinanceiroTransferRequest;
use App\Http\Requests\Api\V1\ConfirmFinanceiroCardCreditRequest;
use App\Http\Requests\Api\V1\StoreFinanceiroContaAdjustmentRequest;
use App\Http\Requests\Api\V1\StoreFinanceiroTransferRequest;
use App\Http\Requests\Api\V1\UpsertFinanceiroContaRequest;
use App\Models\FinanceiroConta;
use App\Models\FinanceiroMovimentoCartao;
use App\Models\FinanceiroTransferencia;
use App\Services\Financeiro\FinanceiroContaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class FinanceiroContaController extends BaseApiController
{
    public function __construct(
        private readonly FinanceiroContaService $financeiroContaService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('financeiro:visualizar');

        try {
            $dashboard = $this->financeiroContaService->dashboard($request->query('mes'));
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'FINANCEIRO_CONTAS_QUERY_FAILED', request: $request);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Não foi possível consultar as contas financeiras.', 500, 'FINANCEIRO_CONTAS_QUERY_FAILED', request: $request);
        }

        return $this->success($dashboard, request: $request);
    }

    public function store(UpsertFinanceiroContaRequest $request): JsonResponse
    {
        $this->authorize('financeiro:editar');

        try {
            $account = $this->financeiroContaService->createAccount(
                $request->validated(),
                $this->authenticatedUser($request)?->id
            );
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'FINANCEIRO_CONTA_SAVE_FAILED', request: $request);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Não foi possível salvar a conta financeira.', 500, 'FINANCEIRO_CONTA_SAVE_FAILED', request: $request);
        }

        return $this->success(['conta' => $account], 201, request: $request);
    }

    public function update(UpsertFinanceiroContaRequest $request, FinanceiroConta $conta): JsonResponse
    {
        $this->authorize('financeiro:editar');

        try {
            $account = $this->financeiroContaService->updateAccount(
                $conta,
                $request->validated(),
                $this->authenticatedUser($request)?->id
            );
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'FINANCEIRO_CONTA_SAVE_FAILED', request: $request);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Não foi possível salvar a conta financeira.', 500, 'FINANCEIRO_CONTA_SAVE_FAILED', request: $request);
        }

        return $this->success(['conta' => $account], request: $request);
    }

    public function statement(Request $request, FinanceiroConta $conta): JsonResponse
    {
        $this->authorize('financeiro:visualizar');
        $filters = $request->validate([
            'data_inicio' => ['nullable', 'date'],
            'data_fim' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $statement = $this->financeiroContaService->statement($conta, $filters);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'FINANCEIRO_EXTRATO_FAILED', request: $request);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Não foi possível consultar o extrato da conta.', 500, 'FINANCEIRO_EXTRATO_FAILED', request: $request);
        }

        return $this->success($statement, request: $request);
    }

    public function adjust(
        StoreFinanceiroContaAdjustmentRequest $request,
        FinanceiroConta $conta
    ): JsonResponse {
        $this->authorize('financeiro:editar');

        try {
            $movement = $this->financeiroContaService->createAdjustment(
                $conta,
                $request->validated(),
                $this->authenticatedUser($request)?->id
            );
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'FINANCEIRO_AJUSTE_FAILED', request: $request);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Não foi possível registrar o ajuste de conciliação.', 500, 'FINANCEIRO_AJUSTE_FAILED', request: $request);
        }

        return $this->success(['movimento' => $movement], 201, request: $request);
    }

    public function transfer(StoreFinanceiroTransferRequest $request): JsonResponse
    {
        $this->authorize('financeiro:editar');

        try {
            $transfer = $this->financeiroContaService->createTransfer(
                $request->validated(),
                $this->authenticatedUser($request)?->id
            );
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'FINANCEIRO_TRANSFERENCIA_FAILED', request: $request);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Não foi possível realizar a transferência.', 500, 'FINANCEIRO_TRANSFERENCIA_FAILED', request: $request);
        }

        return $this->success(['transferencia' => $transfer], 201, request: $request);
    }

    public function cancelTransfer(
        CancelFinanceiroTransferRequest $request,
        FinanceiroTransferencia $transferencia
    ): JsonResponse {
        $this->authorize('financeiro:editar');

        try {
            $transfer = $this->financeiroContaService->cancelTransfer(
                $transferencia,
                (string) $request->validated('motivo'),
                $this->authenticatedUser($request)?->id
            );
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'FINANCEIRO_TRANSFERENCIA_CANCEL_FAILED', request: $request);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Não foi possível cancelar a transferência.', 500, 'FINANCEIRO_TRANSFERENCIA_CANCEL_FAILED', request: $request);
        }

        return $this->success(['transferencia' => $transfer], request: $request);
    }

    public function confirmCard(
        ConfirmFinanceiroCardCreditRequest $request,
        FinanceiroMovimentoCartao $cartao
    ): JsonResponse {
        $this->authorize('financeiro:editar');

        try {
            $card = $this->financeiroContaService->confirmCardCredit(
                $cartao,
                (string) $request->validated('data_credito_efetivo'),
                $this->authenticatedUser($request)?->id
            );
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'FINANCEIRO_CARTAO_CONFIRM_FAILED', request: $request);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Não foi possível confirmar o crédito do cartão.', 500, 'FINANCEIRO_CARTAO_CONFIRM_FAILED', request: $request);
        }

        return $this->success(['cartao' => $card], request: $request);
    }
}
