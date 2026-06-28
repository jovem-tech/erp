<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\OsMargem;
use App\Services\Financeiro\OsMargemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceiroMargemController extends BaseApiController
{
    public function __construct(
        private readonly OsMargemService $osMargemService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('financeiro:visualizar');

        $mes = $this->resolveMes($request);
        $filtros = $request->only(['cliente_id', 'tecnico_id']);

        return $this->success(
            ['margem' => $this->osMargemService->relatorioPorPeriodo($mes, $filtros)],
            request: $request
        );
    }

    public function show(Request $request, int $os): JsonResponse
    {
        $this->authorize('financeiro:visualizar');

        $registro = OsMargem::query()->where('os_id', $os)->first();

        return $this->success(
            ['margem' => $registro?->toArray()],
            request: $request
        );
    }

    public function recalcular(Request $request, int $os): JsonResponse
    {
        $this->authorize('financeiro:editar');

        return $this->success(
            ['margem' => $this->osMargemService->calcularParaOs($os)],
            request: $request
        );
    }

    private function resolveMes(Request $request): string
    {
        $mes = trim((string) $request->query('mes', ''));

        return preg_match('/^\d{4}-\d{2}$/', $mes) === 1 ? $mes : now()->format('Y-m');
    }
}
