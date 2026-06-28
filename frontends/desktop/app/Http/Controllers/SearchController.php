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
        $scope = trim((string) $request->query('scope', 'tudo'));

        return view('search.index', [
            'pageTitle' => 'Busca completa',
            'query' => $query,
            'scope' => $scope,
            'scopes' => $this->searchService->scopes(),
            'results' => $this->searchService->search($query, $scope, 8),
        ]);
    }

    public function suggest(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        $scope = trim((string) $request->query('scope', 'tudo'));

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
}
