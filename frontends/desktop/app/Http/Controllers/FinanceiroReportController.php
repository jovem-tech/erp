<?php

namespace App\Http\Controllers;

use App\Services\FinanceiroService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinanceiroReportController extends DesktopController
{
    public function __construct(
        private readonly FinanceiroService $financeiroService
    ) {
    }

    public function dre(Request $request): View
    {
        $mes = $this->resolveMes($request);

        return view('financeiro.relatorios.dre', [
            'pageTitle' => 'DRE por competência',
            'mes' => $mes,
            'dre' => $this->financeiroService->dre($mes),
        ]);
    }

    public function dreCaixa(Request $request): View
    {
        $mes = $this->resolveMes($request);

        return view('financeiro.relatorios.dre', [
            'pageTitle' => 'DRE de caixa',
            'mes' => $mes,
            'dre' => $this->financeiroService->dreCaixa($mes),
            'caixa' => true,
        ]);
    }

    public function fluxoCaixa(Request $request): View
    {
        $mes = $this->resolveMes($request);

        return view('financeiro.relatorios.fluxo-caixa', [
            'pageTitle' => 'Fluxo de caixa',
            'mes' => $mes,
            'fluxo' => $this->financeiroService->fluxoCaixa($mes),
        ]);
    }

    private function resolveMes(Request $request): string
    {
        $mes = trim((string) $request->query('mes', ''));

        return preg_match('/^\d{4}-\d{2}$/', $mes) === 1 ? $mes : now()->format('Y-m');
    }
}
