<?php

namespace App\Services\Budgets;

use App\Models\Budget;
use App\Services\Pdf\PdfGenerationService;
use Illuminate\Support\Facades\Storage;
use Throwable;

class BudgetPdfService
{
    public function __construct(
        private readonly PdfGenerationService $pdfGenerationService
    ) {
    }

    /**
     * @return array{ok: bool, absolute_path?: string, relative_path?: string, file_name?: string, engine_result?: array<string, mixed>, message?: string}
     */
    public function generate(Budget $budget, string $approvalLink, array $options = []): array
    {
        try {
            $budget->loadMissing(['client', 'equipment', 'order', 'items']);

            $numero = trim((string) ($budget->numero ?? ('ORC-' . (int) $budget->id)));
            $version = max(1, (int) ($budget->versao ?? 1));
            $directory = 'private/orcamentos/' . (int) $budget->id;
            $relativePath = $directory . '/orcamento_' . $this->slug($numero) . '_v' . $version . '.pdf';
            $absolutePath = Storage::disk('local')->path($relativePath);

            // O template publicado no motor central é a única fonte do PDF.
            // Falhar explicitamente evita emitir um documento com layout
            // hard-coded diferente daquele aprovado em Modelos PDF.
            $engineResult = $this->pdfGenerationService->generate('os_orcamento', ['budget' => $budget], array_merge($options, [
                'approval_link' => $approvalLink,
                'formato' => 'a4',
            ]));

            if (! ($engineResult['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'message' => (string) ($engineResult['message'] ?? 'O template publicado do orçamento não pôde ser renderizado.'),
                ];
            }

            Storage::disk('local')->put($relativePath, (string) $engineResult['bytes']);

            return [
                'ok' => true,
                'absolute_path' => $absolutePath,
                'relative_path' => $relativePath,
                'file_name' => 'Orcamento-' . $numero . '.pdf',
                'engine_result' => $engineResult,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'ok' => false,
                'message' => 'Falha ao gerar o PDF do orçamento.',
            ];
        }
    }

    private function slug(string $value): string
    {
        $slug = (string) preg_replace('/[^a-z0-9]+/i', '_', $value);

        return trim(strtolower($slug), '_') ?: 'orcamento';
    }
}
