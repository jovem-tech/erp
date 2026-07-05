<?php

namespace App\Services\Budgets;

use App\Models\Budget;
use App\Services\Company\CompanyProfileService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

class BudgetPdfService
{
    public function __construct(
        private readonly CompanyProfileService $companyProfileService
    ) {
    }

    /**
     * @return array{ok: bool, absolute_path?: string, relative_path?: string, file_name?: string, message?: string}
     */
    public function generate(Budget $budget, string $approvalLink): array
    {
        try {
            $budget->loadMissing(['client', 'equipment', 'order', 'items']);

            $companyPayload = $this->companyProfileService->payload();
            $companySettings = is_array($companyPayload['settings'] ?? null) ? $companyPayload['settings'] : [];

            $companyName = trim((string) ($companySettings['empresa_nome_fantasia'] ?? ''));
            if ($companyName === '') {
                $companyName = trim((string) ($companySettings['empresa_razao_social'] ?? ''));
            }
            if ($companyName === '') {
                $companyName = 'Sistema ERP';
            }

            $numero = trim((string) ($budget->numero ?? ('ORC-' . (int) $budget->id)));
            $version = max(1, (int) ($budget->versao ?? 1));
            $directory = 'private/orcamentos/' . (int) $budget->id;
            $relativePath = $directory . '/orcamento_' . $this->slug($numero) . '_v' . $version . '.pdf';
            $absolutePath = Storage::disk('local')->path($relativePath);
            $absoluteDirectory = dirname($absolutePath);

            if (! is_dir($absoluteDirectory)) {
                mkdir($absoluteDirectory, 0775, true);
            }

            Pdf::loadView('budgets.pdf.approval', [
                'budget' => $budget,
                'companyName' => $companyName,
                'approvalLink' => $approvalLink,
                'generatedAt' => Carbon::now(),
            ])->setPaper('a4', 'portrait')->save($absolutePath);

            return [
                'ok' => true,
                'absolute_path' => $absolutePath,
                'relative_path' => $relativePath,
                'file_name' => 'Orcamento-' . $numero . '.pdf',
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
