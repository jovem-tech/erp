<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\OrderDocumentFile;
use App\Models\User;
use App\Services\Pdf\PdfGenerationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class OrderClosurePdfService
{
    public function __construct(
        private readonly PdfGenerationService $pdfGenerationService
    ) {
    }

    /**
     * Gera o PDF consolidado da OS para anexar na notificação de baixa.
     *
     * Renderiza exclusivamente pelo motor central (tipo os_encerramento) e
     * registra a versão no acervo da OS. Assim a emissão sempre corresponde
     * ao template publicado e permanece auditável.
     *
     * @param array<string, mixed> $context
     * @return array{ok: bool, path?: string, file_name?: string, document_id?: int, message?: string}
     */
    public function generate(Order $order, array $context): array
    {
        try {
            $order->loadMissing(['client', 'equipment']);

            $numeroOs = trim((string) ($context['numeroOs'] ?? $order->numero_os ?? ('os_' . $order->id)));

            $engineResults = [];
            foreach (['a4', '80mm'] as $formato) {
                $engineResults[$formato] = $this->pdfGenerationService->generate('os_encerramento', ['order' => $order], [
                    'formato' => $formato,
                    'status_final_nome' => (string) ($context['statusFinalNome'] ?? ''),
                    'data_entrega' => (string) ($context['dataEntrega'] ?? ''),
                    'observacao_encerramento' => (string) ($context['observacaoEncerramento'] ?? ''),
                    'valor_titulo' => (float) ($context['valorTitulo'] ?? 0),
                    'saldo_restante' => (float) ($context['saldoRestante'] ?? 0),
                    'recebimentos' => is_array($context['recebimentos'] ?? null) ? $context['recebimentos'] : [],
                    'actor' => ($context['actor'] ?? null) instanceof User ? $context['actor'] : null,
                ]);

                if (! ($engineResults[$formato]['ok'] ?? false)) {
                    return [
                        'ok' => false,
                        'message' => (string) ($engineResults[$formato]['message']
                            ?? 'O template publicado do encerramento não pôde ser renderizado.'),
                    ];
                }
            }

            $bytesByFormat = [
                'a4' => (string) $engineResults['a4']['bytes'],
                '80mm' => (string) $engineResults['80mm']['bytes'],
            ];

            $outputDir = storage_path('app' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'os_closure');
            if (! is_dir($outputDir)) {
                mkdir($outputDir, 0775, true);
            }

            $fileName = sprintf('os_%s_%s.pdf', $this->slug($numeroOs), Carbon::now()->format('Ymd_His'));
            $filePath = $outputDir . DIRECTORY_SEPARATOR . $fileName;
            if (file_put_contents($filePath, $bytesByFormat['a4']) === false) {
                throw new \RuntimeException('Não foi possível preparar o anexo de encerramento da OS.');
            }

            $documentId = $this->persistToArchive(
                $order,
                $numeroOs,
                $bytesByFormat,
                $engineResults['a4'],
                ($context['actor'] ?? null) instanceof User ? $context['actor'] : null
            );

            $result = [
                'ok' => true,
                'path' => $filePath,
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
     * @param array<string, string> $bytesByFormat
     * @param array<string, mixed> $engineResult
     */
    private function persistToArchive(Order $order, string $numeroOs, array $bytesByFormat, array $engineResult, ?User $actor): ?int
    {
        if (! Schema::hasTable('os_documentos')) {
            return null;
        }

        try {
            return DB::transaction(function () use ($order, $numeroOs, $bytesByFormat, $engineResult, $actor): int {
                $version = max(
                    1,
                    ((int) DB::table('os_documentos')
                        ->where('os_id', (int) $order->id)
                        ->where('tipo_documento', 'encerramento')
                        ->lockForUpdate()
                        ->max('versao')) + 1
                );

                $basePath = 'private/os_documentos/' . (int) $order->id . '/encerramento_' . $this->slug($numeroOs) . '_v' . $version;
                $paths = ['a4' => $basePath . '_a4.pdf', '80mm' => $basePath . '_80mm.pdf'];

                foreach ($paths as $formato => $relativePath) {
                    if (! Storage::disk('local')->put($relativePath, (string) ($bytesByFormat[$formato] ?? ''))) {
                        foreach ($paths as $writtenPath) {
                            Storage::disk('local')->delete($writtenPath);
                        }

                        throw new \RuntimeException('Não foi possível arquivar o PDF de encerramento.');
                    }
                }

                try {
                    $payload = [
                        'os_id' => (int) $order->id,
                        'tipo_documento' => 'encerramento',
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
                        $payload['template_codigo'] = 'os_encerramento';
                    }

                    if (Schema::hasColumn('os_documentos', 'metadados_json')) {
                        $payload['metadados_json'] = array_merge(
                            ['layout_padrao' => 'a4', 'formatos' => ['a4', '80mm'], 'origin' => 'order_closure_pdf'],
                            PdfGenerationService::auditMetadata($engineResult, 'order_closure_pdf')
                        );
                    }

                    $signatureAudit = is_array($engineResult['assinatura'] ?? null)
                        ? $engineResult['assinatura']
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

                return (int) $document->id;
            });
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
