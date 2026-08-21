<?php

namespace App\Services;

/**
 * Cartões de crédito da assistência (usados para COMPRAR).
 *
 * Não confundir com FinanceiroCartaoService, que trata das operadoras/taxas
 * da maquininha (RECEBER do cliente).
 */
class FinanceiroCartaoCreditoService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function list(): array
    {
        $response = $this->apiClient->get('/financeiro/cartoes-credito');

        return $response['data']['cartoes'] ?? [];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function create(array $payload): array
    {
        $response = $this->apiClient->post('/financeiro/cartoes-credito', $payload);

        return $response['data']['cartao'] ?? [];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function update(int $cartaoId, array $payload): array
    {
        $response = $this->apiClient->patch('/financeiro/cartoes-credito/'.$cartaoId, $payload);

        return $response['data']['cartao'] ?? [];
    }

    /** @param array<string, mixed> $filtros @return array<string, mixed> */
    public function faturas(int $cartaoId, array $filtros = []): array
    {
        $response = $this->apiClient->get('/financeiro/cartoes-credito/'.$cartaoId.'/faturas', $filtros);

        return $response['data'] ?? [];
    }

    /** @return array<string, mixed> */
    public function fatura(int $cartaoId, string $dataVencimento): array
    {
        $response = $this->apiClient->get('/financeiro/cartoes-credito/'.$cartaoId.'/faturas/'.$dataVencimento);

        return $response['data'] ?? [];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function pagarFatura(int $cartaoId, string $dataVencimento, array $payload): array
    {
        $response = $this->apiClient->post(
            '/financeiro/cartoes-credito/'.$cartaoId.'/faturas/'.$dataVencimento.'/pagar',
            $payload
        );

        return $response['data']['resultado'] ?? [];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function lancarDespesaEsquecida(int $cartaoId, string $dataVencimento, array $payload): array
    {
        // postOnce: cria um lançamento. Um retry automático numa resposta que
        // falhou no meio do caminho duplicaria a despesa dentro da fatura.
        $response = $this->apiClient->postOnce(
            '/financeiro/cartoes-credito/'.$cartaoId.'/faturas/'.$dataVencimento.'/despesa-esquecida',
            $payload
        );

        return $response['data']['lancamento'] ?? [];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function cancelarBaixaFatura(int $cartaoId, string $dataVencimento, array $payload): array
    {
        // postOnce (sem retry): estorno é destrutivo — apaga os movimentos das
        // despesas. Repetir a chamada automaticamente numa resposta que falhou
        // no meio do caminho não pode ser opção.
        $response = $this->apiClient->postOnce(
            '/financeiro/cartoes-credito/'.$cartaoId.'/faturas/'.$dataVencimento.'/cancelar-baixa',
            $payload
        );

        return $response['data']['resultado'] ?? [];
    }

    /** @return array<string, mixed> */
    public function preverFatura(int $cartaoId, string $dataCompra): array
    {
        $response = $this->apiClient->get(
            '/financeiro/cartoes-credito/'.$cartaoId.'/prever-fatura',
            ['data_compra' => $dataCompra]
        );

        return $response['data']['fatura'] ?? [];
    }
}
