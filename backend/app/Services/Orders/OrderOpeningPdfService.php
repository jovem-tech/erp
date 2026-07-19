<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\OrderDocumentFile;
use App\Models\OrderEvent;
use App\Models\User;
use App\Services\Pdf\PdfGenerationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class OrderOpeningPdfService
{
    public function __construct(
        private readonly OrderEventService $orderEventService,
        private readonly PdfGenerationService $pdfGenerationService
    ) {
    }

    /**
     * Gera e arquiva o comprovante de abertura nos formatos A4 e 80mm.
     * O template publicado no motor central e a unica fonte de emissao.
     *
     * @return array<string, mixed>
     */
    public function generate(Order $order, ?User $actor = null, array $options = []): array
    {
        if (! Schema::hasTable('os_documentos')) {
            return [
                'ok' => false,
                'skipped' => true,
                'message' => 'Repositório de documentos da OS indisponível neste ambiente.',
            ];
        }

        try {
            $order->loadMissing([
                'client',
                'equipment',
                'equipment.type',
                'equipment.brand',
                'equipment.model',
                'technician',
                'statusCatalog',
            ]);

            $numeroOs = trim((string) ($order->numero_os ?? ('OS-' . (int) $order->id)));
            $engineResults = [];

            foreach (['a4', '80mm'] as $formato) {
                $engineResults[$formato] = $this->pdfGenerationService->generate(
                    'os_abertura',
                    ['order' => $order],
                    array_merge($options, ['actor' => $actor, 'formato' => $formato])
                );

                if (! ($engineResults[$formato]['ok'] ?? false)) {
                    return [
                        'ok' => false,
                        'skipped' => false,
                        'message' => (string) ($engineResults[$formato]['message']
                            ?? 'O template publicado da abertura não pôde ser renderizado.'),
                    ];
                }
            }

            $bytesByFormat = [
                'a4' => (string) $engineResults['a4']['bytes'],
                '80mm' => (string) $engineResults['80mm']['bytes'],
            ];

            /** @var array{document: OrderDocument, relative_path: string, absolute_path: string, version: int} $persisted */
            $persisted = DB::transaction(function () use ($order, $actor, $numeroOs, $bytesByFormat, $engineResults): array {
                $version = max(
                    1,
                    ((int) DB::table('os_documentos')
                        ->where('os_id', (int) $order->id)
                        ->where('tipo_documento', 'abertura')
                        ->lockForUpdate()
                        ->max('versao')) + 1
                );

                $basePath = 'private/os_documentos/' . (int) $order->id . '/abertura_' . $this->slug($numeroOs) . '_v' . $version;
                $paths = [
                    'a4' => $basePath . '_a4.pdf',
                    '80mm' => $basePath . '_80mm.pdf',
                ];

                foreach ($paths as $formato => $relativePath) {
                    if (! Storage::disk('local')->put($relativePath, $bytesByFormat[$formato])) {
                        foreach ($paths as $writtenPath) {
                            Storage::disk('local')->delete($writtenPath);
                        }

                        throw new RuntimeException('Não foi possível gravar o PDF de abertura no armazenamento local.');
                    }
                }

                try {
                    $payload = [
                        'os_id' => (int) $order->id,
                        'tipo_documento' => 'abertura',
                        'arquivo' => $paths['a4'],
                        'versao' => $version,
                        'hash_sha1' => sha1($bytesByFormat['a4']),
                        'gerado_por' => $actor instanceof User ? (int) $actor->id : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    if (Schema::hasColumn('os_documentos', 'hash_sha256')) {
                        $payload['hash_sha256'] = hash('sha256', $bytesByFormat['a4']);
                    }
                    if (Schema::hasColumn('os_documentos', 'template_codigo')) {
                        $payload['template_codigo'] = 'os_abertura';
                    }
                    if (Schema::hasColumn('os_documentos', 'metadados_json')) {
                        $payload['metadados_json'] = array_merge(
                            ['layout_padrao' => 'a4', 'formatos' => ['a4', '80mm'], 'origin' => 'order_opening_pdf'],
                            PdfGenerationService::auditMetadata($engineResults['a4'], 'order_opening_pdf')
                        );
                    }

                    $signatureAudit = is_array($engineResults['a4']['assinatura'] ?? null)
                        ? $engineResults['a4']['assinatura']
                        : [];
                    if (Schema::hasColumn('os_documentos', 'assinado_por') && (int) ($signatureAudit['usuario_id'] ?? 0) > 0) {
                        $payload['assinado_por'] = (int) $signatureAudit['usuario_id'];
                        $payload['assinatura_hash'] = (string) ($signatureAudit['hash_sha256'] ?? '');
                        $payload['assinado_em'] = now();
                        $payload['metodo_assinatura'] = (string) ($signatureAudit['metodo'] ?? 'sessao');
                    }

                    $document = OrderDocument::query()->create($payload);

                    if (Schema::hasTable('os_documento_arquivos')) {
                        foreach ($paths as $formato => $relativePath) {
                            OrderDocumentFile::query()->create([
                                'documento_id' => (int) $document->id,
                                'formato' => $formato,
                                'arquivo' => $relativePath,
                                'mime' => 'application/pdf',
                                'tamanho_bytes' => strlen($bytesByFormat[$formato]),
                                'hash_sha256' => hash('sha256', $bytesByFormat[$formato]),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                } catch (Throwable $exception) {
                    foreach ($paths as $relativePath) {
                        Storage::disk('local')->delete($relativePath);
                    }

                    throw $exception;
                }

                return [
                    'document' => $document,
                    'relative_path' => $paths['a4'],
                    'absolute_path' => Storage::disk('local')->path($paths['a4']),
                    'version' => $version,
                ];
            }, 3);

            $this->recordDocumentGeneratedEvent($order, $persisted['document'], $actor);

            return [
                'ok' => true,
                'document_id' => (int) $persisted['document']->id,
                'tipo_documento' => 'abertura',
                'relative_path' => $persisted['relative_path'],
                'absolute_path' => $persisted['absolute_path'],
                'file_name' => $numeroOs . '-abertura.pdf',
                'version' => $persisted['version'],
                'message' => 'PDF de abertura gerado com sucesso.',
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'ok' => false,
                'skipped' => false,
                'message' => 'Falha ao gerar o PDF de abertura da OS.',
            ];
        }
    }

    private function recordDocumentGeneratedEvent(Order $order, OrderDocument $document, ?User $actor = null): void
    {
        $this->orderEventService->record(
            (int) $order->id,
            OrderEvent::CATEGORIA_DOCUMENTO,
            'documento_cliente_gerado',
            'Documento do cliente gerado',
            'Uma nova versão documental foi registrada para a OS.',
            [
                'documento_id' => (int) ($document->id ?? 0),
                'tipo_documento' => (string) ($document->tipo_documento ?? ''),
                'versao' => (int) ($document->versao ?? 1),
            ],
            $actor instanceof User ? (int) $actor->id : ((int) ($document->gerado_por ?? 0) ?: null),
            $actor instanceof User ? OrderEvent::ORIGEM_USUARIO : OrderEvent::ORIGEM_SISTEMA
        );
    }

    private function slug(string $value): string
    {
        $slug = (string) preg_replace('/[^a-z0-9]+/i', '_', $value);

        return trim(strtolower($slug), '_') ?: 'os';
    }
}
