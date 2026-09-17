<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\Documents\DocumentPersistencePolicy;
use App\Services\Orders\Documents\OrderDocumentVersionWriter;
use App\Services\Pdf\PdfGenerationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OrderClosurePdfService
{
    public function __construct(
        private readonly PdfGenerationService $pdfGenerationService,
        private readonly OrderDocumentVersionWriter $versionWriter,
        private readonly DocumentPersistencePolicy $persistencePolicy
    ) {
    }

    /**
     * Gera o PDF consolidado da OS para anexar na notificação de baixa.
     *
     * Renderiza exclusivamente pelo motor central (tipo os_encerramento) e
     * registra a versão no acervo da OS (snapshot; binário só por policy).
     * Assim a emissão sempre corresponde ao template publicado e permanece
     * auditável. Devolve `bytes` e um temporário (`path`) para o anexo do
     * chat — o OrderClosureService apaga o temporário no finally.
     *
     * @param array<string, mixed> $context
     * @return array{ok: bool, path?: string, bytes?: string, file_name?: string, document_id?: int, message?: string}
     */
    public function generate(Order $order, array $context): array
    {
        try {
            $order->loadMissing(['client', 'equipment']);

            $numeroOs = trim((string) ($context['numeroOs'] ?? $order->numero_os ?? ('os_' . $order->id)));
            $actor = ($context['actor'] ?? null) instanceof User ? $context['actor'] : null;

            $generationOptions = [
                'status_final_nome' => (string) ($context['statusFinalNome'] ?? ''),
                'data_entrega' => (string) ($context['dataEntrega'] ?? ''),
                'observacao_encerramento' => (string) ($context['observacaoEncerramento'] ?? ''),
                'valor_titulo' => (float) ($context['valorTitulo'] ?? 0),
                'saldo_restante' => (float) ($context['saldoRestante'] ?? 0),
                'recebimentos' => is_array($context['recebimentos'] ?? null) ? $context['recebimentos'] : [],
                'actor' => $actor,
                'capture_snapshot' => true,
                'render_profile' => $this->persistencePolicy->renderProfile([]),
            ];

            $engineResults = [];
            $formats = ['a4'];
            $a4 = $this->pdfGenerationService->generate('os_encerramento', ['order' => $order], array_merge($generationOptions, ['formato' => 'a4']));
            if (! ($a4['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'message' => (string) ($a4['message'] ?? 'O template publicado do encerramento não pôde ser renderizado.'),
                ];
            }
            $engineResults['a4'] = $a4;

            if ((bool) config('document-rendering.eager_80mm', false) || ! is_array($a4['snapshot'] ?? null)) {
                $thermal = $this->pdfGenerationService->generate('os_encerramento', ['order' => $order], array_merge($generationOptions, ['formato' => '80mm']));
                if (! ($thermal['ok'] ?? false)) {
                    return [
                        'ok' => false,
                        'message' => (string) ($thermal['message'] ?? 'O template publicado do encerramento não pôde ser renderizado.'),
                    ];
                }
                $engineResults['80mm'] = $thermal;
                $formats[] = '80mm';
            }

            $a4Bytes = (string) $a4['bytes'];

            $documentId = $this->persistToArchive($order, $formats, $engineResults, $actor);

            // Temporário só para o anexo do WhatsApp (UploadedFile exige
            // caminho); OrderClosureService apaga no finally.
            $outputDir = (string) config('document-rendering.temp_directory', storage_path('framework/cache/pdf-tmp'));
            if (! is_dir($outputDir) && ! @mkdir($outputDir, 0700, true) && ! is_dir($outputDir)) {
                throw new \RuntimeException('Não foi possível preparar o anexo de encerramento da OS.');
            }

            $fileName = sprintf('os_%s_%s.pdf', $this->slug($numeroOs), Carbon::now()->format('Ymd_His'));
            $filePath = $outputDir . DIRECTORY_SEPARATOR . $fileName;
            if (file_put_contents($filePath, $a4Bytes) === false) {
                throw new \RuntimeException('Não foi possível preparar o anexo de encerramento da OS.');
            }
            @chmod($filePath, 0600);

            $result = [
                'ok' => true,
                'path' => $filePath,
                'bytes' => $a4Bytes,
                'file_name' => 'OS-' . $numeroOs . '.pdf',
            ];

            if ($documentId !== null) {
                $result['document_id'] = $documentId;
            }

            return $result;
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * Registro auditável no acervo documental da OS. Falha aqui não pode
     * derrubar a baixa nem o anexo do WhatsApp — loga e segue.
     *
     * @param array<int, string> $formats
     * @param array<string, array<string, mixed>> $engineResults
     */
    private function persistToArchive(Order $order, array $formats, array $engineResults, ?User $actor): ?int
    {
        if (! Schema::hasTable('os_documentos')) {
            return null;
        }

        try {
            $renders = [];
            foreach ($formats as $formato) {
                $renders[$formato] = [
                    'bytes' => (string) ($engineResults[$formato]['bytes'] ?? ''),
                    'mime' => 'application/pdf',
                ];
            }
            $renders['a4']['engine'] = $engineResults['a4'];

            $persisted = $this->versionWriter->persist($order, 'encerramento', $renders, $actor, [
                'template_codigo' => 'os_encerramento',
                'apply_signature_audit' => true,
                'metadata' => array_merge(
                    ['layout_padrao' => 'a4', 'formatos' => ['a4', '80mm'], 'origin' => 'order_closure_pdf'],
                    PdfGenerationService::auditMetadata($engineResults['a4'], 'order_closure_pdf')
                ),
            ]);

            if (! ($persisted['ok'] ?? false)) {
                logger()->warning('[ORDERS][CLOSURE] Versão de encerramento não registrada no acervo', [
                    'order_id' => (int) $order->id,
                    'message' => (string) ($persisted['message'] ?? ''),
                ]);

                return null;
            }

            return (int) ($persisted['document_id'] ?? 0) ?: null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function slug(string $value): string
    {
        $slug = (string) preg_replace('/[^a-z0-9]+/i', '_', $value);

        return trim(strtolower($slug), '_') ?: 'os';
    }
}
