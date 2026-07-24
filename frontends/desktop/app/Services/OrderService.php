<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

class OrderService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function paginate(array $filters = []): array
    {
        $response = $this->apiClient->get('/orders', $filters);

        return [
            'items' => $response['data']['orders'] ?? [],
            'pagination' => $response['meta']['pagination'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        $response = $this->apiClient->get('/orders/'.$id);

        return $response['data']['order'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{order: array<string, mixed>, events: array<int, array<string, mixed>>, stats: array<string, mixed>, pagination: array<string, mixed>}
     */
    public function auditTrail(int $id, array $filters = []): array
    {
        $response = $this->apiClient->get('/orders/'.$id.'/events', $filters);

        return [
            'order' => is_array($response['data']['order'] ?? null) ? $response['data']['order'] : [],
            'events' => is_array($response['data']['events'] ?? null) ? $response['data']['events'] : [],
            'stats' => is_array($response['data']['stats'] ?? null) ? $response['data']['stats'] : [],
            'pagination' => is_array($response['meta']['pagination'] ?? null) ? $response['meta']['pagination'] : [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function entryChecklistModel(int $tipoEquipamentoId): ?array
    {
        if ($tipoEquipamentoId <= 0) {
            return null;
        }

        $response = $this->apiClient->get('/orders/checklists/entrada/modelos/'.$tipoEquipamentoId);
        $modelo = $response['data']['modelo'] ?? null;

        return is_array($modelo) ? $modelo : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, UploadedFile>  $photos
     * @param  array<int, UploadedFile>  $equipmentPhotos  Fotos do equipamento novo
     *                                                     (criação diferida), criadas junto com a OS.
     * @return array<string, mixed>
     */
    public function create(array $payload, array $photos = [], array $equipmentPhotos = []): array
    {
        if ($photos !== [] || $equipmentPhotos !== []) {
            $files = [];
            if ($photos !== []) {
                $files['fotos[]'] = $photos;
            }
            if ($equipmentPhotos !== []) {
                $files['novo_equipamento_fotos[]'] = $equipmentPhotos;
            }

            $response = $this->apiClient->postMultipart('/orders', $payload, $files);
        } else {
            $response = $this->apiClient->post('/orders', $payload);
        }

        return $response['data'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, UploadedFile>  $photos
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload, array $photos = []): array
    {
        if ($photos !== []) {
            $response = $this->apiClient->postMultipart('/orders/'.$id, array_merge($payload, [
                '_method' => 'PATCH',
            ]), [
                'fotos[]' => $photos,
            ]);
        } else {
            $response = $this->apiClient->patch('/orders/'.$id, $payload);
        }

        return $response['data']['order'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function closureMetadata(int $id): array
    {
        $response = $this->apiClient->get('/orders/'.$id.'/closure');

        return $response['data'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function close(int $id, array $payload): array
    {
        $response = $this->apiClient->post('/orders/'.$id.'/closure', $payload);

        return $response['data'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelClosure(int $id, string $adminEmail, string $adminPassword): array
    {
        $response = $this->apiClient->post('/orders/'.$id.'/closure/cancel', [
            'admin_email' => $adminEmail,
            'admin_password' => $adminPassword,
        ]);

        return $response['data'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateStatus(
        int $id,
        ?string $status = null,
        ?string $observacao = null,
        ?string $diagnosticoTecnico = null,
        ?string $solucaoAplicada = null,
        bool $comunicarCliente = false,
        ?string $novoPrazo = null
    ): array {
        $payload = ['comunicar_cliente' => $comunicarCliente];
        if ($status !== null && $status !== '') {
            $payload['status'] = $status;
        }
        if ($observacao !== null && $observacao !== '') {
            $payload['observacao'] = $observacao;
        }
        if ($diagnosticoTecnico !== null) {
            $payload['diagnostico_tecnico'] = $diagnosticoTecnico;
        }
        if ($solucaoAplicada !== null) {
            $payload['solucao_aplicada'] = $solucaoAplicada;
        }
        if ($novoPrazo !== null && $novoPrazo !== '') {
            $payload['novo_prazo'] = $novoPrazo;
        }

        $response = $this->apiClient->patch('/orders/'.$id.'/status', $payload);

        return $response['data']['order'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function addProcedure(int $id, string $descricao): array
    {
        $response = $this->apiClient->post('/orders/'.$id.'/procedures', ['descricao' => $descricao]);

        return $response['data']['order'] ?? [];
    }

    /**
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function downloadPhoto(int $orderId, int $photoId): array
    {
        return $this->apiClient->download('/orders/'.$orderId.'/photos/'.$photoId);
    }

    /**
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function downloadDocument(int $orderId, int $documentId): array
    {
        return $this->apiClient->download('/orders/'.$orderId.'/documents/'.$documentId);
    }

    /**
     * @return array<string, mixed>
     */
    public function documentsCenter(int $orderId): array
    {
        $response = $this->apiClient->get('/orders/'.$orderId.'/documents');

        return $response['data'] ?? [];
    }

    /**
     * @param  array<int, string>  $types
     * @return array<string, mixed>
     */
    public function generateDocuments(int $orderId, array $types, array $signature = []): array
    {
        $response = $this->apiClient->post('/orders/'.$orderId.'/documents/generate', array_merge($signature, [
            'tipos' => array_values($types),
        ]));

        return $response['data'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function documentSignatureUsers(): array
    {
        $response = $this->apiClient->get('/document-signatures/signers');

        return $response['data']['users'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function pendingDocumentSignatures(): array
    {
        $response = $this->apiClient->get('/document-signatures/pending');

        return $response['data']['requests'] ?? [];
    }

    /** @return array{body: string, headers: array<string, string>, status: int} */
    public function previewPendingDocumentSignature(int $requestId): array
    {
        return $this->apiClient->download('/document-signatures/'.$requestId.'/preview');
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function signPendingDocument(int $requestId, array $payload = []): array
    {
        $response = $this->apiClient->post('/document-signatures/'.$requestId.'/sign', $payload);

        return $response['data'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sendDocuments(int $orderId, array $payload): array
    {
        $response = $this->apiClient->post('/orders/'.$orderId.'/documents/send', $payload);

        return $response['data'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createShareLink(int $orderId, array $payload): array
    {
        $response = $this->apiClient->post('/orders/'.$orderId.'/documents/share-links', $payload);

        return $response['data'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function revokeShareLink(int $orderId, int $linkId): array
    {
        $response = $this->apiClient->patch('/orders/'.$orderId.'/documents/share-links/'.$linkId.'/revoke');

        return $response['data'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function archiveDocument(int $orderId, int $documentId, bool $archive = true): array
    {
        $uri = '/orders/'.$orderId.'/documents/'.$documentId.'/'.($archive ? 'archive' : 'unarchive');
        $response = $this->apiClient->patch($uri);

        return $response['data'] ?? [];
    }

    /**
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function downloadDocumentFile(int $orderId, int $documentId, string $format): array
    {
        return $this->apiClient->download('/orders/'.$orderId.'/documents/'.$documentId.'/files/'.$format);
    }

    /**
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function downloadDocumentThumbnail(int $orderId, int $documentId): array
    {
        return $this->apiClient->download('/orders/'.$orderId.'/documents/'.$documentId.'/thumbnail');
    }

    /**
     * @param  array<int, int>  $documentIds
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function downloadDocumentsZip(int $orderId, array $documentIds, string $format = 'a4'): array
    {
        $query = http_build_query([
            'document_ids' => array_values($documentIds),
            'format' => $format,
        ]);

        return $this->apiClient->download('/orders/'.$orderId.'/documents/download?'.$query);
    }

    /**
     * @param  array<int, int>  $documentIds
     * @return array<string, mixed>
     */
    public function printDocuments(int $orderId, array $documentIds, string $format = 'a4'): array
    {
        $response = $this->apiClient->get('/orders/'.$orderId.'/documents/print', [
            'document_ids' => array_values($documentIds),
            'format' => $format,
        ]);

        return $response['data'] ?? [];
    }
}
