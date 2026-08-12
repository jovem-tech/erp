<?php

namespace App\Services\Sales;

use App\Models\Sale;
use App\Services\Pdf\PdfGenerationService;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Comprovante (cupom) da venda de balcão — specs/027-vendas-balcao-pdv.
 *
 * No MVP o PDF é servido por streaming, sem persistir: o cupom é reimprimível a
 * qualquer momento a partir da venda, então guardar uma cópia por emissão só
 * encheria o storage.
 */
class SaleReceiptService
{
    public function __construct(private readonly PdfGenerationService $pdfGenerationService) {}

    public function render(Sale $sale, string $formato = '80mm'): string
    {
        $result = $this->pdfGenerationService->generate(
            'venda_comprovante',
            ['sale' => $sale],
            [
                'formato' => $formato === 'a4' ? 'a4' : '80mm',
                // Cupom de balcão não é documento assinado. Sem isto, o motor
                // exigiria assinatura cadastrada do operador (regra de
                // document-signatures.require_user_signature) e a impressão
                // falharia para quem nunca cadastrou a sua.
                'unsigned_review' => true,
            ]
        );

        if (! ($result['ok'] ?? false)) {
            throw new RuntimeException((string) ($result['message'] ?? 'Falha ao gerar o comprovante da venda.'));
        }

        return (string) $result['bytes'];
    }

    public function stream(Sale $sale, string $formato = '80mm'): Response
    {
        $bytes = $this->render($sale, $formato);
        $filename = 'venda_'.str_replace('/', '-', (string) $sale->numero).'.pdf';

        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
