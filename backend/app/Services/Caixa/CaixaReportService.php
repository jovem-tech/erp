<?php

namespace App\Services\Caixa;

use App\Models\CaixaSessao;
use App\Services\Pdf\PdfGenerationService;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Relatório de fechamento de caixa — specs/028-caixa-sessoes.
 *
 * Servido por streaming, sem persistir: é reimprimível a qualquer momento a
 * partir da sessão.
 */
class CaixaReportService
{
    public function __construct(private readonly PdfGenerationService $pdfGenerationService) {}

    public function render(CaixaSessao $session, string $formato = '80mm'): string
    {
        $result = $this->pdfGenerationService->generate(
            'caixa_fechamento',
            ['session' => $session],
            [
                'formato' => $formato === 'a4' ? 'a4' : '80mm',
                // Relatório operacional, não documento assinado: sem isto o
                // motor exigiria assinatura cadastrada do operador.
                'unsigned_review' => true,
            ]
        );

        if (! ($result['ok'] ?? false)) {
            throw new RuntimeException((string) ($result['message'] ?? 'Falha ao gerar o relatório do caixa.'));
        }

        return (string) $result['bytes'];
    }

    public function stream(CaixaSessao $session, string $formato = '80mm'): Response
    {
        $bytes = $this->render($session, $formato);

        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="caixa-'.(int) $session->id.'.pdf"',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
