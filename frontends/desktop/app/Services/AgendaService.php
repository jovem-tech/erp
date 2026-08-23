<?php

namespace App\Services;

class AgendaService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {}

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function list(array $filters): array
    {
        $response = $this->apiClient->get('/agenda', $filters);

        return $response['data'] ?? [];
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $response = $this->apiClient->get('/agenda/resumo');

        return $response['data'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $response = $this->apiClient->post('/agenda', $payload);

        return $response['data']['compromisso'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload): array
    {
        $response = $this->apiClient->patch('/agenda/'.$id, $payload);

        return $response['data']['compromisso'] ?? [];
    }

    /** @return array<string, mixed> */
    public function complete(int $id): array
    {
        $response = $this->apiClient->post('/agenda/'.$id.'/concluir', []);

        return $response['data']['compromisso'] ?? [];
    }

    /** @return array<string, mixed> */
    public function reopen(int $id): array
    {
        $response = $this->apiClient->post('/agenda/'.$id.'/reabrir', []);

        return $response['data']['compromisso'] ?? [];
    }

    public function delete(int $id): void
    {
        $this->apiClient->delete('/agenda/'.$id);
    }

    // -----------------------------------------------------------------
    // Integração Google Agenda
    // -----------------------------------------------------------------

    /** @return array<string, mixed> */
    public function googleStatus(): array
    {
        $response = $this->apiClient->get('/agenda/google/status');

        return $response['data'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveGoogleCredentials(array $payload): array
    {
        $response = $this->apiClient->post('/agenda/google/credenciais', $payload);

        return $response['data'] ?? [];
    }

    /** @return array<string, mixed> */
    public function googleAuthorizationUrl(): array
    {
        $response = $this->apiClient->post('/agenda/google/conectar', []);

        return $response['data'] ?? [];
    }

    /** @return array<string, mixed> */
    public function googleConnectManual(string $refreshToken): array
    {
        $response = $this->apiClient->post('/agenda/google/conectar-manual', [
            'refresh_token' => $refreshToken,
        ]);

        return $response['data'] ?? [];
    }

    /** @return array<string, mixed> */
    public function googleDisconnect(): array
    {
        $response = $this->apiClient->post('/agenda/google/desconectar', []);

        return $response['data'] ?? [];
    }

    /** @return array<string, mixed> */
    public function googleSyncNow(): array
    {
        $response = $this->apiClient->post('/agenda/google/sincronizar', []);

        return $response['data'] ?? [];
    }
}
