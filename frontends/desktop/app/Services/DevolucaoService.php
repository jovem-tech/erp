<?php

namespace App\Services;

/**
 * Devolução e troca de venda — specs/029-devolucao-troca/spec.md.
 */
class DevolucaoService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function paginate(array $filters = []): array
    {
        $response = $this->apiClient->get('/devolucoes', $filters);

        return [
            'items' => $response['data']['devolucoes'] ?? [],
            'pagination' => $response['meta']['pagination'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        $response = $this->apiClient->get('/devolucoes/'.$id);

        return $response['data']['devolucao'] ?? [];
    }

    /**
     * Saldo devolvível da venda: o que ainda pode voltar, por item.
     *
     * @return array<string, mixed>
     */
    public function returnableItems(int $vendaId): array
    {
        $response = $this->apiClient->get('/vendas/'.$vendaId.'/devolvivel');

        return $response['data'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(int $vendaId, array $payload): array
    {
        // postOnce e não post: retry automático duplicaria a devolução.
        $response = $this->apiClient->postOnce('/vendas/'.$vendaId.'/devolucoes', $payload);

        return $response['data'] ?? [];
    }

    /**
     * @return array{body: string, headers: array<string, mixed>, status: int}
     */
    public function receipt(int $id, string $formato = '80mm'): array
    {
        return $this->apiClient->download('/devolucoes/'.$id.'/comprovante', ['formato' => $formato]);
    }
}
