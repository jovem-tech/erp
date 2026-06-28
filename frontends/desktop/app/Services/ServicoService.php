<?php

namespace App\Services;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use Illuminate\Http\UploadedFile;

class ServicoService
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
        $response = $this->apiClient->get('/servicos', $filters);

        return [
            'items' => $response['data']['servicos'] ?? [],
            'pagination' => $response['meta']['pagination'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formData(): array
    {
        $response = $this->apiClient->get('/servicos/form-data');

        return $response['data']['form'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        $response = $this->apiClient->get('/servicos/' . $id);

        return $response['data']['servico'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $response = $this->apiClient->post('/servicos', $payload);

        return $response['data']['servico'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload): array
    {
        $response = $this->apiClient->patch('/servicos/' . $id, $payload);

        return $response['data']['servico'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function close(int $id): array
    {
        $response = $this->apiClient->patch('/servicos/' . $id . '/encerrar');

        return $response['data']['servico'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function destroy(int $id): array
    {
        $response = $this->apiClient->delete('/servicos/' . $id);

        return $response['data'] ?? [];
    }

    /**
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function exportCsv(): array
    {
        return $this->apiClient->download('/servicos/exportar-csv');
    }

    /**
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function downloadCsvTemplate(): array
    {
        return $this->apiClient->download('/servicos/modelo-importacao.csv');
    }

    /**
     * @return array<string, mixed>
     */
    public function importCsv(UploadedFile $file): array
    {
        $response = $this->apiClient->postMultipart('/servicos/importar-lote', [], [
            'arquivo' => [$file],
        ]);

        return $response['data'] ?? [];
    }
}
