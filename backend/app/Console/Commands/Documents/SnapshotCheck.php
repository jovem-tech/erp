<?php

namespace App\Console\Commands\Documents;

use App\Models\OrderDocument;
use App\Models\OrderDocumentSnapshot;
use App\Services\Pdf\PdfGenerationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Diagnóstico do modo 'dual': para uma amostra de versões que têm snapshot E
 * arquivo em disco, re-renderiza pelo snapshot e compara páginas, tamanho e
 * texto extraído com o binário emitido. Serve para ganhar confiança antes de
 * virar DOCUMENT_RENDERING_MODE=snapshot. Não grava nada.
 */
class SnapshotCheck extends Command
{
    protected $signature = 'documents:snapshot-check
        {--sample=25 : Quantas versões (mais recentes) comparar}
        {--order= : Restringe a uma OS}
        {--format=a4 : a4 ou 80mm}';

    protected $description = 'Compara o re-render do snapshot com o PDF emitido (modo dual).';

    public function handle(PdfGenerationService $engine): int
    {
        $sample = max(1, min(500, (int) $this->option('sample')));
        $format = PdfGenerationService::normalizeFormat($this->option('format'));
        $orderId = (int) ($this->option('order') ?: 0);

        $query = OrderDocumentSnapshot::query()->with('document.files')->orderByDesc('id');
        if ($orderId > 0) {
            $query->whereHas('document', fn ($q) => $q->where('os_id', $orderId));
        }

        $checked = 0;
        $ok = 0;
        $warnings = 0;
        $failed = 0;
        $pdftotext = is_executable('/usr/bin/pdftotext') ? '/usr/bin/pdftotext' : null;

        foreach ($query->limit($sample)->get() as $snapshot) {
            $document = $snapshot->document;
            if (! $document instanceof OrderDocument) {
                continue;
            }

            $path = $format === 'a4'
                ? trim((string) $document->arquivo)
                : trim((string) ($document->files->firstWhere('formato', $format)?->arquivo ?? ''));
            if ($path === '' || ! Storage::disk('local')->exists($path)) {
                continue;
            }

            $checked++;
            $emitted = (string) Storage::disk('local')->get($path);

            try {
                $result = $engine->renderSnapshot($snapshot->envelope(), $format);
            } catch (Throwable $exception) {
                $result = ['ok' => false, 'message' => $exception->getMessage()];
            }

            if (! ($result['ok'] ?? false)) {
                $failed++;
                $this->error(sprintf('doc #%d (%s v%d): falha — %s', $document->id, $document->tipo_documento, $document->versao, (string) ($result['message'] ?? '')));

                continue;
            }

            $rendered = (string) $result['bytes'];
            $pagesEmitted = $this->pageCount($emitted);
            $pagesRendered = $this->pageCount($rendered);
            $ratio = strlen($emitted) > 0 ? strlen($rendered) / strlen($emitted) : 0;
            $divergencias = (array) ($result['divergencias'] ?? []);
            $textMatch = $pdftotext !== null ? $this->textMatches($pdftotext, $emitted, $rendered) : null;

            $problems = [];
            if ($pagesEmitted !== $pagesRendered) {
                $problems[] = sprintf('páginas %d→%d', $pagesEmitted, $pagesRendered);
            }
            if ($ratio < 0.5 || $ratio > 2.0) {
                $problems[] = sprintf('tamanho x%.2f', $ratio);
            }
            if ($textMatch === false) {
                $problems[] = 'texto diverge';
            }
            if ($divergencias !== []) {
                $problems[] = 'divergências: '.implode(',', $divergencias);
            }

            if ($problems === []) {
                $ok++;
                $this->line(sprintf('<info>ok</info>   doc #%d %s v%d — %d pág, %s → %s', $document->id, $document->tipo_documento, $document->versao, $pagesEmitted, $this->kb($emitted), $this->kb($rendered)));
            } else {
                $warnings++;
                $this->warn(sprintf('aviso doc #%d %s v%d — %s', $document->id, $document->tipo_documento, $document->versao, implode('; ', $problems)));
            }
        }

        $this->newLine();
        $this->info(sprintf('Comparados: %d | ok: %d | avisos: %d | falhas: %d', $checked, $ok, $warnings, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function pageCount(string $bytes): int
    {
        return max(0, (int) preg_match_all('#/Type\s*/Page[^s]#', $bytes));
    }

    private function textMatches(string $binary, string $a, string $b): bool
    {
        $extract = static function (string $bytes) use ($binary): string {
            $path = tempnam(sys_get_temp_dir(), 'snapchk-');
            file_put_contents($path, $bytes);
            try {
                $process = new \Symfony\Component\Process\Process([$binary, '-layout', $path, '-']);
                $process->run();

                // Ignora a linha "Gerado em ..." quando o snapshot não a preserva
                // e normaliza espaços.
                return trim((string) preg_replace('/\s+/', ' ', $process->getOutput()));
            } finally {
                @unlink($path);
            }
        };

        return $extract($a) === $extract($b);
    }

    private function kb(string $bytes): string
    {
        return sprintf('%.0f KB', strlen($bytes) / 1024);
    }
}
