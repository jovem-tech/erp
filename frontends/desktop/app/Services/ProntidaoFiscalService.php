<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

class ProntidaoFiscalService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function verificar(): array
    {
        $response = $this->apiClient->get('/fiscal/prontidao');

        return $response['data'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function instalarCertificado(UploadedFile $arquivo, string $senha): array
    {
        $response = $this->apiClient->postMultipart(
            '/fiscal/certificado',
            ['senha' => $senha],
            ['arquivo' => [$arquivo]]
        );

        return $response['data']['certificado'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function removerCertificado(): array
    {
        $response = $this->apiClient->delete('/fiscal/certificado');

        return $response['data']['certificado'] ?? [];
    }
}
