<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Financeiro\FinanceiroReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceiroReportController extends BaseApiController
{
    public function __construct(
        private readonly FinanceiroReportService $financeiroReportService
    ) {
    }

    public function dre(Request $request): JsonResponse
    {
        $this->authorize('financeiro:visualizar');

        return $this->success(
            ['dre' => $this->financeiroReportService->dreReport($this->resolveMes($request))],
            request: $request
        );
    }

    public function dreCaixa(Request $request): JsonResponse
    {
        $this->authorize('financeiro:visualizar');

        return $this->success(
            ['dre' => $this->financeiroReportService->dreCashReport($this->resolveMes($request))],
            request: $request
        );
    }

    public function fluxoCaixa(Request $request): JsonResponse
    {
        $this->authorize('financeiro:visualizar');

        return $this->success(
            ['fluxo' => $this->financeiroReportService->cashFlowReport($this->resolveMes($request))],
            request: $request
        );
    }

    private function resolveMes(Request $request): string
    {
        $mes = trim((string) $request->query('mes', ''));

        return preg_match('/^\d{4}-\d{2}$/', $mes) === 1 ? $mes : now()->format('Y-m');
    }
}
