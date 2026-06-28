<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Auth\RbacAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends BaseApiController
{
    public function __construct(
        private readonly RbacAuthorizationService $rbacAuthorizationService
    ) {
    }

    public function modules(Request $request): JsonResponse
    {
        $this->authorize('grupos:visualizar');

        return $this->success(
            ['modules' => $this->rbacAuthorizationService->activeModulesCatalog()],
            request: $request
        );
    }

    public function permissions(Request $request): JsonResponse
    {
        $this->authorize('grupos:visualizar');

        return $this->success(
            ['permissions' => $this->rbacAuthorizationService->permissionsCatalog()],
            request: $request
        );
    }
}
