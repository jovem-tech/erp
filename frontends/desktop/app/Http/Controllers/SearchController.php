<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends DesktopController
{
    public function __construct(
        private readonly SearchService $searchService
    ) {
    }

    public function index(Request $request): View
    {
        $query = trim((string) $request->query('q', ''));
        $scope = $this->scopeFromRequest($request);
        $results = $this->searchService->search($query, $scope, 8);

        return view('search.index', [
            'pageTitle' => 'Busca completa',
            'query' => $query,
            'scope' => $results['scope'],
            'scopes' => $this->searchService->scopes(),
            'results' => $results,
        ]);
    }

    public function suggest(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        $scope = $this->scopeFromRequest($request);

        try {
            if (mb_strlen($query) < 2) {
                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'query' => $query,
                        'scope' => $scope,
                        'sections' => [],
                        'total' => 0,
                    ],
                ]);
            }

            return response()->json([
                'status' => 'success',
                'data' => $this->searchService->suggestions($query, $scope, 4),
            ]);
        } catch (ApiAuthenticationException $exception) {
            return response()->json([
                'status' => 'error',
                'error' => [
                    'message' => $exception->getMessage(),
                    'code' => 'AUTH_REQUIRED',
                ],
            ], 401);
        } catch (ApiAuthorizationException $exception) {
            return response()->json([
                'status' => 'error',
                'error' => [
                    'message' => $exception->getMessage(),
                    'code' => 'AUTH_FORBIDDEN',
                ],
            ], 403);
        } catch (ApiRequestException $exception) {
            return response()->json([
                'status' => 'error',
                'error' => [
                    'message' => $exception->getMessage(),
                    'code' => 'SEARCH_FAILED',
                ],
            ], max(400, $exception->getCode() ?: 500));
        }
    }

    /**
     * O parâmetro `scope` chega de duas formas conforme a origem: checkboxes reais
     * (`scope[]=os&scope[]=clientes`, viram array) na tela de busca completa, ou uma
     * string única separada por vírgula (`scope=os,clientes`) vinda do dropdown do
     * topbar, que guarda a seleção multi-escolha num só input hidden.
     *
     * @return string|array<int, string>
     */
    private function scopeFromRequest(Request $request): string|array
    {
        $scope = $request->query('scope', 'tudo');

        return is_array($scope) ? $scope : trim((string) $scope);
    }
}
