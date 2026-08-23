<?php

namespace App\Services;

/**
 * Proxy puro da API de backups.
 *
 * Sem regra de negócio aqui: o backend é a fonte da verdade (AGENTS.md).
 * Note que NÃO existe método de download — o pacote tem ~130 MB e o
 * ApiClient carrega o corpo inteiro em memória; o navegador busca o arquivo
 * direto do backend por URL assinada.
 */
class BackupService
{
    public function __construct(private readonly ApiClient $apiClient) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return (array) ($this->apiClient->get('/backups/resumo')['data'] ?? []);
    }

    /**
     * @param  array<string, scalar|null>  $filters
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, int>}
     */
    public function catalog(array $filters = []): array
    {
        $response = $this->apiClient->get('/backups', $filters);

        return [
            'items' => (array) ($response['data'] ?? []),
            'pagination' => (array) ($response['meta']['pagination'] ?? []),
        ];
    }

    /** @return array<string, mixed> */
    public function show(string $uuid): array
    {
        return (array) ($this->apiClient->get('/backups/'.rawurlencode($uuid))['data'] ?? []);
    }

    /** @return array<string, mixed> */
    public function generate(): array
    {
        return (array) ($this->apiClient->post('/backups')['data'] ?? []);
    }

    /** @return array<string, mixed> */
    public function scan(): array
    {
        return (array) ($this->apiClient->post('/backups/varrer')['data'] ?? []);
    }

    /** @return array<string, mixed> */
    public function verify(string $uuid): array
    {
        return (array) ($this->apiClient->post('/backups/'.rawurlencode($uuid).'/verificar')['data'] ?? []);
    }

    /** @return array<string, mixed> */
    public function downloadLink(string $uuid): array
    {
        return (array) ($this->apiClient->post('/backups/'.rawurlencode($uuid).'/link-download')['data'] ?? []);
    }

    /** @return array<string, mixed> */
    public function destroy(string $uuid): array
    {
        return (array) ($this->apiClient->delete('/backups/'.rawurlencode($uuid))['data'] ?? []);
    }

    /** @return array<string, mixed> */
    public function pin(string $uuid, bool $protegido): array
    {
        return (array) ($this->apiClient->post(
            '/backups/'.rawurlencode($uuid).'/proteger',
            ['protegido' => $protegido]
        )['data'] ?? []);
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return (array) ($this->apiClient->get('/backups/configuracoes')['data'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateSettings(array $payload): array
    {
        return (array) ($this->apiClient->put('/backups/configuracoes', $payload)['data'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function definePassphrase(array $payload): array
    {
        return (array) ($this->apiClient->post('/backups/frase-secreta', $payload)['data'] ?? []);
    }
}
