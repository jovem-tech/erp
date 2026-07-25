<?php

namespace App\Services;

class OrcamentoService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, mixed>, summary: array<string, mixed>, status_options: array<int, array<string, mixed>>}
     */
    public function paginate(array $filters = []): array
    {
        $response = $this->apiClient->get('/orcamentos', $filters);

        return [
            'items' => $response['data']['budgets'] ?? [],
            'pagination' => $response['meta']['pagination'] ?? [],
            'summary' => $response['data']['summary'] ?? [],
            'status_options' => $response['data']['status_options'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        $response = $this->apiClient->get('/orcamentos/'.$id);

        return $response['data']['budget'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function linkableForOrder(array $filters = []): array
    {
        $response = $this->apiClient->get('/orcamentos/vinculaveis-os', $filters);

        return [
            'items' => $response['data']['budgets'] ?? [],
            'pagination' => $response['meta']['pagination'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function findLinkableForOrder(int $id): array
    {
        $response = $this->apiClient->get('/orcamentos/vinculaveis-os/'.$id);

        return $response['data']['budget'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function formData(array $filters = []): array
    {
        $response = $this->apiClient->get('/orcamentos/form-data', $filters);

        return $response['data']['form'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function clientOptions(array $filters = []): array
    {
        $response = $this->apiClient->get('/orcamentos/clientes', $filters);

        return [
            'items' => $response['data']['clients'] ?? [],
            'pagination' => $response['meta']['pagination'] ?? [],
        ];
    }

    /**
     * OS abertas e equipamentos do cliente selecionado — usado para filtrar os
     * campos "OS vinculada" e "Equipamento cadastrado" do formulário conforme o
     * cliente escolhido no Select2.
     *
     * @return array{orders: array<int, array<string, mixed>>, equipments: array<int, array<string, mixed>>}
     */
    public function clientContext(int $clientId): array
    {
        $response = $this->apiClient->get('/orcamentos/cliente-contexto', ['cliente_id' => $clientId]);

        return [
            'orders' => $response['data']['orders'] ?? [],
            'equipments' => $response['data']['equipments'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $response = $this->apiClient->post('/orcamentos', $payload);

        return $response['data']['budget'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload): array
    {
        $response = $this->apiClient->patch('/orcamentos/'.$id, $payload);

        return $response['data']['budget'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sendForApproval(int $id, array $payload = []): array
    {
        $response = $this->apiClient->post('/orcamentos/'.$id.'/send-approval', $payload);

        return $response['data']['dispatch'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function approve(int $id, ?string $observacao = null): array
    {
        $response = $this->apiClient->post('/orcamentos/'.$id.'/aprovar', ['observacao' => $observacao]);

        return $response['data'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function reject(int $id, ?string $motivo = null): array
    {
        $response = $this->apiClient->post('/orcamentos/'.$id.'/rejeitar', ['motivo' => $motivo]);

        return $response['data'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(int $id, ?string $motivo = null): array
    {
        $response = $this->apiClient->post('/orcamentos/'.$id.'/cancelar', ['motivo' => $motivo]);

        return $response['data'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function destroy(int $id): array
    {
        $response = $this->apiClient->delete('/orcamentos/'.$id);

        return $response['data'] ?? [];
    }
}
