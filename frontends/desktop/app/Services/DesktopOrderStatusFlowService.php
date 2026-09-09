<?php

namespace App\Services;

class DesktopOrderStatusFlowService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    /** @return array{statuses: array<int, array<string, mixed>>, transitions: array<int, array<string, mixed>>} */
    public function index(): array
    {
        $response = $this->apiClient->get('/knowledge/os-flow');

        return [
            'statuses' => $response['data']['statuses'] ?? [],
            'transitions' => $response['data']['transitions'] ?? [],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function statusCatalog(): array
    {
        $response = $this->apiClient->get('/orders/status-catalog');

        return $response['data']['statuses'] ?? [];
    }

    /**
     * Estatisticas do fluxo real (frequencia das transicoes que aconteceram),
     * usadas pela "rota provavel" do Mapa da OS. Falha aqui nunca pode
     * derrubar o mapa: sem estatistica o mapa so deixa de desenhar a rota
     * sugerida, o trajeto percorrido e a posicao atual continuam.
     *
     * @return array<string, mixed>
     */
    public function flowStatistics(): array
    {
        try {
            $response = $this->apiClient->get('/knowledge/os-flow/estatisticas');
        } catch (\Throwable $exception) {
            report($exception);

            return [];
        }

        return is_array($response['data'] ?? null) ? $response['data'] : [];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createStatus(array $payload): array
    {
        $response = $this->apiClient->post('/knowledge/os-flow/statuses', $payload);

        return $response['data']['order_status'] ?? [];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function updateStatus(int $id, array $payload): array
    {
        $response = $this->apiClient->patch('/knowledge/os-flow/statuses/' . $id, $payload);

        return $response['data']['order_status'] ?? [];
    }
}
