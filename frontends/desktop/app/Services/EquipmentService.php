<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

class EquipmentService
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
        $response = $this->apiClient->get('/equipments', $filters);

        return [
            'items' => $response['data']['equipments'] ?? [],
            'pagination' => $response['meta']['pagination'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        $response = $this->apiClient->get('/equipments/' . $id);

        return $response['data']['equipment'] ?? [];
    }

    /**
     * Revela a senha de acesso do equipamento mediante step-up de
     * administrador (ver skill sistema-erp-autenticacao-step-up).
     */
    public function revealPassword(int $id, string $adminEmail, string $adminPassword): string
    {
        $response = $this->apiClient->post('/equipments/' . $id . '/reveal-password', [
            'admin_email' => $adminEmail,
            'admin_password' => $adminPassword,
        ]);

        return (string) ($response['data']['senha_acesso'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function formData(): array
    {
        $response = $this->apiClient->get('/equipments/form-data');

        return $response['data']['form'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, UploadedFile> $photos
     * @return array<string, mixed>
     */
    public function create(array $payload, array $photos = []): array
    {
        $response = $this->apiClient->postMultipart('/equipments', $payload, [
            'fotos[]' => $photos,
        ]);

        return $response['data']['equipment'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, UploadedFile> $photos
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload, array $photos = []): array
    {
        $response = $this->apiClient->postMultipart('/equipments/' . $id, array_merge($payload, [
            '_method' => 'PATCH',
        ]), [
            'fotos[]' => $photos,
        ]);

        return $response['data']['equipment'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createBrand(array $payload): array
    {
        $response = $this->apiClient->post('/equipments/brands', $payload);

        return $response['data']['brand'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createModel(array $payload): array
    {
        $response = $this->apiClient->post('/equipments/models', $payload);

        return $response['data']['model'] ?? [];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<int, array<string, mixed>>
     */
    public function suggestModels(array $query): array
    {
        $response = $this->apiClient->get('/equipments/models/suggestions', $query);

        return $response['data']['suggestions'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function readLocalCollectorSnapshot(): array
    {
        $response = $this->apiClient->get('/equipments/collector/local-snapshot');

        return $response['data']['collector'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function collectLocalCollectorSnapshot(): array
    {
        $response = $this->apiClient->post('/equipments/collector/local-collect');

        return $response['data']['collector'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function createCollectorPairing(): array
    {
        $response = $this->apiClient->post('/equipments/collector-pairings');

        $pairing = is_array($response['data']['pairing'] ?? null) ? $response['data']['pairing'] : [];
        $pairing['submission_token'] = (string) ($response['data']['submission_token'] ?? '');

        return $pairing;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCollectorPairing(string $code): array
    {
        $response = $this->apiClient->get('/equipments/collector-pairings/' . rawurlencode($code));

        return $response['data']['pairing'] ?? [];
    }

    /**
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function downloadPhoto(int $equipmentId, int $photoId): array
    {
        return $this->apiClient->download('/equipments/' . $equipmentId . '/photos/' . $photoId);
    }

    /**
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function downloadWindowsCollectorPackage(string $pairingCode): array
    {
        return $this->apiClient->download('/equipments/collector-pairings/' . rawurlencode($pairingCode) . '/download/windows');
    }
}
