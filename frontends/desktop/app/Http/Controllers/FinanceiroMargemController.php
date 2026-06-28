<?php

namespace App\Http\Controllers;

use App\Services\FinanceiroService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinanceiroMargemController extends DesktopController
{
    public function __construct(
        private readonly FinanceiroService $financeiroService
    ) {
    }

    public function index(Request $request): View
    {
        $mes = $this->resolveMes($request);

        return view('financeiro.relatorios.margem', [
            'pageTitle' => 'Margem por OS',
            'mes' => $mes,
            'margem' => $this->financeiroService->margem($mes),
        ]);
    }

    private function resolveMes(Request $request): string
    {
        $mes = trim((string) $request->query('mes', ''));

        return preg_match('/^\d{4}-\d{2}$/', $mes) === 1 ? $mes : now()->format('Y-m');
    }
}
