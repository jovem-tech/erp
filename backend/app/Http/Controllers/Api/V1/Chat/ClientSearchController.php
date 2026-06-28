<?php

namespace App\Http\Controllers\Api\V1\Chat;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\User;
use App\Services\Chat\ChatClientLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientSearchController extends BaseApiController
{
    public function __construct(
        private readonly ChatClientLookupService $clientLookup
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        if (! $user instanceof User) {
            return $this->unauthenticatedResponse($request);
        }

        $this->authorize('atendimento_whatsapp:visualizar');

        $query = trim((string) $request->query('q', $request->query('search', '')));
        if ($query === '') {
            return $this->success(['clients' => []], request: $request);
        }

        $clients = $this->clientLookup->search($query)
            ->map(function ($client): array {
                $summary = $this->clientLookup->mapSummary($client);
                $summary['can_start_conversation'] = ! empty($summary['telefones']);

                return $summary;
            })
            ->values()
            ->all();

        return $this->success(['clients' => $clients], request: $request);
    }
}
