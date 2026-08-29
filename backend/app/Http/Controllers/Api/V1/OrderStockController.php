<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Order;
use App\Services\Estoque\OsAplicacaoPecaService;
use App\Services\Estoque\SaldoInsuficienteException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aplicacao de peca na OS.
 *
 * Controller proprio porque a autorizacao e dupla — mexe na OS E no estoque — e
 * o payload e da OS, nao do catalogo de pecas.
 *
 * E o caminho que faz o CMV deixar de ser zero: ate specs/038, nenhum fluxo do
 * sistema criava movimentacao de estoque a partir de uma Ordem de Servico.
 */
class OrderStockController extends BaseApiController
{
    public function __construct(
        private readonly OsAplicacaoPecaService $osAplicacaoPecaService
    ) {
    }

    public function show(Request $request, int $order): JsonResponse
    {
        $this->authorize('os:visualizar');

        $ordem = Order::query()->find($order);

        if (! $ordem instanceof Order) {
            return $this->error('Ordem de serviço não encontrada.', 404, 'OS_NAO_ENCONTRADA', null, request: $request);
        }

        return $this->success(
            ['estoque' => $this->osAplicacaoPecaService->contexto((int) $ordem->id)],
            request: $request
        );
    }

    public function apply(Request $request, int $order): JsonResponse
    {
        // Dupla: aplicar peça mexe no razão de estoque e no custo da OS.
        $this->authorize('os:editar');
        $this->authorize('estoque:editar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $ordem = Order::query()->find($order);

        if (! $ordem instanceof Order) {
            return $this->error('Ordem de serviço não encontrada.', 404, 'OS_NAO_ENCONTRADA', null, request: $request);
        }

        $validated = $request->validate([
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.peca_id' => ['required', 'integer', 'min:1'],
            'itens.*.quantidade' => ['required', 'numeric', 'min:0.0001'],
            'confirmar_estoque_insuficiente' => ['nullable', 'boolean'],
        ]);

        try {
            $resultado = $this->osAplicacaoPecaService->aplicar(
                $ordem,
                $validated['itens'],
                (int) $user->id,
                filter_var($validated['confirmar_estoque_insuficiente'] ?? false, FILTER_VALIDATE_BOOL)
            );
        } catch (SaldoInsuficienteException $exception) {
            return $this->error(
                'Estoque insuficiente para aplicar as peças informadas.',
                422,
                'OS_ESTOQUE_INSUFICIENTE',
                ['itens' => $exception->faltas()],
                request: $request
            );
        }

        return $this->success([
            'aplicacao' => $resultado,
            'estoque' => $this->osAplicacaoPecaService->contexto((int) $ordem->id),
        ], request: $request);
    }
}
