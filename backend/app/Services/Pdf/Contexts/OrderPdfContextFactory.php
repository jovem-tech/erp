<?php

namespace App\Services\Pdf\Contexts;

use App\Models\Equipment;
use App\Models\EquipmentPhoto;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\EquipmentWorkflowService;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contexto-base de qualquer documento ligado a uma OS: os.*, cliente.*,
 * equipamento.* e as coleções itens/acessorios/estado_fisico.
 *
 * As variáveis os.acessorios_html / os.estado_fisico_html (tipo html,
 * herdadas do modelo legado de abertura) são pré-sanitizadas aqui — o
 * resolver injeta variáveis html sem escapar, então NUNCA podem carregar
 * conteúdo bruto do usuário.
 */
class OrderPdfContextFactory implements PdfContextFactoryInterface
{
    private const EQUIPMENT_PHOTO_MAX_BYTES = 2097152;

    private const EQUIPMENT_PHOTO_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private const ENTRY_PHOTOS_LIMIT = 4;

    public function __construct(
        private readonly EquipmentWorkflowService $equipmentWorkflowService,
        private readonly OrderWorkflowService $orderWorkflowService
    ) {
    }

    public function build(array $subject, array $options = []): array
    {
        $order = $this->resolveOrder($subject);
        if (! $order instanceof Order) {
            return [];
        }

        $order->loadMissing([
            'client',
            'equipment',
            'equipment.type',
            'equipment.brand',
            'equipment.model',
            'technician',
            'statusCatalog',
        ]);

        $checklist = $this->entryChecklistContext((int) $order->id);
        $acessorios = $this->accessoriesList((string) ($order->acessorios ?? ''), $checklist['items']);

        return [
            'os' => [
                'numero' => trim((string) ($order->numero_os ?? ('#' . $order->id))),
                'status' => (string) ($order->statusCatalog?->nome ?? $order->status ?? ''),
                'prioridade' => $this->humanizePriority((string) ($order->prioridade ?? '')),
                'data_abertura' => $order->data_abertura,
                'data_previsao' => $order->data_previsao,
                // Data de entrega não pode assumir a previsão: são fatos
                // distintos e o documento deve permanecer auditável.
                'data_entrega' => $order->data_entrega,
                'valor_final' => (float) ($order->valor_final ?? 0),
                'relato_cliente' => (string) ($order->relato_cliente ?? ''),
                'diagnostico_tecnico' => (string) ($order->diagnostico_tecnico ?? ''),
                'solucao_aplicada' => (string) ($order->solucao_aplicada ?? ''),
                'forma_pagamento' => (string) ($order->forma_pagamento ?? ''),
                'garantia_dias' => $order->garantia_dias !== null ? (int) $order->garantia_dias : null,
                'garantia_validade' => $order->garantia_validade,
                'tecnico_nome' => (string) ($order->technician?->nome ?? ''),
                'acessorios_html' => $this->accessoriesHtml($acessorios),
                'estado_fisico_html' => $this->stateHtml($checklist),
                'fotos_entrada' => $this->shouldIncludeEntryPhotos($options)
                    ? $this->entryPhotosBase64((int) $order->id)
                    : [],
            ],
            'cliente' => [
                'nome' => (string) ($order->client?->nome_razao ?? ''),
                'telefone' => (string) ($order->client?->telefone1 ?? $order->client?->telefone_contato ?? ''),
                'email' => (string) ($order->client?->email ?? ''),
                'documento' => (string) ($order->client?->cpf_cnpj ?? ''),
                'endereco' => $this->clientAddress($order),
            ],
            'equipamento' => [
                'descricao' => $this->equipmentLabel($order),
                'tipo' => (string) ($order->equipment?->type?->nome ?? ''),
                'marca' => (string) ($order->equipment?->brand?->nome ?? ''),
                'modelo' => (string) ($order->equipment?->model?->nome ?? ''),
                'serie' => (string) ($order->equipment?->numero_serie ?? ''),
                'foto_principal_base64' => $this->shouldIncludeEquipmentPhoto($options)
                    ? $this->equipmentPhotoBase64($order->equipment)
                    : '',
            ],
            'itens' => $this->orderItems($order),
            'acessorios' => array_map(
                static fn (string $descricao): array => ['descricao' => $descricao],
                $acessorios
            ),
            'estado_fisico' => array_map(
                fn (array $item): array => [
                    'item' => (string) ($item['item_nome'] ?? ''),
                    'status' => $this->humanizeChecklistStatus((string) ($item['status'] ?? '')),
                    'observacao' => (string) ($item['observacao'] ?? ''),
                ],
                $checklist['items']
            ),
        ];
    }

    protected function resolveOrder(array $subject): ?Order
    {
        $order = $subject['order'] ?? null;
        if ($order instanceof Order) {
            return $order;
        }

        $orderId = (int) ($subject['order_id'] ?? 0);

        return $orderId > 0 ? Order::query()->find($orderId) : null;
    }

    /**
     * Converte somente a foto principal autorizada pelo serviço de equipamentos.
     * URLs e caminhos vindos do template nunca são aceitos pelo motor de PDF.
     */
    protected function equipmentPhotoBase64(?Equipment $equipment): string
    {
        if (! $equipment instanceof Equipment || (int) $equipment->id <= 0) {
            return '';
        }

        $photo = EquipmentPhoto::query()
            ->where('equipamento_id', (int) $equipment->id)
            ->orderByDesc('is_principal')
            ->orderBy('id')
            ->first(['id', 'equipamento_id']);

        if (! $photo instanceof EquipmentPhoto) {
            return '';
        }

        $access = $this->equipmentWorkflowService->resolvePhotoAccess((int) $equipment->id, (int) $photo->id);
        $file = is_array($access['file'] ?? null) ? $access['file'] : [];
        $absolutePath = (string) ($file['absolute_path'] ?? '');
        $mimeType = strtolower((string) ($file['mime_type'] ?? ''));

        if (
            ($access['result'] ?? null) !== 'ok'
            || $absolutePath === ''
            || ! is_file($absolutePath)
            || ! in_array($mimeType, self::EQUIPMENT_PHOTO_MIME_TYPES, true)
        ) {
            return '';
        }

        $size = filesize($absolutePath);
        if ($size === false || $size <= 0 || $size > self::EQUIPMENT_PHOTO_MAX_BYTES) {
            logger()->warning('[PDF ENGINE] Foto principal do equipamento ignorada no PDF', [
                'equipamento_id' => (int) $equipment->id,
                'foto_id' => (int) $photo->id,
                'bytes' => $size,
                'limite' => self::EQUIPMENT_PHOTO_MAX_BYTES,
            ]);

            return '';
        }

        $bytes = file_get_contents($absolutePath);

        return $bytes === false ? '' : 'data:' . $mimeType . ';base64,' . base64_encode($bytes);
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function shouldIncludeEquipmentPhoto(array $options): bool
    {
        $tokens = is_array($options['image_tokens'] ?? null) ? $options['image_tokens'] : [];

        return in_array('foto_equipamento_principal', $tokens, true);
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function shouldIncludeEntryPhotos(array $options): bool
    {
        $tokens = is_array($options['image_tokens'] ?? null) ? $options['image_tokens'] : [];

        return in_array('fotos_entrada', $tokens, true);
    }

    /**
     * Converte até 4 fotos de recepção (check-in) da OS para data URI. Mesmas
     * regras de segurança da foto do equipamento: só arquivo local já
     * resolvido pelo serviço (nunca caminho/URL vindo do template), MIME
     * numa allowlist e tamanho limitado — item que estourar o limite é
     * silenciosamente ignorado (log de aviso), sem quebrar o documento.
     *
     * @return array<int, string>
     */
    protected function entryPhotosBase64(int $orderId): array
    {
        $photos = $this->orderWorkflowService->resolveEntryPhotosForPdf($orderId, self::ENTRY_PHOTOS_LIMIT);

        $dataUris = [];
        foreach ($photos as $photo) {
            $absolutePath = (string) ($photo['absolute_path'] ?? '');
            $mimeType = strtolower((string) ($photo['mime_type'] ?? ''));

            if (
                $absolutePath === ''
                || ! is_file($absolutePath)
                || ! in_array($mimeType, self::EQUIPMENT_PHOTO_MIME_TYPES, true)
            ) {
                continue;
            }

            $size = filesize($absolutePath);
            if ($size === false || $size <= 0 || $size > self::EQUIPMENT_PHOTO_MAX_BYTES) {
                logger()->warning('[PDF ENGINE] Foto de entrada da OS ignorada no PDF', [
                    'os_id' => $orderId,
                    'bytes' => $size,
                    'limite' => self::EQUIPMENT_PHOTO_MAX_BYTES,
                ]);

                continue;
            }

            $bytes = file_get_contents($absolutePath);
            if ($bytes === false) {
                continue;
            }

            ['bytes' => $bytes, 'mime' => $mimeType] = $this->rotateToLandscapeIfPortrait($bytes, $mimeType);

            $dataUris[] = 'data:' . $mimeType . ';base64,' . base64_encode($bytes);
        }

        return $dataUris;
    }

    /**
     * A galeria de fotos de entrada do PDF exige paisagem sempre, sem
     * cortar nada da imagem (diferente da exibição normal no sistema, que
     * mantém a orientação original — essa rotação só existe pra esse bloco).
     * Fotos já em paisagem (ou quadradas) voltam intactas; se o GD não
     * estiver disponível ou a imagem não puder ser decodificada, devolve os
     * bytes originais sem quebrar o documento.
     *
     * @return array{bytes: string, mime: string}
     */
    private function rotateToLandscapeIfPortrait(string $bytes, string $mimeType): array
    {
        $original = ['bytes' => $bytes, 'mime' => $mimeType];

        if (! function_exists('imagecreatefromstring')) {
            return $original;
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return $original;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($height <= $width) {
            return $original;
        }

        $rotated = imagerotate($image, -90, 0);

        if ($rotated === false) {
            return $original;
        }

        if ($mimeType === 'image/png') {
            imagesavealpha($rotated, true);
        }

        ob_start();
        $encoded = match ($mimeType) {
            'image/png' => imagepng($rotated),
            'image/webp' => function_exists('imagewebp') ? imagewebp($rotated) : imagejpeg($rotated, null, 90),
            default => imagejpeg($rotated, null, 90),
        };
        $output = ob_get_clean();

        if (! $encoded || ! is_string($output) || $output === '') {
            return $original;
        }

        $effectiveMime = $mimeType === 'image/webp' && ! function_exists('imagewebp') ? 'image/jpeg' : $mimeType;

        return ['bytes' => $output, 'mime' => $effectiveMime];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function orderItems(Order $order): array
    {
        return OrderItem::query()
            ->where('os_id', (int) $order->id)
            ->orderBy('tipo')
            ->orderBy('id')
            ->get(['tipo', 'descricao', 'quantidade', 'valor_unitario', 'valor_total'])
            ->map(static fn (OrderItem $item): array => [
                'tipo' => (string) ($item->tipo ?? ''),
                'descricao' => (string) ($item->descricao ?? ''),
                'quantidade' => (int) ($item->quantidade ?? 0),
                'valor_unitario' => (float) ($item->valor_unitario ?? 0),
                'valor_total' => (float) ($item->valor_total ?? 0),
            ])
            ->values()
            ->all();
    }

    private function clientAddress(Order $order): string
    {
        $parts = array_filter([
            trim((string) ($order->client?->endereco ?? '')),
            trim((string) ($order->client?->numero ?? '')),
            trim((string) ($order->client?->bairro ?? '')),
            trim((string) ($order->client?->cidade ?? '')),
            trim((string) ($order->client?->uf ?? '')),
        ], static fn (string $part): bool => $part !== '');

        return implode(', ', $parts);
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

    private function humanizeChecklistStatus(string $status): string
    {
        return match (trim($status)) {
            'ok' => 'OK',
            'discrepancia' => 'Discrepância',
            'nao_verificado' => 'Não verificado',
            default => ucwords(str_replace('_', ' ', trim($status))),
        };
    }

    /**
     * Portado de OrderOpeningPdfService::entryChecklistContext().
     *
     * @return array{observacoes_estado: string, items: array<int, array<string, string>>}
     */
    private function entryChecklistContext(int $orderId): array
    {
        if (
            $orderId <= 0
            || ! Schema::hasTable('checklist_execucoes')
            || ! Schema::hasTable('checklist_respostas')
        ) {
            return ['observacoes_estado' => '', 'items' => []];
        }

        $execution = DB::table('checklist_execucoes')
            ->where('os_id', $orderId)
            ->orderByDesc('id')
            ->first(['id', 'observacoes_estado']);

        if ($execution === null) {
            return ['observacoes_estado' => '', 'items' => []];
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
     * @return array<int, string>
     */
    private function accessoriesList(string $rawAccessories, array $checklistItems): array
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

        return $items;
    }

    /**
     * @param array<int, string> $acessorios
     */
    private function accessoriesHtml(array $acessorios): string
    {
        if ($acessorios === []) {
            return '<div class="empty-box muted">Nenhum acessório informado na abertura da OS.</div>';
        }

        $html = '<ul class="list">';
        foreach ($acessorios as $item) {
            $html .= '<li>' . $this->escape($item) . '</li>';
        }

        return $html . '</ul>';
    }

    /**
     * @param array{observacoes_estado: string, items: array<int, array<string, string>>} $checklistContext
     */
    private function stateHtml(array $checklistContext): string
    {
        $observacoes = trim((string) ($checklistContext['observacoes_estado'] ?? ''));
        $items = $checklistContext['items'];

        $blocks = '';
        if ($observacoes !== '') {
            $blocks .= '<div class="info-box"><strong>Observações registradas:</strong><br>' . nl2br($this->escape($observacoes), false) . '</div>';
        }

        if ($items === []) {
            return $blocks !== '' ? $blocks : '<div class="empty-box muted">Nenhuma observação de estado físico foi registrada.</div>';
        }

        $blocks .= '<table class="checklist-table">';
        $blocks .= '<thead><tr><th>Item</th><th>Status</th><th>Observação</th></tr></thead><tbody>';

        foreach ($items as $item) {
            $observacao = trim((string) ($item['observacao'] ?? ''));
            $blocks .= '<tr>';
            $blocks .= '<td>' . $this->escape($item['item_nome'] ?? '') . '</td>';
            $blocks .= '<td>' . $this->escape($this->humanizeChecklistStatus((string) ($item['status'] ?? ''))) . '</td>';
            $blocks .= '<td>' . ($observacao !== '' ? nl2br($this->escape($observacao), false) : '<span class="muted">Sem observação</span>') . '</td>';
            $blocks .= '</tr>';
        }

        return $blocks . '</tbody></table>';
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

    protected function escape(mixed $value): string
    {
        return htmlspecialchars(trim((string) ($value ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
