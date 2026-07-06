<?php

namespace App\Services;

class FinanceiroService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, mixed>, status_options: array<int, array<string, mixed>>}
     */
    public function paginate(array $filters = []): array
    {
        $response = $this->apiClient->get('/financeiro', $filters);

        return [
            'items' => $response['data']['lancamentos'] ?? [],
            'pagination' => $response['meta']['pagination'] ?? [],
            'status_options' => $response['data']['status_options'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        $response = $this->apiClient->get('/financeiro/' . $id);

        return $response['data'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $response = $this->apiClient->post('/financeiro', $payload);

        return $response['data']['lancamento'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload): array
    {
        $response = $this->apiClient->patch('/financeiro/' . $id, $payload);

        return $response['data']['lancamento'] ?? [];
    }

    public function destroy(int $id): void
    {
        $this->apiClient->delete('/financeiro/' . $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(int $id): array
    {
        $response = $this->apiClient->post('/financeiro/' . $id . '/cancelar');

        return $response['data']['lancamento'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function pay(int $id, array $payload): array
    {
        $response = $this->apiClient->post('/financeiro/' . $id . '/baixar', $payload);

        return $response['data'] ?? [];
    }

    /**
     * @return array{categorias: array<int, array<string, mixed>>, dre_grupos: array<int, array<string, mixed>>, dre_subgrupos: array<int, array<string, mixed>>, comissoes_tecnicos: array<int, array<string, mixed>>, comissao_percentual_padrao: float, cartao: array{operadoras: array<int, array<string, mixed>>, bandeiras: array<int, array<string, mixed>>, taxas: array<int, array<string, mixed>>}}
     */
    public function catalogo(): array
    {
        $response = $this->apiClient->get('/financeiro/catalogo');

        return [
            'categorias' => $response['data']['categorias'] ?? [],
            'dre_grupos' => $response['data']['dre_grupos'] ?? [],
            'dre_subgrupos' => $response['data']['dre_subgrupos'] ?? [],
            'comissoes_tecnicos' => $response['data']['comissoes_tecnicos'] ?? [],
            'comissao_percentual_padrao' => (float) ($response['data']['comissao_percentual_padrao'] ?? 0),
            'cartao' => [
                'operadoras' => $response['data']['cartao']['operadoras'] ?? [],
                'bandeiras' => $response['data']['cartao']['bandeiras'] ?? [],
                'taxas' => $response['data']['cartao']['taxas'] ?? [],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createCategoria(array $payload): void
    {
        $this->apiClient->post('/financeiro/categorias', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateCategoria(int $id, array $payload): void
    {
        $this->apiClient->patch('/financeiro/categorias/' . $id, $payload);
    }

    public function destroyCategoria(int $id): void
    {
        $this->apiClient->delete('/financeiro/categorias/' . $id);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createGrupo(array $payload): void
    {
        $this->apiClient->post('/financeiro/dre-grupos', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateGrupo(int $id, array $payload): void
    {
        $this->apiClient->patch('/financeiro/dre-grupos/' . $id, $payload);
    }

    public function destroyGrupo(int $id): void
    {
        $this->apiClient->delete('/financeiro/dre-grupos/' . $id);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createSubgrupo(array $payload): void
    {
        $this->apiClient->post('/financeiro/dre-subgrupos', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateSubgrupo(int $id, array $payload): void
    {
        $this->apiClient->patch('/financeiro/dre-subgrupos/' . $id, $payload);
    }

    public function destroySubgrupo(int $id): void
    {
        $this->apiClient->delete('/financeiro/dre-subgrupos/' . $id);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createComissao(array $payload): void
    {
        $this->apiClient->post('/financeiro/comissoes', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateComissao(int $id, array $payload): void
    {
        $this->apiClient->patch('/financeiro/comissoes/' . $id, $payload);
    }

    public function destroyComissao(int $id): void
    {
        $this->apiClient->delete('/financeiro/comissoes/' . $id);
    }

    public function updateComissaoPadrao(float $percentual): void
    {
        $this->apiClient->patch('/financeiro/comissoes-padrao', ['percentual_padrao' => $percentual]);
    }

    /**
     * @param array<string, mixed> $filtros
     * @return array<string, mixed>
     */
    public function margem(string $mes, array $filtros = []): array
    {
        $response = $this->apiClient->get('/financeiro/margem', array_merge(['mes' => $mes], $filtros));

        return $response['data']['margem'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function margemPorOs(int $os): array
    {
        $response = $this->apiClient->get('/financeiro/margem/' . $os);

        return $response['data']['margem'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function recalcularMargem(int $os): array
    {
        $response = $this->apiClient->post('/financeiro/margem/' . $os . '/recalcular');

        return $response['data']['margem'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function dre(string $mes): array
    {
        $response = $this->apiClient->get('/financeiro/relatorios/dre', ['mes' => $mes]);

        return $response['data']['dre'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function dreCaixa(string $mes): array
    {
        $response = $this->apiClient->get('/financeiro/relatorios/dre-caixa', ['mes' => $mes]);

        return $response['data']['dre'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function fluxoCaixa(string $mes): array
    {
        $response = $this->apiClient->get('/financeiro/relatorios/fluxo-caixa', ['mes' => $mes]);

        return $response['data']['fluxo'] ?? [];
    }
}
