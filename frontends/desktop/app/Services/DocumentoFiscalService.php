<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

class DocumentoFiscalService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pendentes(int $limite = 100): array
    {
        $response = $this->apiClient->get('/fiscal/pendentes', ['limite' => $limite]);

        return $response['data']['ordens'] ?? [];
    }

    /**
     * Notas já registradas, com paginação e totais.
     *
     * @param  array<string, mixed>  $filtros
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, mixed>, totais: array<string, mixed>}
     */
    public function listar(array $filtros = []): array
    {
        $response = $this->apiClient->get('/fiscal/documentos', $filtros);

        return [
            'items' => $response['data']['documentos'] ?? [],
            'pagination' => $response['meta']['pagination'] ?? [],
            'totais' => $response['meta']['totais'] ?? [],
        ];
    }

    /**
     * Monta (ou recupera) o rascunho da OS. Idempotente no backend: chamar de
     * novo devolve o mesmo rascunho, nunca um segundo.
     *
     * @return array<string, mixed>
     */
    public function rascunhoDeOrdem(int $order): array
    {
        return $this->rascunhoComContexto($order)['documento'] ?? [];
    }

    /**
     * Rascunho MAIS o contexto de emissao (ambiente e disponibilidade do
     * certificado).
     *
     * O desktop e' uma aplicacao separada e nao le' `config/fiscal.php` do
     * backend — sem este atalho a tela nao teria como avisar que a emissao sai
     * em homologacao, nem por que o botao esta' indisponivel. Uma chamada so',
     * a mesma que ja' abre o rascunho.
     *
     * @return array<string, mixed>
     */
    public function rascunhoComContexto(int $order): array
    {
        $response = $this->apiClient->post('/orders/' . $order . '/documento-fiscal', []);

        return $response['data'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    /**
     * Abre documento novo para a OS depois que a nota anterior foi cancelada.
     *
     * @return array<string, mixed>
     */
    public function novoDocumento(int $order): array
    {
        $response = $this->apiClient->post('/orders/' . $order . '/documento-fiscal/novo', []);

        return $response['data']['documento'] ?? [];
    }

    /**
     * Envia a nota ao cliente por e-mail ou WhatsApp.
     *
     * @return array<string, mixed>
     */
    public function enviar(int $documento, string $canal, string $destino, ?string $mensagem = null): array
    {
        $response = $this->apiClient->post('/fiscal/documentos/' . $documento . '/envio', array_filter([
            'canal' => $canal,
            'destino' => $destino,
            'mensagem' => $mensagem,
        ], static fn ($valor) => $valor !== null && $valor !== ''));

        return $response['data']['envio'] ?? [];
    }

    public function registrarEmissao(int $documento, array $payload): array
    {
        $response = $this->apiClient->post('/fiscal/documentos/' . $documento . '/emissao', $payload);

        return $response['data']['documento'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function registrarRejeicao(int $documento, string $motivo): array
    {
        $response = $this->apiClient->post('/fiscal/documentos/' . $documento . '/rejeicao', [
            'motivo_rejeicao' => $motivo,
        ]);

        return $response['data']['documento'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelar(int $documento, string $motivo): array
    {
        $response = $this->apiClient->post('/fiscal/documentos/' . $documento . '/cancelamento', [
            'motivo_cancelamento' => $motivo,
        ]);

        return $response['data']['documento'] ?? [];
    }

    /**
     * Importa o XML do portal e registra a emissão a partir dele.
     *
     * @return array<string, mixed>
     */
    public function importarXml(int $documento, UploadedFile $arquivo): array
    {
        $response = $this->apiClient->postMultipart(
            '/fiscal/documentos/' . $documento . '/importar-xml',
            [],
            ['arquivo' => [$arquivo]]
        );

        return $response['data'] ?? [];
    }

    /**
     * Emite a NFS-e pelo proprio sistema, transmitindo ao Ambiente Nacional.
     *
     * Sincrono de proposito: o endpoint do governo responde com a nota pronta,
     * e o operador precisa ver o numero na volta. O page loader do desktop e'
     * quem segura a espera.
     *
     * @return array<string, mixed>
     */
    public function emitirPeloSistema(int $documento): array
    {
        $response = $this->apiClient->post('/fiscal/documentos/' . $documento . '/emitir');

        return $response['data'] ?? [];
    }

    /**
     * Estado do ambiente de emissao (homologacao/producao).
     *
     * @return array<string, mixed>
     */
    public function ambienteFiscal(): array
    {
        return $this->apiClient->get('/fiscal/ambiente')['data'] ?? [];
    }

    /**
     * Troca o ambiente de emissao.
     *
     * @return array<string, mixed>
     */
    public function alterarAmbienteFiscal(int $ambiente): array
    {
        return $this->apiClient->post('/fiscal/ambiente', ['ambiente' => $ambiente])['data'] ?? [];
    }

    /**
     * DANFSe reconstruído a partir do XML, para quando o PDF oficial não foi
     * anexado.
     *
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function danfse(int $documento): array
    {
        return $this->apiClient->download('/fiscal/documentos/' . $documento . '/danfse');
    }

    /**
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function baixarArquivo(int $documento, string $formato): array
    {
        return $this->apiClient->download('/fiscal/documentos/' . $documento . '/arquivo/' . $formato);
    }

    /**
     * @return array<string, mixed>
     */
    public function anexarArquivo(int $documento, UploadedFile $arquivo, string $formato): array
    {
        $response = $this->apiClient->postMultipart('/fiscal/documentos/' . $documento . '/arquivo', [
            'formato' => $formato,
        ], ['arquivo' => [$arquivo]]);

        return $response['data']['documento'] ?? [];
    }
}
