<?php

namespace App\Http\Controllers;

use App\Services\AgendaService;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\DesktopSession;
use Illuminate\View\View;
use Throwable;

class DashboardController extends DesktopController
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly AgendaService $agendaService
    ) {
    }

    public function index(Request $request): View
    {
        return view('dashboard.index', [
            'pageTitle' => 'Dashboard',
            'agenda' => $this->agendaSummary(),
            'dashboard' => [
                'dataUrl' => route('dashboard.data'),
                'filters' => $this->dashboardService->bootstrapFilters($this->normalizeFilters($request)),
            ],
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        // A agenda entra aqui também, e não só no index: o painel "O que merece
        // sua atenção hoje" conta compromissos atrasados junto com os alertas de
        // OS, estoque e financeiro. Se o refresh por filtro devolvesse o painel
        // sem a agenda, o chip de atrasados sumiria ao trocar o ano.
        $agenda = $this->agendaSummary();

        return response()->json([
            'status' => 'success',
            'data' => $this->dashboardService->summary($this->normalizeFilters($request), $agenda),
            'error' => null,
            'meta' => [],
        ]);
    }

    /**
     * Resumo da agenda para o topo do painel. O dashboard é a primeira tela do
     * dia e não pode cair porque a agenda falhou — sem permissão ou com erro,
     * os itens de agenda simplesmente não aparecem.
     *
     * @return array<string, mixed>
     */
    private function agendaSummary(): array
    {
        if (! DesktopSession::can('agenda', 'visualizar')) {
            return [];
        }

        try {
            return $this->agendaService->summary();
        } catch (Throwable) {
            return [];
        }
    }

    public function help(): View
    {
        return view('dashboard.help', [
            'pageTitle' => 'Ajuda do dashboard',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeFilters(Request $request): array
    {
        return array_filter([
            'ano' => $request->query('ano'),
            'equip_mes' => $request->query('equip_mes'),
            'equip_ano' => $request->query('equip_ano'),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }
}
