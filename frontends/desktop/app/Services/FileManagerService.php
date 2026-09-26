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
     * @param  array<string, mixed>  $selection  ver FileManagerController::validatedSelection()
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function downloadBatch(array $selection): array
    {
        return $this->apiClient->postDownload('/files/download-batch', $selection);
    }

    /**
     * @param  array<string, mixed>  $selection  UUIDs marcados ou select_all + filtros
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    public function trashBatch(array $selection, array $payload): array
    {
        return (array) ($this->apiClient->postOnce('/files/trash-batch', array_merge($payload, $selection))['data'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $selection  UUIDs marcados ou select_all + filtros
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    public function restoreBatch(array $selection, array $payload): array
    {
        return (array) ($this->apiClient->postOnce('/files/restore-batch', array_merge($payload, $selection))['data'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $selection  UUIDs marcados ou select_all + filtros
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    public function purgeBatch(array $selection, array $payload): array
    {
        return (array) ($this->apiClient->postOnce('/files/purge-batch', array_merge($payload, $selection))['data'] ?? []);
    }

    /** @param array<string, string|int> $payload */
    public function updateTrashRetention(array $payload): array
    {
        return (array) ($this->apiClient->postOnce('/file-manager/trash-retention', $payload)['data'] ?? []);
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
