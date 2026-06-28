<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

class StockService
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
        $response = $this->apiClient->get('/estoque', $filters);

        return [
            'items' => $response['data']['pecas'] ?? [],
            'pagination' => $response['meta']['pagination'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formData(): array
    {
        $response = $this->apiClient->get('/estoque/form-data');

        return $response['data']['form'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        $response = $this->apiClient->get('/estoque/' . $id);

        return $response['data']['peca'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function lowStock(): array
    {
        $response = $this->apiClient->get('/estoque/baixo');

        return $response['data']['pecas'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function movements(int $id): array
    {
        $response = $this->apiClient->get('/estoque/' . $id . '/movimentacoes');

        return [
            'part' => $response['data']['peca'] ?? [],
            'movements' => $response['data']['movimentacoes'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $response = $this->apiClient->post('/estoque', $payload);

        return $response['data']['peca'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload): array
    {
        $response = $this->apiClient->patch('/estoque/' . $id, $payload);

        return $response['data']['peca'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createMovement(int $id, array $payload): array
    {
        $response = $this->apiClient->post('/estoque/' . $id . '/movimentacoes', $payload);

        return $response['data']['peca'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function close(int $id): array
    {
        $response = $this->apiClient->patch('/estoque/' . $id . '/encerrar');

        return $response['data']['peca'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function destroy(int $id): array
    {
        $response = $this->apiClient->delete('/estoque/' . $id);

        return $response['data'] ?? [];
    }

    /**
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function exportCsv(): array
    {
        return $this->apiClient->download('/estoque/exportar-csv');
    }

    /**
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function downloadCsvTemplate(): array
    {
        return $this->apiClient->download('/estoque/modelo-importacao.csv');
    }

    /**
     * @return array<string, mixed>
     */
    public function importCsv(UploadedFile $file): array
    {
        $response = $this->apiClient->postMultipart('/estoque/importar-lote', [], [
            'arquivo' => [$file],
        ]);

        return $response['data'] ?? [];
    }
}
