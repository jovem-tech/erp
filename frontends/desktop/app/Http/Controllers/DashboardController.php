<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends DesktopController
{
    public function __construct(
        private readonly DashboardService $dashboardService
    ) {
    }

    public function index(Request $request): View
    {
        return view('dashboard.index', [
            'pageTitle' => 'Dashboard',
            'dashboard' => [
                'dataUrl' => route('dashboard.data'),
                'filters' => $this->dashboardService->bootstrapFilters($this->normalizeFilters($request)),
            ],
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->dashboardService->summary($this->normalizeFilters($request)),
            'error' => null,
            'meta' => [],
        ]);
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
