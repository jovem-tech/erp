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

    /**
     * Taxonomia de estoque (Grupo → Categoria → Subcategoria). Grupos,
     * categorias e subcategorias abaixo alimentam o modal "Gerenciar
     * categorias" — sempre a lista completa (inclusive inativos), diferente
     * de formData(), que só traz os ativos para os selects de cadastro.
     *
     * @return array<int, array<string, mixed>>
     */
    public function grupos(): array
    {
        $response = $this->apiClient->get('/estoque/grupos');

        return $response['data']['grupos'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveGrupo(array $payload): array
    {
        $id = (int) ($payload['id'] ?? 0);
        $response = $id > 0
            ? $this->apiClient->patch('/estoque/grupos/' . $id, $payload)
            : $this->apiClient->post('/estoque/grupos', $payload);

        return $response['data']['grupo'] ?? [];
    }

    public function deactivateGrupo(int $id): void
    {
        $this->apiClient->delete('/estoque/grupos/' . $id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function categorias(): array
    {
        $response = $this->apiClient->get('/estoque/categorias');

        return $response['data']['categorias'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveCategoria(array $payload): array
    {
        $id = (int) ($payload['id'] ?? 0);
        $response = $id > 0
            ? $this->apiClient->patch('/estoque/categorias/' . $id, $payload)
            : $this->apiClient->post('/estoque/categorias', $payload);

        return $response['data']['categoria'] ?? [];
    }

    public function deactivateCategoria(int $id): void
    {
        $this->apiClient->delete('/estoque/categorias/' . $id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function subcategorias(): array
    {
        $response = $this->apiClient->get('/estoque/subcategorias');

        return $response['data']['subcategorias'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveSubcategoria(array $payload): array
    {
        $id = (int) ($payload['id'] ?? 0);
        $response = $id > 0
            ? $this->apiClient->patch('/estoque/subcategorias/' . $id, $payload)
            : $this->apiClient->post('/estoque/subcategorias', $payload);

        return $response['data']['subcategoria'] ?? [];
    }

    public function deactivateSubcategoria(int $id): void
    {
        $this->apiClient->delete('/estoque/subcategorias/' . $id);
    }
}
