<?php

namespace App\Services\Sales;

use App\Models\SaleReturn;
use App\Services\Pdf\PdfGenerationService;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Comprovante de devolução — specs/029-devolucao-troca.
 *
 * Servido por streaming, sem persistir: é reimprimível a partir da devolução.
 */
class SaleReturnReceiptService
{
    public function __construct(private readonly PdfGenerationService $pdfGenerationService) {}

    public function render(SaleReturn $devolucao, string $formato = '80mm'): string
    {
        $result = $this->pdfGenerationService->generate(
            'venda_devolucao',
            ['return' => $devolucao],
            [
                'formato' => $formato === 'a4' ? 'a4' : '80mm',
                // Cupom de balcão não é documento assinado.
                'unsigned_review' => true,
            ]
        );

        if (! ($result['ok'] ?? false)) {
            throw new RuntimeException((string) ($result['message'] ?? 'Falha ao gerar o comprovante da devolução.'));
        }

        return (string) $result['bytes'];
    }

    public function stream(SaleReturn $devolucao, string $formato = '80mm'): Response
    {
        $bytes = $this->render($devolucao, $formato);
        $filename = 'devolucao_'.str_replace('/', '-', (string) $devolucao->numero).'.pdf';

        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
