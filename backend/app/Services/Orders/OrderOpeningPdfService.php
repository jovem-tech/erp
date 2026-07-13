<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\OrderEvent;
use App\Models\OsPdfTemplate;
use App\Models\User;
use App\Support\TemplateHtmlSanitizer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class OrderOpeningPdfService
{
    public function __construct(
        private readonly OrderEventService $orderEventService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(Order $order, ?User $actor = null): array
    {
        if (! Schema::hasTable('os_pdf_templates')) {
            return [
                'ok' => false,
                'skipped' => true,
                'message' => 'Catálogo de modelos PDF indisponível neste ambiente.',
            ];
        }

        if (! Schema::hasTable('os_documentos')) {
            return [
                'ok' => false,
                'skipped' => true,
                'message' => 'Repositório de documentos da OS indisponível neste ambiente.',
            ];
        }

        $template = OsPdfTemplate::query()
            ->where('codigo', 'abertura')
            ->where('ativo', true)
            ->orderBy('ordem')
            ->orderByDesc('id')
            ->first();

        if (! $template instanceof OsPdfTemplate) {
            return [
                'ok' => false,
                'skipped' => true,
                'message' => 'Modelo PDF "abertura" não está configurado ou ativo.',
            ];
        }

        $templateHtml = trim((string) ($template->conteudo_html ?? ''));
        if ($templateHtml === '') {
            return [
                'ok' => false,
                'skipped' => true,
                'message' => 'Modelo PDF "abertura" está vazio.',
            ];
        }

        try {
            $order->loadMissing([
                'client',
                'equipment',
                'equipment.type',
                'equipment.brand',
                'equipment.model',
                'technician',
                'statusCatalog',
            ]);

            $numeroOs = trim((string) ($order->numero_os ?? ('OS-' . (int) $order->id)));

            $html = $this->wrapHtml(
                $this->renderTemplate($templateHtml, $this->placeholderMap($order)),
                $numeroOs
            );

            $pdfBytes = Pdf::loadHTML($html)
                ->setOption('isRemoteEnabled', false)
                ->setOption('isPhpEnabled', false)
                ->setPaper('a4', 'portrait')
                ->output();

            /** @var array{document: OrderDocument, relative_path: string, absolute_path: string, version: int} $persisted */
            $persisted = DB::transaction(function () use ($order, $actor, $numeroOs, $pdfBytes): array {
                $version = max(
                    1,
                    ((int) DB::table('os_documentos')
                        ->where('os_id', (int) $order->id)
                        ->where('tipo_documento', 'abertura')
                        ->lockForUpdate()
                        ->max('versao')) + 1
                );

                $relativePath = 'private/os_documentos/' . (int) $order->id . '/abertura_' . $this->slug($numeroOs) . '_v' . $version . '.pdf';
                $absolutePath = Storage::disk('local')->path($relativePath);
                Storage::disk('local')->put($relativePath, $pdfBytes);

                try {
                    $payload = [
                        'os_id' => (int) $order->id,
                        'tipo_documento' => 'abertura',
                        'arquivo' => $relativePath,
                        'versao' => $version,
                        'hash_sha1' => sha1($pdfBytes),
                        'gerado_por' => $actor instanceof User ? (int) $actor->id : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    if (Schema::hasColumn('os_documentos', 'hash_sha256')) {
                        $payload['hash_sha256'] = hash('sha256', $pdfBytes);
                    }

                    if (Schema::hasColumn('os_documentos', 'template_codigo')) {
                        $payload['template_codigo'] = 'abertura';
                    }

                    if (Schema::hasColumn('os_documentos', 'metadados_json')) {
                        $payload['metadados_json'] = json_encode([
                            'layout_padrao' => 'a4',
                            'origin' => 'order_opening_pdf',
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }

                    $document = OrderDocument::query()->create($payload);
                } catch (Throwable $exception) {
                    Storage::disk('local')->delete($relativePath);

                    throw $exception;
                }

                return [
                    'document' => $document,
                    'relative_path' => $relativePath,
                    'absolute_path' => $absolutePath,
                    'version' => $version,
                ];
            });

            $document = $persisted['document'];
            $relativePath = $persisted['relative_path'];
            $absolutePath = $persisted['absolute_path'];
            $version = $persisted['version'];

            $this->recordDocumentGeneratedEvent($order, $document, $actor);

            return [
                'ok' => true,
                'document_id' => (int) $document->id,
                'tipo_documento' => 'abertura',
                'relative_path' => $relativePath,
                'absolute_path' => $absolutePath,
                'file_name' => $numeroOs . '-abertura.pdf',
                'version' => $version,
                'message' => 'PDF de abertura gerado com sucesso.',
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'ok' => false,
                'skipped' => false,
                'message' => 'Falha ao gerar o PDF de abertura da OS.',
            ];
        }
    }

    private function recordDocumentGeneratedEvent(Order $order, OrderDocument $document, ?User $actor = null): void
    {
        $this->orderEventService->record(
            (int) $order->id,
            OrderEvent::CATEGORIA_DOCUMENTO,
            'documento_cliente_gerado',
            'Documento do cliente gerado',
            'Uma nova vers?o documental foi registrada para a OS.',
            [
                'documento_id' => (int) ($document->id ?? 0),
                'tipo_documento' => (string) ($document->tipo_documento ?? ''),
                'versao' => (int) ($document->versao ?? 1),
            ],
            $actor instanceof User ? (int) $actor->id : ((int) ($document->gerado_por ?? 0) ?: null),
            $actor instanceof User ? OrderEvent::ORIGEM_USUARIO : OrderEvent::ORIGEM_SISTEMA
        );
    }

    /**
     * @return array<string, string>
     */
    private function placeholderMap(Order $order): array
    {
        $equipmentLabel = $this->equipmentLabel($order);
        $checklistContext = $this->entryChecklistContext((int) $order->id);

        return [
            'numero_os' => $this->escape($order->numero_os ?? ''),
            'cliente_nome' => $this->escape($order->client?->nome_razao ?? ''),
            'cliente_telefone' => $this->escape($order->client?->telefone1 ?? $order->client?->telefone_contato ?? ''),
            'cliente_email' => $this->escape($order->client?->email ?? ''),
            'equipamento' => $this->escape($equipmentLabel),
            'equipamento_tipo' => $this->escape($order->equipment?->type?->nome ?? ''),
            'equipamento_marca' => $this->escape($order->equipment?->brand?->nome ?? ''),
            'equipamento_modelo' => $this->escape($order->equipment?->model?->nome ?? ''),
            'equipamento_serie' => $this->escape($order->equipment?->numero_serie ?? ''),
            'status_atual' => $this->escape($order->statusCatalog?->nome ?? $order->status ?? ''),
            'data_abertura' => $this->escape($this->formatDateTime($order->data_abertura ?? null)),
            'data_entrega' => $this->escape($this->formatDateTime($order->data_entrega ?? $order->data_previsao ?? null)),
            'valor_final' => $this->escape($this->decimalMoney($order->valor_final ?? null)),
            'tecnico_nome' => $this->escape($order->technician?->nome ?? ''),
            'prioridade' => $this->escape($this->humanizePriority((string) ($order->prioridade ?? ''))),
            'relato_cliente' => $this->escapeWithLineBreaks($order->relato_cliente ?? ''),
            'acessorios_html' => $this->buildAccessoriesHtml((string) ($order->acessorios ?? ''), $checklistContext['items'] ?? []),
            'estado_fisico_html' => $this->buildStateHtml($checklistContext),
        ];
    }

    /**
     * @param array<string, string> $placeholders
     */
    private function renderTemplate(string $templateHtml, array $placeholders): string
    {
        $replacements = [];
        foreach ($placeholders as $token => $value) {
            $replacements['{{' . $token . '}}'] = $value;
        }

        return strtr($this->sanitizeTemplateHtml($templateHtml), $replacements);
    }

    private function wrapHtml(string $content, string $numeroOs): string
    {
        $generatedAt = Carbon::now()->format('d/m/Y H:i');

        return '<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 12px; line-height: 1.55; margin: 0; padding: 24px; }
        .page { width: 100%; }
        .header { display: table; width: 100%; margin-bottom: 20px; }
        .header-cell { display: table-cell; vertical-align: top; }
        .header-meta { text-align: right; }
        .eyebrow { color: #1d4ed8; font-size: 11px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; margin-bottom: 6px; }
        h1 { margin: 0; font-size: 24px; line-height: 1.15; }
        .meta-chip { display: inline-block; padding: 8px 12px; border: 1px solid #bfdbfe; border-radius: 12px; background: #eff6ff; color: #1e3a8a; font-size: 11px; font-weight: 700; }
        .section-title { margin: 18px 0 8px; color: #1e293b; font-size: 12px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; }
        .grid, .checklist-table { width: 100%; border-collapse: collapse; }
        .grid td, .checklist-table th, .checklist-table td { border: 1px solid #dbe4f0; padding: 8px 10px; vertical-align: top; }
        .grid .label { width: 22%; font-weight: 700; color: #334155; background: #f8fbff; }
        .checklist-table th { background: #eff6ff; color: #1e3a8a; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; }
        .highlight-box, .info-box, .empty-box { padding: 10px 12px; border-radius: 12px; border: 1px solid #dbeafe; background: #f8fbff; }
        .info-box + .checklist-table, .empty-box + .checklist-table { margin-top: 10px; }
        .list { margin: 0; padding-left: 18px; }
        .list li { margin-bottom: 4px; }
        .muted { color: #475569; }
        .footer-note { margin-top: 18px; color: #475569; font-size: 11px; }
    </style>
</head>
<body>
    <div class="page">
        <div class="header">
            <div class="header-cell">
                <div class="eyebrow">Comprovante de abertura</div>
                <h1>Ordem de serviço ' . $this->escape($numeroOs) . '</h1>
            </div>
            <div class="header-cell header-meta">
                <span class="meta-chip">Gerado em ' . $this->escape($generatedAt) . '</span>
            </div>
        </div>
        ' . $content . '
    </div>
</body>
</html>';
    }

    private function sanitizeTemplateHtml(string $html): string
    {
        return TemplateHtmlSanitizer::sanitize($html);
    }

    private function equipmentLabel(Order $order): string
    {
        $summary = trim((string) ($order->equipment?->resumo_tecnico ?? ''));
        if ($summary !== '') {
            return $summary;
        }

        return trim(implode(' ', array_filter([
            trim((string) ($order->equipment?->type?->nome ?? '')),
            trim((string) ($order->equipment?->brand?->nome ?? '')),
            trim((string) ($order->equipment?->model?->nome ?? '')),
        ], static fn (string $value): bool => $value !== '')));
    }

    /**
     * @return array<string, mixed>
     */
    private function entryChecklistContext(int $orderId): array
    {
        if (
            $orderId <= 0
            || ! Schema::hasTable('checklist_execucoes')
            || ! Schema::hasTable('checklist_respostas')
        ) {
            return [
                'observacoes_estado' => '',
                'items' => [],
            ];
        }

        $execution = DB::table('checklist_execucoes')
            ->where('os_id', $orderId)
            ->orderByDesc('id')
            ->first(['id', 'observacoes_estado']);

        if ($execution === null) {
            return [
                'observacoes_estado' => '',
                'items' => [],
            ];
        }

        $itemsQuery = DB::table('checklist_respostas')
            ->where('checklist_respostas.checklist_execucao_id', (int) $execution->id)
            ->orderBy('checklist_respostas.ordem')
            ->orderBy('checklist_respostas.id');

        if (Schema::hasTable('checklist_itens')) {
            $itemsQuery->leftJoin('checklist_itens', 'checklist_itens.id', '=', 'checklist_respostas.checklist_item_id');
        }

        $itemNameSources = [];

        if (Schema::hasColumn('checklist_respostas', 'descricao_item')) {
            $itemNameSources[] = "NULLIF(TRIM(checklist_respostas.descricao_item), '')";
        }

        if (Schema::hasTable('checklist_itens') && Schema::hasColumn('checklist_itens', 'descricao')) {
            $itemNameSources[] = "NULLIF(TRIM(checklist_itens.descricao), '')";
        }

        if (Schema::hasTable('checklist_itens') && Schema::hasColumn('checklist_itens', 'nome')) {
            $itemNameSources[] = "NULLIF(TRIM(checklist_itens.nome), '')";
        }

        $itemNameExpression = $itemNameSources === []
            ? "'Item do checklist'"
            : 'COALESCE(' . implode(', ', $itemNameSources) . ", 'Item do checklist')";

        $items = $itemsQuery
            ->selectRaw($itemNameExpression . ' as item_nome')
            ->addSelect([
                'checklist_respostas.status',
                'checklist_respostas.observacao',
            ])
            ->get()
            ->map(static function (object $row): array {
                return [
                    'item_nome' => trim((string) ($row->item_nome ?? 'Item do checklist')),
                    'status' => trim((string) ($row->status ?? 'nao_verificado')),
                    'observacao' => trim((string) ($row->observacao ?? '')),
                ];
            })
            ->values()
            ->all();

        return [
            'observacoes_estado' => trim((string) ($execution->observacoes_estado ?? '')),
            'items' => $items,
        ];
    }

    /**
     * @param array<int, array<string, string>> $checklistItems
     */
    private function buildAccessoriesHtml(string $rawAccessories, array $checklistItems): string
    {
        $items = $this->splitTextList($rawAccessories);

        if ($items === []) {
            foreach ($checklistItems as $checklistItem) {
                $label = trim((string) ($checklistItem['item_nome'] ?? ''));
                if ($label === '' || ! $this->looksLikeAccessory($label)) {
                    continue;
                }

                $status = $this->humanizeChecklistStatus((string) ($checklistItem['status'] ?? ''));
                $note = trim((string) ($checklistItem['observacao'] ?? ''));
                $items[] = $label . ($status !== '' ? ' — ' . $status : '') . ($note !== '' ? ' (' . $note . ')' : '');
            }
        }

        if ($items === []) {
            return '<div class="empty-box muted">Nenhum acessório informado na abertura da OS.</div>';
        }

        $html = '<ul class="list">';
        foreach ($items as $item) {
            $html .= '<li>' . $this->escape($item) . '</li>';
        }
        $html .= '</ul>';

        return $html;
    }

    /**
     * @param array<string, mixed> $checklistContext
     */
    private function buildStateHtml(array $checklistContext): string
    {
        $observacoes = trim((string) ($checklistContext['observacoes_estado'] ?? ''));
        $items = is_array($checklistContext['items'] ?? null) ? $checklistContext['items'] : [];

        $blocks = '';
        if ($observacoes !== '') {
            $blocks .= '<div class="info-box"><strong>Observações registradas:</strong><br>' . $this->escapeWithLineBreaks($observacoes) . '</div>';
        }

        if ($items === []) {
            return $blocks !== '' ? $blocks : '<div class="empty-box muted">Nenhuma observação de estado físico foi registrada.</div>';
        }

        $blocks .= '<table class="checklist-table">';
        $blocks .= '<thead><tr><th>Item</th><th>Status</th><th>Observação</th></tr></thead><tbody>';

        foreach ($items as $item) {
            $blocks .= '<tr>';
            $blocks .= '<td>' . $this->escape($item['item_nome'] ?? '') . '</td>';
            $blocks .= '<td>' . $this->escape($this->humanizeChecklistStatus((string) ($item['status'] ?? ''))) . '</td>';
            $blocks .= '<td>' . ($item['observacao'] !== '' ? $this->escapeWithLineBreaks($item['observacao']) : '<span class="muted">Sem observação</span>') . '</td>';
            $blocks .= '</tr>';
        }

        $blocks .= '</tbody></table>';

        return $blocks;
    }

    private function humanizeChecklistStatus(string $status): string
    {
        return match (trim($status)) {
            'ok' => 'OK',
            'discrepancia' => 'Discrepância',
            'nao_verificado' => 'Não verificado',
            default => ucwords(str_replace('_', ' ', trim($status))),
        };
    }

    private function humanizePriority(string $priority): string
    {
        return match (trim($priority)) {
            'baixa' => 'Baixa',
            'normal' => 'Normal',
            'alta' => 'Alta',
            'urgente' => 'Urgente',
            default => 'Não informada',
        };
    }

    /**
     * @return array<int, string>
     */
    private function splitTextList(string $value): array
    {
        $normalized = str_replace(["\r\n", "\r", ';'], ["\n", "\n", "\n"], trim($value));
        if ($normalized === '') {
            return [];
        }

        $items = preg_split('/[\n,]+/', $normalized) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            $items
        ), static fn (string $item): bool => $item !== ''));
    }

    private function looksLikeAccessory(string $label): bool
    {
        $label = mb_strtolower($label);

        foreach ([
            'carregador',
            'fonte',
            'cabo',
            'bateria',
            'mouse',
            'teclado',
            'controle',
            'adaptador',
            'case',
            'capa',
            'chip',
            'pelicula',
            'caixa',
            'suporte',
        ] as $keyword) {
            if (str_contains($label, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function formatDateTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'Não informado';
        }

        if ($value instanceof Carbon) {
            return $value->format('d/m/Y H:i');
        }

        return trim((string) $value) !== '' ? (string) $value : 'Não informado';
    }

    private function decimalMoney(mixed $value): string
    {
        return 'R$ ' . number_format((float) ($value ?? 0), 2, ',', '.');
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars(trim((string) ($value ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeWithLineBreaks(mixed $value): string
    {
        return nl2br($this->escape($value), false);
    }

    private function slug(string $value): string
    {
        $slug = (string) preg_replace('/[^a-z0-9]+/i', '_', $value);

        return trim(strtolower($slug), '_') ?: 'os';
    }
}
