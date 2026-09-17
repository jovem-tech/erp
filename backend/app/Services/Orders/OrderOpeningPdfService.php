<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\Documents\DocumentPersistencePolicy;
use App\Services\Orders\Documents\OrderDocumentVersionWriter;
use App\Services\Pdf\PdfGenerationService;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OrderOpeningPdfService
{
    public function __construct(
        private readonly PdfGenerationService $pdfGenerationService,
        private readonly OrderDocumentVersionWriter $versionWriter,
        private readonly DocumentPersistencePolicy $persistencePolicy
    ) {
    }

    /**
     * Gera e arquiva o comprovante de abertura. O template publicado no
     * motor central e a unica fonte de emissao. O A4 e emitido com snapshot;
     * o 80mm sai sob demanda do mesmo snapshot (ou junto, se eager_80mm).
     * O retorno traz `bytes` do A4 — o envio ao cliente usa isso, sem
     * depender de arquivo em disco (absolute_path fica vazio no modo
     * snapshot).
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
            $generationOptions = array_merge($options, [
                'actor' => $actor,
                'capture_snapshot' => true,
                'render_profile' => $this->persistencePolicy->renderProfile($options),
            ]);

            $engineResults = [];
            $formats = ['a4'];
            $a4 = $this->pdfGenerationService->generate('os_abertura', ['order' => $order], array_merge($generationOptions, ['formato' => 'a4']));
            if (! ($a4['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'skipped' => false,
                    'message' => (string) ($a4['message'] ?? 'O template publicado da abertura não pôde ser renderizado.'),
                ];
            }
            $engineResults['a4'] = $a4;

            if ((bool) config('document-rendering.eager_80mm', false) || ! is_array($a4['snapshot'] ?? null)) {
                $thermal = $this->pdfGenerationService->generate('os_abertura', ['order' => $order], array_merge($generationOptions, ['formato' => '80mm']));
                if (! ($thermal['ok'] ?? false)) {
                    return [
                        'ok' => false,
                        'skipped' => false,
                        'message' => (string) ($thermal['message'] ?? 'O template publicado da abertura não pôde ser renderizado.'),
                    ];
                }
                $engineResults['80mm'] = $thermal;
                $formats[] = '80mm';
            }

            $renders = [];
            foreach ($formats as $formato) {
                $renders[$formato] = [
                    'bytes' => (string) $engineResults[$formato]['bytes'],
                    'mime' => 'application/pdf',
                ];
            }
            $renders['a4']['engine'] = $a4;

            $persisted = $this->versionWriter->persist($order, 'abertura', $renders, $actor, [
                'template_codigo' => 'os_abertura',
                'generation_options' => $options,
                'apply_signature_audit' => true,
                'metadata' => array_merge(
                    ['layout_padrao' => 'a4', 'formatos' => ['a4', '80mm'], 'origin' => 'order_opening_pdf'],
                    PdfGenerationService::auditMetadata($a4, 'order_opening_pdf')
                ),
            ]);

            if (! ($persisted['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'skipped' => false,
                    'message' => (string) ($persisted['message'] ?? 'Falha ao registrar o PDF de abertura.'),
                ];
            }

            return [
                'ok' => true,
                'document_id' => (int) ($persisted['document_id'] ?? 0),
                'tipo_documento' => 'abertura',
                'relative_path' => (string) ($persisted['relative_path'] ?? ''),
                'absolute_path' => (string) ($persisted['absolute_path'] ?? ''),
                'bytes' => (string) ($persisted['bytes'] ?? $a4['bytes']),
                'file_name' => $numeroOs . '-abertura.pdf',
                'version' => (int) ($persisted['version'] ?? 1),
                'storage' => (string) ($persisted['storage'] ?? 'disco'),
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
}
