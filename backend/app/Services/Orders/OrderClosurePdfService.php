<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Throwable;

class OrderClosurePdfService
{
    /**
     * Gera o PDF consolidado da OS para anexar na notificação de baixa.
     *
     * @param array<string, mixed> $context
     * @return array{ok: bool, path?: string, file_name?: string, message?: string}
     */
    public function generate(Order $order, array $context): array
    {
        try {
            $order->loadMissing(['client', 'equipment']);

            $itens = OrderItem::query()
                ->where('os_id', $order->id)
                ->orderBy('tipo')
                ->orderBy('id')
                ->get(['tipo', 'descricao', 'quantidade', 'valor_unitario', 'valor_total']);

            $data = array_merge($context, [
                'order' => $order,
                'itens' => $itens,
                'geradoEm' => Carbon::now(),
            ]);

            $outputDir = storage_path('app' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'os_closure');
            if (! is_dir($outputDir)) {
                mkdir($outputDir, 0775, true);
            }

            $numeroOs = trim((string) ($context['numeroOs'] ?? $order->numero_os ?? ('os_' . $order->id)));
            $fileName = sprintf('os_%s_%s.pdf', $this->slug($numeroOs), Carbon::now()->format('Ymd_His'));
            $filePath = $outputDir . DIRECTORY_SEPARATOR . $fileName;

            Pdf::loadView('orders.pdf.closure', $data)
                ->setPaper('a4', 'portrait')
                ->save($filePath);

            return [
                'ok' => true,
                'path' => $filePath,
                'file_name' => 'OS-' . $numeroOs . '.pdf',
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    private function slug(string $value): string
    {
        $slug = (string) preg_replace('/[^a-z0-9]+/i', '_', $value);

        return trim(strtolower($slug), '_') ?: 'os';
    }
}
