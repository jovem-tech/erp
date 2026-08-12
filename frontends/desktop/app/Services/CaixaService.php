<?php

namespace App\Services;

/**
 * Turnos de caixa — specs/028-caixa-sessoes/spec.md.
 */
class CaixaService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    /**
     * Estado atual: conta, sessão aberta (se houver) e contas de destino para
     * sangria. Enquanto o caixa está aberto a resposta NÃO traz o valor
     * esperado — a conferência é cega.
     *
     * @return array<string, mixed>
     */
    public function current(): array
    {
        $response = $this->apiClient->get('/caixa/atual');

        return $response['data'] ?? [];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function paginate(array $filters = []): array
    {
        $response = $this->apiClient->get('/caixa', $filters);

        return [
            'items' => $response['data']['sessoes'] ?? [],
            'pagination' => $response['meta']['pagination'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        $response = $this->apiClient->get('/caixa/'.$id);

        return $response['data']['sessao'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function open(array $payload): array
    {
        $response = $this->apiClient->postOnce('/caixa/abrir', $payload);

        return $response['data']['sessao'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function storeMovement(int $id, array $payload): array
    {
        $response = $this->apiClient->postOnce('/caixa/'.$id.'/movimentos', $payload);

        return $response['data']['sessao'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateOpening(int $id, array $payload): array
    {
        $response = $this->apiClient->patch('/caixa/'.$id.'/abertura', $payload);

        return $response['data']['sessao'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function close(int $id, array $payload): array
    {
        $response = $this->apiClient->postOnce('/caixa/'.$id.'/fechar', $payload);

        return $response['data']['sessao'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function reopen(int $id, array $payload): array
    {
        $response = $this->apiClient->postOnce('/caixa/'.$id.'/reabrir', $payload);

        return $response['data']['sessao'] ?? [];
    }

    /**
     * @return array{body: string, headers: array<string, mixed>, status: int}
     */
    public function report(int $id, string $formato = '80mm'): array
    {
        return $this->apiClient->download('/caixa/'.$id.'/relatorio', ['formato' => $formato]);
    }
}
