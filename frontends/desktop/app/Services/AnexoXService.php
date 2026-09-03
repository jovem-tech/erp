<?php

namespace App\Services;

/**
 * Anexo X — Relatório Mensal das Receitas Brutas (Res. CGSN 140/2018, art. 106).
 *
 * Só transporte: toda a apuração vive no backend, que é a fonte de verdade de
 * negócio. Nenhuma query daqui.
 */
class AnexoXService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    /**
     * Os doze meses do ano nos dois regimes — o que a tabela da tela consome.
     *
     * @return array<string, mixed>
     */
    public function resumoAnual(int $ano): array
    {
        $response = $this->apiClient->get('/fiscal/anexo-x/resumo', ['ano' => $ano]);

        return $response['data']['resumo'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function ajustes(string $competencia, string $regime): array
    {
        $response = $this->apiClient->get('/fiscal/anexo-x/ajustes', [
            'competencia' => $competencia,
            'regime' => $regime,
        ]);

        return $response['data'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function lancarAjuste(array $payload): array
    {
        $response = $this->apiClient->post('/fiscal/anexo-x/ajustes', $payload);

        return $response['data'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelarAjuste(int $ajuste, string $motivo): array
    {
        $response = $this->apiClient->post('/fiscal/anexo-x/ajustes/'.$ajuste.'/cancelamento', [
            'motivo' => $motivo,
        ]);

        return $response['data'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function relatorio(string $competencia, string $regime, bool $reconferir = false): array
    {
        $response = $this->apiClient->get('/fiscal/anexo-x', array_filter([
            'competencia' => $competencia,
            'regime' => $regime,
            'reconferir' => $reconferir ? '1' : null,
        ]));

        return $response['data']['anexo_x'] ?? [];
    }

    /**
     * Bytes do formulário oficial.
     *
     * @return array<string, mixed>
     */
    public function pdf(string $competencia, string $regime): array
    {
        return $this->apiClient->download('/fiscal/anexo-x/pdf', [
            'competencia' => $competencia,
            'regime' => $regime,
        ]);
    }

    /**
     * Bytes do bloco anual — uma folha por mês do ano-calendário.
     *
     * @return array<string, mixed>
     */
    public function pdfAnual(int $ano, string $regime): array
    {
        return $this->apiClient->download('/fiscal/anexo-x/pdf', [
            'ano' => $ano,
            'regime' => $regime,
        ]);
    }

    /**
     * Bytes da relação de documentos emitidos — arquivo SEPARADO do formulário.
     *
     * @return array<string, mixed>
     */
    public function documentosPdf(string $competencia): array
    {
        return $this->apiClient->download('/fiscal/anexo-x/documentos/pdf', [
            'competencia' => $competencia,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function fechar(string $competencia, string $regime): array
    {
        $response = $this->apiClient->post('/fiscal/anexo-x/fechamento', [
            'competencia' => $competencia,
            'regime' => $regime,
        ]);

        return $response['data'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function reabrir(string $competencia, string $regime, string $motivo, string $adminEmail, string $adminPassword): array
    {
        $response = $this->apiClient->post('/fiscal/anexo-x/fechamento/reabertura', [
            'competencia' => $competencia,
            'regime' => $regime,
            'motivo' => $motivo,
            'admin_email' => $adminEmail,
            'admin_password' => $adminPassword,
        ]);

        return $response['data'] ?? [];
    }
}
