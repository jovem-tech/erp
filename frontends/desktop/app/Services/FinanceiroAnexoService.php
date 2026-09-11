<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

class FinanceiroAnexoService
{
    public function __construct(private readonly ApiClient $apiClient) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listar(int $financeiro): array
    {
        $response = $this->apiClient->get('/financeiro/' . $financeiro . '/anexos');

        return $response['data']['anexos'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function anexar(int $financeiro, UploadedFile $arquivo, ?string $descricao = null): array
    {
        return $this->apiClient->postMultipart(
            '/financeiro/' . $financeiro . '/anexos',
            array_filter(['descricao' => $descricao], static fn ($valor) => filled($valor)),
            ['arquivo' => [$arquivo]]
        );
    }

    /**
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function baixar(int $financeiro, int $anexo): array
    {
        return $this->apiClient->download('/financeiro/' . $financeiro . '/anexos/' . $anexo . '/download');
    }

    public function excluir(int $financeiro, int $anexo): void
    {
        $this->apiClient->delete('/financeiro/' . $financeiro . '/anexos/' . $anexo);
    }
}
