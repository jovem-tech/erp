<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Dashboard\DashboardSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends BaseApiController
{
    public function __construct(
        private readonly DashboardSummaryService $dashboardSummaryService
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorize('dashboard:visualizar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        return $this->success(
            $this->dashboardSummaryService->build($user, $this->normalizeFilters($request)),
            request: $request
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeFilters(Request $request): array
    {
        return [
            'ano' => $request->query('ano'),
            'equip_mes' => $request->query('equip_mes'),
            'equip_ano' => $request->query('equip_ano'),
        ];
    }
}
