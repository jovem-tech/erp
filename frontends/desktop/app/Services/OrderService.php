<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

class OrderService
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
        $response = $this->apiClient->get('/orders', $filters);

        return [
            'items' => $response['data']['orders'] ?? [],
            'pagination' => $response['meta']['pagination'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        $response = $this->apiClient->get('/orders/' . $id);

        return $response['data']['order'] ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function entryChecklistModel(int $tipoEquipamentoId): ?array
    {
        if ($tipoEquipamentoId <= 0) {
            return null;
        }

        $response = $this->apiClient->get('/orders/checklists/entrada/modelos/' . $tipoEquipamentoId);
        $modelo = $response['data']['modelo'] ?? null;

        return is_array($modelo) ? $modelo : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, UploadedFile> $photos
     * @return array<string, mixed>
     */
    public function create(array $payload, array $photos = []): array
    {
        if ($photos !== []) {
            $response = $this->apiClient->postMultipart('/orders', $payload, [
                'fotos[]' => $photos,
            ]);
        } else {
            $response = $this->apiClient->post('/orders', $payload);
        }

        return $response['data']['order'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, UploadedFile> $photos
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload, array $photos = []): array
    {
        if ($photos !== []) {
            $response = $this->apiClient->postMultipart('/orders/' . $id, array_merge($payload, [
                '_method' => 'PATCH',
            ]), [
                'fotos[]' => $photos,
            ]);
        } else {
            $response = $this->apiClient->patch('/orders/' . $id, $payload);
        }

        return $response['data']['order'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function closureMetadata(int $id): array
    {
        $response = $this->apiClient->get('/orders/' . $id . '/closure');

        return $response['data'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function close(int $id, array $payload): array
    {
        $response = $this->apiClient->post('/orders/' . $id . '/closure', $payload);

        return $response['data'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelClosure(int $id, string $adminEmail, string $adminPassword): array
    {
        $response = $this->apiClient->post('/orders/' . $id . '/closure/cancel', [
            'admin_email' => $adminEmail,
            'admin_password' => $adminPassword,
        ]);

        return $response['data'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateStatus(
        int $id,
        ?string $status = null,
        ?string $observacao = null,
        ?string $diagnosticoTecnico = null,
        ?string $solucaoAplicada = null,
        bool $comunicarCliente = false
    ): array {
        $payload = ['comunicar_cliente' => $comunicarCliente];
        if ($status !== null && $status !== '') {
            $payload['status'] = $status;
        }
        if ($observacao !== null && $observacao !== '') {
            $payload['observacao'] = $observacao;
        }
        if ($diagnosticoTecnico !== null) {
            $payload['diagnostico_tecnico'] = $diagnosticoTecnico;
        }
        if ($solucaoAplicada !== null) {
            $payload['solucao_aplicada'] = $solucaoAplicada;
        }

        $response = $this->apiClient->patch('/orders/' . $id . '/status', $payload);

        return $response['data']['order'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function addProcedure(int $id, string $descricao): array
    {
        $response = $this->apiClient->post('/orders/' . $id . '/procedures', ['descricao' => $descricao]);

        return $response['data']['order'] ?? [];
    }

    /**
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function downloadPhoto(int $orderId, int $photoId): array
    {
        return $this->apiClient->download('/orders/' . $orderId . '/photos/' . $photoId);
    }

    /**
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function downloadDocument(int $orderId, int $documentId): array
    {
        return $this->apiClient->download('/orders/' . $orderId . '/documents/' . $documentId);
    }
}
