<?php

namespace App\Services;

class FileManagerService
{
    public function __construct(private readonly ApiClient $apiClient) {}

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        return (array) ($this->apiClient->get('/file-manager/dashboard')['data'] ?? []);
    }

    /** @return array<string, mixed> */
    public function requestSynchronization(): array
    {
        return (array) ($this->apiClient->post('/file-manager/sync')['data'] ?? []);
    }

    /**
     * @param  array<string, scalar|null>  $filters
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, int>}
     */
    public function catalog(array $filters): array
    {
        $response = $this->apiClient->get('/files', $filters);

        return [
            'items' => (array) ($response['data'] ?? []),
            'pagination' => (array) ($response['meta']['pagination'] ?? []),
        ];
    }

    /** @return array<string, mixed> */
    public function file(string $uuid): array
    {
        return (array) ($this->apiClient->get('/files/'.rawurlencode($uuid))['data'] ?? []);
    }

    /** @return array<int, array<string, mixed>> */
    public function findings(int $perPage = 10): array
    {
        return (array) ($this->apiClient->get('/file-manager/findings', [
            'per_page' => max(1, min(25, $perPage)),
            'resolution_status' => 'open',
        ])['data'] ?? []);
    }

    /**
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    public function mutate(string $uuid, string $action, array $payload): array
    {
        $allowed = ['archive', 'restore', 'quarantine', 'release-quarantine'];
        if (! in_array($action, $allowed, true)) {
            throw new \InvalidArgumentException('Ação de arquivo inválida.');
        }

        return (array) ($this->apiClient->post(
            '/files/'.rawurlencode($uuid).'/'.$action,
            $payload
        )['data'] ?? []);
    }

    /**
     * @param  array<int, string>  $uuids
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function downloadBatch(array $uuids): array
    {
        return $this->apiClient->postDownload('/files/download-batch', [
            'file_uuids' => array_values($uuids),
        ]);
    }

    /**
     * @param  array<int, string>  $uuids
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    public function trashBatch(array $uuids, array $payload): array
    {
        return (array) ($this->apiClient->post('/files/trash-batch', array_merge($payload, [
            'file_uuids' => array_values($uuids),
        ]))['data'] ?? []);
    }

    /** @return array{body: string, headers: array<string, string>, status: int} */
    public function download(string $uuid, bool $preview = false): array
    {
        return $this->apiClient->download(
            '/files/'.rawurlencode($uuid).($preview ? '/preview' : '/download')
        );
    }

    /** @return array{body: string, headers: array<string, string>, status: int} */
    public function thumbnail(string $uuid): array
    {
        return $this->apiClient->download('/files/'.rawurlencode($uuid).'/thumbnail');
    }
}
