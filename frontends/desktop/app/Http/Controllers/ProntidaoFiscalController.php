<?php

namespace App\Http\Controllers;

use App\Services\ProntidaoFiscalService;
use Illuminate\View\View;

/**
 * Quanto falta no cadastro para conseguir emitir nota fiscal (spec 041).
 */
class ProntidaoFiscalController extends DesktopController
{
    public function __construct(
        private readonly ProntidaoFiscalService $prontidaoFiscalService
    ) {
    }

    public function index(): View
    {
        return view('fiscal.prontidao', [
            'pageTitle' => 'Prontidão fiscal',
            'prontidao' => $this->prontidaoFiscalService->verificar(),
        ]);
    }
}
