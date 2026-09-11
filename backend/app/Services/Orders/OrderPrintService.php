<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;
use App\Services\Pdf\PdfGenerationService;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Impressão da OS pelo botão "Imprimir" da tela — o espelho completo do
 * atendimento (tipo documental os_completa).
 *
 * Servido por streaming, sem persistir: é reimpressão do estado ATUAL da OS,
 * disponível a qualquer momento. Guardar uma versão no acervo a cada
 * impressão só encheria o histórico documental — o que precisa virar
 * documento arquivado (abertura, laudo, entrega, encerramento) continua
 * saindo pela Central Documental.
 */
class OrderPrintService
{
    public const FORMATS = ['a4', '80mm'];

    public function __construct(private readonly PdfGenerationService $pdfGenerationService) {}

    public function normalizeFormat(?string $formato): string
    {
        $formato = strtolower(trim((string) $formato));

        return in_array($formato, self::FORMATS, true) ? $formato : 'a4';
    }

    public function render(Order $order, string $formato = 'a4', ?User $actor = null): string
    {
        $result = $this->pdfGenerationService->generate(
            'os_completa',
            ['order' => $order],
            [
                'formato' => $this->normalizeFormat($formato),
                'actor' => $actor,
                // Reimpressão não é emissão assinada. Sem isto o motor exigiria
                // assinatura cadastrada no perfil de quem imprime (regra de
                // document-signatures.require_user_signature) e o botão
                // falharia para quem nunca cadastrou a sua.
                'unsigned_review' => true,
            ]
        );

        if (! ($result['ok'] ?? false)) {
            throw new RuntimeException((string) ($result['message'] ?? 'Falha ao gerar a impressão da OS.'));
        }

        return (string) $result['bytes'];
    }

    public function stream(Order $order, string $formato = 'a4', ?User $actor = null): Response
    {
        $bytes = $this->render($order, $formato, $actor);
        $numero = trim((string) ($order->numero_os ?? ''));
        $filename = 'OS-'.str_replace(['/', '\\', ' '], '-', $numero !== '' ? $numero : (string) $order->id).'.pdf';

        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
