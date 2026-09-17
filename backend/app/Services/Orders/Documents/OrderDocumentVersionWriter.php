<?php

namespace App\Services\Orders\Documents;

use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\OrderDocumentFile;
use App\Models\OrderDocumentSnapshot;
use App\Models\OrderEvent;
use App\Models\User;
use App\Services\Orders\OrderEventService;
use App\Services\Pdf\PdfGenerationService;
use App\Services\Pdf\Snapshots\DocumentSnapshotSerializer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Registra uma nova versão documental. Grava SEMPRE o snapshot (quando o
 * motor o capturou) e as linhas de formato; o binário só vai a disco
 * quando a DocumentPersistencePolicy manda (assinatura formal, ou modo
 * 'dual'), já com compressão máxima. A numeração de versão continua sob
 * lockForUpdate porque geração manual e gatilhos automáticos concorrem.
 */
final class OrderDocumentVersionWriter
{
    public function __construct(
        private readonly DocumentPersistencePolicy $persistencePolicy,
        private readonly PdfRenderCache $renderCache,
        private readonly DocumentBytesResolver $bytesResolver,
        private readonly OrderEventService $orderEventService
    ) {
    }

    /**
     * @param  array<string, array{bytes: string, mime?: string, engine?: array<string, mixed>}>  $renders  'a4' obrigatório
     * @param  array<string, mixed>  $options  template_codigo, idempotency_key, metadata, generation_options,
     *                                         apply_signature_audit (bool)
     * @return array<string, mixed>
     */
    public function persist(Order $order, string $type, array $renders, ?User $actor, array $options = []): array
    {
        $a4Bytes = (string) ($renders['a4']['bytes'] ?? '');
        if ($a4Bytes === '') {
            return [
                'ok' => false,
                'message' => 'O layout A4 é obrigatório para registrar a versão documental.',
            ];
        }

        $engineResult = is_array($renders['a4']['engine'] ?? null) ? $renders['a4']['engine'] : [];
        $generationOptions = is_array($options['generation_options'] ?? null) ? $options['generation_options'] : [];
        $templateCode = trim((string) ($options['template_codigo'] ?? ''));
        $idempotencyKey = trim((string) ($options['idempotency_key'] ?? ''));
        $metadata = is_array($options['metadata'] ?? null) ? $options['metadata'] : [];
        $snapshotEnvelope = is_array($engineResult['snapshot'] ?? null) && Schema::hasTable('os_documento_snapshots')
            ? $engineResult['snapshot']
            : null;
        $hashSnapshot = $snapshotEnvelope !== null
            ? (string) ($engineResult['hash_snapshot'] ?? DocumentSnapshotSerializer::canonicalHash($snapshotEnvelope))
            : '';

        $formalSignature = $this->persistencePolicy->isFormalSignature($generationOptions, $engineResult);
        // Sem snapshot não há como re-renderizar: o binário fica em disco
        // independentemente da policy (legado/ambiente sem a tabela).
        $persistBinary = $snapshotEnvelope === null
            || $this->persistencePolicy->shouldPersistBinary($type, $generationOptions, $engineResult);

        // Compressão já aconteceu dentro de PdfGenerationService::renderPdfBytes()
        // (teto document-rendering.max_bytes, aplicado a TODO PDF gerado) — não
        // recomprimir aqui de novo.

        if ($idempotencyKey !== '' && Schema::hasColumn('os_documentos', 'idempotency_key')) {
            $existing = OrderDocument::query()
                ->where('os_id', (int) $order->id)
                ->where('tipo_documento', $type)
                ->where('idempotency_key', $idempotencyKey)
                ->orderByDesc('id')
                ->first();

            if ($existing instanceof OrderDocument) {
                return [
                    'ok' => true,
                    'document_id' => (int) $existing->id,
                    'version' => (int) ($existing->versao ?? 1),
                    'relative_path' => (string) ($existing->arquivo ?? ''),
                    'absolute_path' => $this->bytesResolver->diskFile($existing, 'a4')?->absolutePath ?? '',
                    'bytes' => $a4Bytes,
                    'message' => 'Versão documental reaproveitada por idempotência.',
                ];
            }
        }

        $baseSlug = $this->slug((string) ($order->numero_os ?? ('os-'.$order->id)));
        $renderProfile = is_array($snapshotEnvelope['render'] ?? null) ? $snapshotEnvelope['render'] : PdfGenerationService::renderProfileDescriptor($generationOptions);

        try {
            /** @var array{document: OrderDocument, version: int, paths: array<string, string>} $persisted */
            $persisted = DB::transaction(function () use (
                $order, $type, $renders, $actor, $engineResult, $templateCode, $idempotencyKey, $metadata,
                $baseSlug, $snapshotEnvelope, $hashSnapshot, $persistBinary, $renderProfile, $options
            ): array {
                $version = max(
                    1,
                    ((int) DB::table('os_documentos')
                        ->where('os_id', (int) $order->id)
                        ->where('tipo_documento', $type)
                        ->lockForUpdate()
                        ->max('versao')) + 1
                );

                $paths = [];
                foreach (['a4', '80mm'] as $format) {
                    $paths[$format] = 'private/os_documentos/'.(int) $order->id.'/'.$type.'_'.$baseSlug.'_v'.$version.'_'.$format.'.pdf';
                }

                $written = [];
                if ($persistBinary) {
                    foreach ($renders as $format => $render) {
                        $normalizedFormat = $this->normalizeFormat((string) $format);
                        $bytes = (string) ($render['bytes'] ?? '');
                        if ($bytes === '') {
                            continue;
                        }

                        if (! Storage::disk('local')->put($paths[$normalizedFormat], $bytes)) {
                            foreach ($written as $writtenPath) {
                                Storage::disk('local')->delete($writtenPath);
                            }

                            throw new \RuntimeException('Não foi possível gravar o PDF no armazenamento local.');
                        }
                        $written[$normalizedFormat] = $paths[$normalizedFormat];
                    }
                }

                try {
                    $a4Bytes = (string) $renders['a4']['bytes'];
                    $documentPayload = [
                        'os_id' => (int) $order->id,
                        'tipo_documento' => $type,
                        'arquivo' => $paths['a4'],
                        'versao' => $version,
                        'hash_sha1' => sha1($a4Bytes),
                        'gerado_por' => $actor instanceof User ? (int) $actor->id : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    if (Schema::hasColumn('os_documentos', 'hash_sha256')) {
                        $documentPayload['hash_sha256'] = hash('sha256', $a4Bytes);
                    }

                    if (Schema::hasColumn('os_documentos', 'template_codigo')) {
                        $documentPayload['template_codigo'] = $templateCode !== '' ? $templateCode : null;
                    }

                    if (Schema::hasColumn('os_documentos', 'idempotency_key')) {
                        $documentPayload['idempotency_key'] = $idempotencyKey !== '' ? $idempotencyKey : null;
                    }

                    if (Schema::hasColumn('os_documentos', 'metadados_json')) {
                        // O cast 'array' do model já serializa — passar o array
                        // direto (json_encode manual aqui dupla-codificava e o
                        // metadado voltava como string ao ler).
                        $documentPayload['metadados_json'] = array_merge($metadata, array_filter([
                            'armazenamento' => $persistBinary ? 'disco' : 'snapshot',
                            'render_perfil' => (string) ($renderProfile['perfil'] ?? 'padrao'),
                            'hash_snapshot' => $hashSnapshot,
                            // hash_sha1/hash_sha256 atestam o binário EMITIDO; um
                            // re-render do snapshot nunca é byte-idêntico (dompdf
                            // grava /ID e CreationDate novos).
                            'hash_semantica' => 'emissao',
                        ], static fn ($value): bool => $value !== '' && $value !== null));
                    }

                    $signatureAudit = is_array($engineResult['assinatura'] ?? null) ? $engineResult['assinatura'] : [];
                    if (
                        (bool) ($options['apply_signature_audit'] ?? false)
                        && Schema::hasColumn('os_documentos', 'assinado_por')
                        && (int) ($signatureAudit['usuario_id'] ?? 0) > 0
                    ) {
                        $documentPayload['assinado_por'] = (int) $signatureAudit['usuario_id'];
                        $documentPayload['assinatura_hash'] = (string) ($signatureAudit['hash_sha256'] ?? '');
                        $documentPayload['assinado_em'] = now();
                        $documentPayload['metodo_assinatura'] = (string) ($signatureAudit['metodo'] ?? 'sessao');
                    }

                    /** @var OrderDocument $document */
                    $document = OrderDocument::query()->create($documentPayload);

                    if ($snapshotEnvelope !== null) {
                        $json = DocumentSnapshotSerializer::encode($snapshotEnvelope);
                        OrderDocumentSnapshot::query()->create([
                            'documento_id' => (int) $document->id,
                            'versao_formato' => (int) ($snapshotEnvelope['v'] ?? DocumentSnapshotSerializer::FORMAT_VERSION),
                            'tipo_codigo' => (string) ($snapshotEnvelope['tipo_codigo'] ?? $templateCode),
                            'template_id' => (int) ($snapshotEnvelope['template']['id'] ?? 0) ?: null,
                            'template_versao_id' => (int) ($snapshotEnvelope['template']['versao_id'] ?? 0) ?: null,
                            'template_versao' => (int) ($snapshotEnvelope['template']['versao'] ?? 0) ?: null,
                            'hash_schema' => (string) ($snapshotEnvelope['template']['hash_schema'] ?? '') ?: null,
                            'hash_snapshot' => $hashSnapshot,
                            'formatos' => 'a4,80mm',
                            'tamanho_bytes' => strlen($json),
                            'snapshot_json' => $json,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    if (Schema::hasTable('os_documento_arquivos')) {
                        foreach (['a4', '80mm'] as $format) {
                            $bytes = (string) ($renders[$format]['bytes'] ?? '');
                            if ($bytes === '' && $snapshotEnvelope === null && $format === '80mm') {
                                continue;
                            }

                            OrderDocumentFile::query()->create([
                                'documento_id' => (int) $document->id,
                                'formato' => $format,
                                'arquivo' => $paths[$format],
                                'mime' => (string) ($renders[$format]['mime'] ?? 'application/pdf'),
                                'tamanho_bytes' => $bytes !== '' ? strlen($bytes) : null,
                                'hash_sha256' => $bytes !== '' ? hash('sha256', $bytes) : null,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                } catch (Throwable $exception) {
                    foreach ($written as $writtenPath) {
                        Storage::disk('local')->delete($writtenPath);
                    }

                    throw $exception;
                }

                return [
                    'document' => $document,
                    'version' => $version,
                    'paths' => $paths,
                    'written' => $written,
                ];
            }, 3);
        } catch (Throwable $exception) {
            report($exception);

            return [
                'ok' => false,
                'message' => 'Falha ao persistir a nova versão documental.',
            ];
        }

        /** @var OrderDocument $document */
        $document = $persisted['document'];

        // Pré-aquece o cache com os bytes que de fato foram emitidos: o
        // primeiro download/link/miniatura sai instantâneo e idêntico.
        if ($snapshotEnvelope !== null) {
            foreach ($renders as $format => $render) {
                $bytes = (string) ($render['bytes'] ?? '');
                if ($bytes === '') {
                    continue;
                }
                $this->renderCache->put(PdfRenderCache::key(
                    $hashSnapshot,
                    (int) ($snapshotEnvelope['template']['versao_id'] ?? 0),
                    (string) ($snapshotEnvelope['template']['hash_schema'] ?? ''),
                    $this->normalizeFormat((string) $format)
                ), $bytes);
            }
        }

        $this->recordOrderEvent(
            (int) $order->id,
            'documento',
            'documento_cliente_gerado',
            'Documento do cliente gerado',
            'Uma nova versão documental foi registrada para a OS.',
            [
                'documento_id' => (int) $document->id,
                'tipo_documento' => $type,
                'versao' => (int) ($document->versao ?? 1),
                'armazenamento' => $persistBinary ? 'disco' : 'snapshot',
            ],
            $actor instanceof User ? (int) $actor->id : null
        );

        $a4Written = (string) ($persisted['written']['a4'] ?? '');

        return [
            'ok' => true,
            'document_id' => (int) $document->id,
            'version' => (int) $persisted['version'],
            'relative_path' => (string) $persisted['paths']['a4'],
            'absolute_path' => $a4Written !== '' ? Storage::disk('local')->path($a4Written) : '',
            'bytes' => (string) $renders['a4']['bytes'],
            'storage' => $persistBinary ? 'disco' : 'snapshot',
            'message' => 'Documento gerado com sucesso.',
        ];
    }

    private function normalizeFormat(string $format): string
    {
        return PdfGenerationService::normalizeFormat($format);
    }

    // Mesma convenção de nome do acervo: laudo_os26090009_v2_a4.pdf.
    private function slug(string $value): string
    {
        $slug = (string) preg_replace('/[^a-z0-9]+/i', '_', $value);

        return trim(strtolower($slug), '_') ?: 'documento';
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordOrderEvent(
        int $orderId,
        string $category,
        string $type,
        string $title,
        string $description,
        array $metadata = [],
        ?int $userId = null
    ): void {
        if ($orderId <= 0) {
            return;
        }

        try {
            $this->orderEventService->record(
                $orderId,
                $category,
                $type,
                $title,
                $description,
                $metadata,
                $userId,
                $userId !== null ? OrderEvent::ORIGEM_USUARIO : OrderEvent::ORIGEM_SISTEMA
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
