<?php

namespace App\Services;

class FinanceiroContaService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {}

    /** @return array<string, mixed> */
    public function dashboard(string $month): array
    {
        $response = $this->apiClient->get('/financeiro/contas', ['mes' => $month]);

        return $response['data'] ?? [];
    }

    /** @param array<string, mixed> $payload */
    public function create(array $payload): array
    {
        $response = $this->apiClient->post('/financeiro/contas', $payload);

        return $response['data']['conta'] ?? [];
    }

    /** @param array<string, mixed> $payload */
    public function update(int $accountId, array $payload): array
    {
        $response = $this->apiClient->patch('/financeiro/contas/'.$accountId, $payload);

        return $response['data']['conta'] ?? [];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function statement(int $accountId, array $filters): array
    {
        $response = $this->apiClient->get('/financeiro/contas/'.$accountId.'/extrato', $filters);

        return $response['data'] ?? [];
    }

    /** @param array<string, mixed> $payload */
    public function adjust(int $accountId, array $payload): array
    {
        $response = $this->apiClient->post('/financeiro/contas/'.$accountId.'/ajustes', $payload);

        return $response['data']['movimento'] ?? [];
    }

    /** @param array<string, mixed> $payload */
    public function transfer(array $payload): array
    {
        $response = $this->apiClient->post('/financeiro/contas-transferencias', $payload);

        return $response['data']['transferencia'] ?? [];
    }

    public function cancelTransfer(int $transferId, string $reason): array
    {
        $response = $this->apiClient->post('/financeiro/contas-transferencias/'.$transferId.'/cancelar', [
            'motivo' => $reason,
        ]);

        return $response['data']['transferencia'] ?? [];
    }

    public function confirmCard(int $cardId, string $creditDate): array
    {
        $response = $this->apiClient->post('/financeiro/contas-cartoes/'.$cardId.'/confirmar', [
            'data_credito_efetivo' => $creditDate,
        ]);

        return $response['data']['cartao'] ?? [];
    }
}
