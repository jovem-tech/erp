<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

class ProfileService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function current(): array
    {
        $response = $this->apiClient->me();

        return $response['data'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateProfile(array $payload): array
    {
        $response = $this->apiClient->patch('/auth/me', $payload);

        return $response['data'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updatePassword(array $payload): array
    {
        $response = $this->apiClient->put('/auth/password', $payload);

        return $response['data'] ?? [];
    }

    /** @return array<string, mixed> */
    public function signatureStatus(): array
    {
        $response = $this->apiClient->get('/auth/signature');

        return $response['data'] ?? [];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function saveSignature(array $payload, ?UploadedFile $file = null): array
    {
        $files = $file instanceof UploadedFile ? ['signature_file' => [$file]] : [];
        $response = $this->apiClient->postMultipart('/auth/signature', $payload, $files);

        return $response['data'] ?? [];
    }

    /** @return array{body: string, headers: array<string, string>, status: int} */
    public function signatureImage(): array
    {
        return $this->apiClient->download('/auth/signature/image');
    }
}
