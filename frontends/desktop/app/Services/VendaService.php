<?php

namespace App\Services;

/**
 * Vendas de balcão (PDV) — specs/027-vendas-balcao-pdv/spec.md.
 *
 * Única camada do desktop que fala com a API de vendas.
 */
class VendaService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, mixed>, summary: array<string, mixed>, status_options: array<int, array<string, mixed>>, status_pagamento_options: array<int, array<string, mixed>>}
     */
    public function paginate(array $filters = []): array
    {
        $response = $this->apiClient->get('/vendas', $filters);

        return [
            'items' => $response['data']['vendas'] ?? [],
            'pagination' => $response['meta']['pagination'] ?? [],
            'summary' => $response['data']['summary'] ?? [],
            'status_options' => $response['data']['status_options'] ?? [],
            'status_pagamento_options' => $response['data']['status_pagamento_options'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        $response = $this->apiClient->get('/vendas/' . $id);

        return $response['data']['venda'] ?? [];
    }

    /**
     * Catálogos do PDV numa chamada só (formas de pagamento, contas, cartões,
     * vendedores) — evita quatro round-trips na abertura da tela.
     *
     * @return array<string, mixed>
     */
    public function formData(): array
    {
        $response = $this->apiClient->get('/vendas/form-data');

        return $response['data']['form'] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchItems(string $term): array
    {
        $response = $this->apiClient->get('/vendas/itens/buscar', ['search' => $term]);

        return $response['data']['itens'] ?? [];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function clientOptions(array $filters = []): array
    {
        $response = $this->apiClient->get('/vendas/clientes', $filters);

        return [
            'items' => $response['data']['clients'] ?? [],
            'pagination' => $response['meta']['pagination'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        // postOnce e não post: post() tem retry automático e duplicaria a
        // venda. A chave de idempotência do payload é a segunda rede.
        $response = $this->apiClient->postOnce('/vendas', $payload);

        return $response['data'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function cancel(int $id, array $payload): array
    {
        $response = $this->apiClient->postOnce('/vendas/' . $id . '/cancelar', $payload);

        return $response['data']['venda'] ?? [];
    }

    /**
     * @return array{body: string, headers: array<string, mixed>, status: int}
     */
    public function receipt(int $id, string $formato = '80mm'): array
    {
        return $this->apiClient->download('/vendas/' . $id . '/comprovante', [
            'formato' => $formato,
        ]);
    }
}
