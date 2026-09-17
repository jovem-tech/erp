<?php

namespace App\Services\Orders\Documents;

use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\OrderDocumentFile;
use App\Models\OrderDocumentSnapshot;
use App\Services\Pdf\PdfGenerationService;
use App\Services\Pdf\PdfTemplateRegistry;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Ponto único que entrega os bytes de uma versão documental, em ordem:
 *   1. arquivo em disco (assinatura formal, ou legado ainda não expurgado);
 *   2. cache de render;
 *   3. re-render a partir do snapshot da emissão (grava no cache);
 *   4. reconstituição com os dados atuais da OS (legado sem snapshot cujo
 *      binário foi expurgado) — anotada como divergência;
 *   5. missing_file.
 *
 * isAvailable() responde sem renderizar nada: é o que listagens, catálogo e
 * links públicos devem usar, senão abrir a Central Documental viraria um
 * loop de renders de segundos.
 */
final class DocumentBytesResolver
{
    public function __construct(
        private readonly PdfGenerationService $pdfGenerationService,
        private readonly PdfTemplateRegistry $pdfTemplateRegistry,
        private readonly PdfRenderCache $renderCache
    ) {
    }

    public function isAvailable(Order $order, OrderDocument $document, string $format): bool
    {
        $format = PdfGenerationService::normalizeFormat($format);

        if ($this->diskFile($document, $format) !== null) {
            return true;
        }

        $snapshot = $this->snapshot($document);
        if ($snapshot instanceof OrderDocumentSnapshot) {
            return true; // o mesmo envelope serve A4 e 80mm
        }

        return $this->liveFallbackEnabled()
            && $this->pdfTemplateRegistry->codeForLegacyType((string) $document->tipo_documento) !== null;
    }

    /**
     * @param  array<string, mixed>  $options  live_render: callable(string $format): ?string
     * @return array{result: string, file?: ResolvedDocumentFile, message?: string}
     */
    public function resolve(Order $order, OrderDocument $document, string $format, array $options = []): array
    {
        $format = PdfGenerationService::normalizeFormat($format);

        $disk = $this->diskFile($document, $format);
        if ($disk !== null) {
            return ['result' => 'ok', 'file' => $disk];
        }

        $logicalPath = $this->logicalPath($order, $document, $format);

        $snapshot = $this->snapshot($document, true);
        if ($snapshot instanceof OrderDocumentSnapshot) {
            $key = PdfRenderCache::key(
                (string) $snapshot->hash_snapshot,
                (int) $snapshot->template_versao_id,
                (string) ($snapshot->hash_schema ?? ''),
                $format
            );

            $cached = $this->renderCache->get($key);
            if ($cached !== null) {
                return ['result' => 'ok', 'file' => ResolvedDocumentFile::fromBytes($logicalPath, $cached, ResolvedDocumentFile::SOURCE_RENDER_CACHE)];
            }

            $divergencias = [];
            try {
                $bytes = $this->renderCache->remember($key, function () use ($snapshot, $format, &$divergencias): string {
                    $result = $this->pdfGenerationService->renderSnapshot($snapshot->envelope(), $format);
                    if (! ($result['ok'] ?? false) || (string) ($result['bytes'] ?? '') === '') {
                        throw new \RuntimeException((string) ($result['message'] ?? 'Falha ao renderizar o documento a partir do snapshot.'));
                    }
                    $divergencias = is_array($result['divergencias'] ?? null) ? $result['divergencias'] : [];

                    return (string) $result['bytes'];
                });

                return ['result' => 'ok', 'file' => ResolvedDocumentFile::fromBytes($logicalPath, $bytes, ResolvedDocumentFile::SOURCE_SNAPSHOT, $divergencias)];
            } catch (Throwable $exception) {
                report($exception);

                return ['result' => 'render_failed', 'message' => 'Não foi possível renderizar esta versão do documento agora.'];
            }
        }

        $liveRender = $options['live_render'] ?? null;
        if ($this->liveFallbackEnabled() && is_callable($liveRender)) {
            $key = hash('sha256', implode('|', [
                'live',
                (string) $document->id,
                (string) ($order->updated_at?->getTimestamp() ?? 0),
                $format,
                (string) config('document-rendering.profile_version', 1),
            ]));

            try {
                $bytes = $this->renderCache->remember($key, static function () use ($liveRender, $format): string {
                    $bytes = $liveRender($format);
                    if (! is_string($bytes) || $bytes === '') {
                        throw new \RuntimeException('Reconstituição com dados atuais indisponível.');
                    }

                    return $bytes;
                });

                return [
                    'result' => 'ok',
                    'file' => ResolvedDocumentFile::fromBytes($logicalPath, $bytes, ResolvedDocumentFile::SOURCE_LIVE, ['reconstituido_com_dados_atuais']),
                ];
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return ['result' => 'missing_file'];
    }

    /**
     * Arquivo persistido do formato pedido, se existir. Para o A4 a fonte
     * autoritativa é os_documentos.arquivo; a linha em os_documento_arquivos
     * traz o managed_file_uuid.
     */
    public function diskFile(OrderDocument $document, string $format): ?ResolvedDocumentFile
    {
        $format = PdfGenerationService::normalizeFormat($format);
        $record = $this->fileRecord($document, $format);

        $relativePath = $format === 'a4'
            ? trim((string) ($document->arquivo ?? ''))
            : trim((string) ($record?->arquivo ?? ''));
        if ($relativePath === '' && $format === 'a4') {
            $relativePath = trim((string) ($record?->arquivo ?? ''));
        }

        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '' || str_contains($relativePath, '..') || ! Storage::disk('local')->exists($relativePath)) {
            return null;
        }

        $mime = trim((string) Storage::disk('local')->mimeType($relativePath));

        return ResolvedDocumentFile::fromDisk(
            $relativePath,
            Storage::disk('local')->path($relativePath),
            $mime !== '' ? $mime : 'application/pdf',
            trim((string) ($record?->managed_file_uuid ?? ''))
        );
    }

    /**
     * @param  bool  $withEnvelope  exige snapshot_json carregado (render); listagens
     *                              carregam só a identidade e passam false.
     */
    public function snapshot(OrderDocument $document, bool $withEnvelope = false): ?OrderDocumentSnapshot
    {
        if (! Schema::hasTable('os_documento_snapshots')) {
            return null;
        }

        if ($document->relationLoaded('snapshot')) {
            $snapshot = $document->getRelation('snapshot');
            if (! $snapshot instanceof OrderDocumentSnapshot) {
                return null;
            }
            // Listagens carregam o snapshot sem o JSON (só identidade); para
            // renderizar é preciso o envelope completo.
            if ($withEnvelope && ! array_key_exists('snapshot_json', $snapshot->getAttributes())) {
                return OrderDocumentSnapshot::query()->find((int) $snapshot->id);
            }

            return $snapshot;
        }

        return OrderDocumentSnapshot::query()->where('documento_id', (int) $document->id)->first();
    }

    /**
     * Chave do cache de render do A4 desta versão — usada pela miniatura
     * para nomear o PNG sem depender de managed_files.
     */
    public function renderCacheKey(OrderDocument $document, string $format): ?string
    {
        $snapshot = $this->snapshot($document);
        if (! $snapshot instanceof OrderDocumentSnapshot) {
            return null;
        }

        return PdfRenderCache::key(
            (string) $snapshot->hash_snapshot,
            (int) $snapshot->template_versao_id,
            (string) ($snapshot->hash_schema ?? ''),
            PdfGenerationService::normalizeFormat($format)
        );
    }

    private function fileRecord(OrderDocument $document, string $format): ?OrderDocumentFile
    {
        if ($document->relationLoaded('files')) {
            $record = $document->files->first(fn (OrderDocumentFile $file): bool => (string) $file->formato === $format);

            return $record instanceof OrderDocumentFile ? $record : null;
        }

        if (! Schema::hasTable('os_documento_arquivos')) {
            return null;
        }

        return OrderDocumentFile::query()
            ->where('documento_id', (int) $document->id)
            ->where('formato', $format)
            ->first();
    }

    /**
     * Nome/caminho lógico do formato (vira o nome do download). Segue a
     * convenção que o arquivo teria se estivesse em disco.
     */
    private function logicalPath(Order $order, OrderDocument $document, string $format): string
    {
        $record = $this->fileRecord($document, $format);
        $path = trim((string) ($record?->arquivo ?? ''));
        if ($path === '' && $format === 'a4') {
            $path = trim((string) ($document->arquivo ?? ''));
        }
        if ($path !== '') {
            return $path;
        }

        $base = trim((string) ($document->arquivo ?? ''));
        if ($base !== '') {
            return (string) preg_replace('/_a4\.pdf$/i', '_'.$format.'.pdf', $base);
        }

        return sprintf(
            'private/os_documentos/%d/%s_os-%d_v%d_%s.pdf',
            (int) $order->id,
            (string) $document->tipo_documento,
            (int) $order->id,
            (int) ($document->versao ?? 1),
            $format
        );
    }

    private function liveFallbackEnabled(): bool
    {
        return (bool) config('document-rendering.live_fallback', true);
    }
}
