<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\RegisterFinanceiroMovementRequest;
use App\Http\Requests\Api\V1\UpsertFinanceiroRequest;
use App\Models\Financeiro;
use App\Services\Financeiro\FinanceiroService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class FinanceiroController extends BaseApiController
{
    public function __construct(
        private readonly FinanceiroService $financeiroService
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

                return $data;
            })
            ->all();

        return $this->success(
            [
                'lancamentos' => $lancamentos,
                'status_options' => Financeiro::statusOptions(),
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
        ]);

        return $this->success(
            [
                'lancamento' => $financeiro,
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

        try {
            $financeiro = $this->financeiroService->update($financeiro, $request->validated());
        } catch (Throwable $exception) {
            return $this->error($exception->getMessage(), 422, 'FINANCEIRO_SAVE_FAILED', null, request: $request);
        }

        return $this->success(['lancamento' => $financeiro], request: $request);
    }

    public function destroy(Request $request, Financeiro $financeiro): JsonResponse
    {
        $this->authorize('financeiro:excluir');

        $this->financeiroService->delete($financeiro);

        return $this->success(null, request: $request);
    }

    public function pay(RegisterFinanceiroMovementRequest $request, Financeiro $financeiro): JsonResponse
    {
        $this->authorize('financeiro:editar');

        try {
            $resumo = $this->financeiroService->registerMovement($financeiro, $request->validated());
        } catch (Throwable $exception) {
            return $this->error($exception->getMessage(), 422, 'FINANCEIRO_BAIXA_FAILED', null, request: $request);
        }

        return $this->success(['resumo' => $resumo, 'lancamento' => $financeiro->refresh()], request: $request);
    }

    public function cancel(Request $request, Financeiro $financeiro): JsonResponse
    {
        $this->authorize('financeiro:editar');

        try {
            $financeiro = $this->financeiroService->cancel($financeiro);
        } catch (Throwable $exception) {
            return $this->error($exception->getMessage(), 422, 'FINANCEIRO_CANCEL_FAILED', null, request: $request);
        }

        return $this->success(['lancamento' => $financeiro], request: $request);
    }
}
