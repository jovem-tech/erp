<?php

namespace App\Services\Pdf\Contexts;

use App\Models\Budget;
use App\Models\Order;
use App\Models\OrderEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contexto da "Ordem de serviço completa" — o espelho da OS que sai pelo
 * botão Imprimir da tela.
 *
 * Herda tudo de OrderPdfContextFactory (os.*, cliente.*, equipamento.*,
 * itens, acessorios, estado_fisico e as fotos) e acrescenta o que só este
 * documento mostra: o orçamento vinculado (quando existe) e o histórico da
 * OS resumido aos marcos que interessam a quem lê o papel.
 */
class OrderCompletePdfContextFactory extends OrderPdfContextFactory
{
    /**
     * Marcos do histórico que entram no impresso. A timeline completa da OS
     * mistura ruído de automação (mensagens, sincronizações, documentos
     * gerados) que encheria páginas sem dizer nada ao cliente — a Auditoria
     * completa continua sendo o lugar de ver tudo.
     *
     * @var array<int, string>
     */
    private const HISTORY_TYPES = [
        OrderEvent::TIPO_OS_CRIADA,
        OrderEvent::TIPO_STATUS_ALTERADO,
        OrderEvent::TIPO_PRAZO_REDEFINIDO,
        OrderEvent::TIPO_DADOS_TECNICOS_ATUALIZADOS,
        OrderEvent::TIPO_PROCEDIMENTO_REGISTRADO,
        OrderEvent::TIPO_CHECKLIST_REGISTRADO,
        OrderEvent::TIPO_ORCAMENTO_CRIADO,
        OrderEvent::TIPO_ORCAMENTO_ENVIADO,
        OrderEvent::TIPO_ORCAMENTO_APROVADO,
        OrderEvent::TIPO_ORCAMENTO_RECUSADO,
        OrderEvent::TIPO_ADIANTAMENTO_REGISTRADO,
        OrderEvent::TIPO_FECHAMENTO_CONCLUIDO,
        OrderEvent::TIPO_FECHAMENTO_CANCELADO,
    ];

    private const HISTORY_LIMIT = 25;

    /**
     * @param array<string, mixed> $subject
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function build(array $subject, array $options = []): array
    {
        $context = parent::build($subject, $options);
        if ($context === []) {
            return [];
        }

        $order = $this->resolveOrder($subject);
        if (! $order instanceof Order) {
            return [];
        }

        // Contador escalar das fotos: o motor não sabe condicionar em cima de
        // uma coleção, e sem isto o título "Fotos da OS" ficaria sozinho na
        // folha em toda OS sem foto de entrada.
        $fotos = is_array($context['os']['fotos_entrada'] ?? null) ? $context['os']['fotos_entrada'] : [];
        $context['os']['fotos_quantidade'] = count($fotos);

        $context = array_merge($context, $this->budgetContext((int) $order->id));
        $context['historico'] = $this->historyRows((int) $order->id);

        return $context;
    }

    /**
     * Fotos só no A4. O motor deriva os tokens de imagem do schema inteiro,
     * sem olhar o formato, então sem este corte o cupom de 80 mm carregaria
     * em base64 fotos que os blocos `visivel_em => ['a4']` nunca imprimem.
     *
     * @param array<string, mixed> $options
     */
    protected function shouldIncludeEquipmentPhoto(array $options): bool
    {
        return $this->isA4($options) && parent::shouldIncludeEquipmentPhoto($options);
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function shouldIncludeEntryPhotos(array $options): bool
    {
        return $this->isA4($options) && parent::shouldIncludeEntryPhotos($options);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function isA4(array $options): bool
    {
        return strtolower(trim((string) ($options['formato'] ?? 'a4'))) !== '80mm';
    }

    /**
     * Orçamento mais recente da OS. Sem orçamento (ou em ambiente onde o
     * módulo não existe) devolve o bloco vazio: o condicional do modelo
     * some sozinho porque `orcamento.numero` fica em branco.
     *
     * @return array<string, mixed>
     */
    private function budgetContext(int $orderId): array
    {
        $vazio = [
            'orcamento' => [
                'numero' => '',
                'status' => '',
                'validade_data' => null,
                'subtotal' => 0.0,
                'desconto' => 0.0,
                'total' => 0.0,
            ],
            'orcamento_itens' => [],
        ];

        if (! Schema::hasTable('orcamentos')) {
            return $vazio;
        }

        $budget = DB::table('orcamentos')
            ->where('os_id', $orderId)
            ->orderByDesc('id')
            ->first(['id', 'numero', 'status', 'validade_data', 'subtotal', 'desconto', 'total']);

        if ($budget === null) {
            return $vazio;
        }

        $itens = [];
        if (Schema::hasTable('orcamento_itens')) {
            $itens = DB::table('orcamento_itens')
                ->where('orcamento_id', (int) $budget->id)
                ->orderBy('ordem')
                ->orderBy('id')
                ->get(['descricao', 'quantidade', 'valor_unitario', 'total'])
                ->map(static fn (object $item): array => [
                    'descricao' => (string) ($item->descricao ?? ''),
                    // float, não int: a quantidade do orçamento é decimal
                    // (1,5 h de serviço) — mesmo cuidado do documento de
                    // orçamento, para não divergir do valor total.
                    'quantidade' => (float) ($item->quantidade ?? 0),
                    'valor_unitario' => (float) ($item->valor_unitario ?? 0),
                    'valor_total' => (float) ($item->total ?? 0),
                ])
                ->values()
                ->all();
        }

        return [
            'orcamento' => [
                'numero' => trim((string) ($budget->numero ?? '')),
                'status' => Budget::statusLabel((string) ($budget->status ?? '')),
                'validade_data' => $budget->validade_data !== null
                    ? Carbon::parse((string) $budget->validade_data)
                    : null,
                'subtotal' => (float) ($budget->subtotal ?? 0),
                'desconto' => (float) ($budget->desconto ?? 0),
                'total' => (float) ($budget->total ?? 0),
            ],
            'orcamento_itens' => $itens,
        ];
    }

    /**
     * Marcos da OS em ordem cronológica. Quando a OS tem mais marcos que o
     * limite, ficam os mais recentes — o topo antigo é o que menos importa
     * na hora de conferir o atendimento.
     *
     * @return array<int, array<string, mixed>>
     */
    private function historyRows(int $orderId): array
    {
        if (! Schema::hasTable('os_eventos')) {
            return [];
        }

        return OrderEvent::query()
            ->with(['user:id,nome'])
            ->where('os_id', $orderId)
            ->whereIn('tipo', self::HISTORY_TYPES)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get(['id', 'tipo', 'titulo', 'usuario_id', 'created_at'])
            ->reverse()
            ->map(static fn (OrderEvent $event): array => [
                'data' => $event->created_at,
                'evento' => (string) ($event->titulo ?? ''),
                'autor' => trim((string) ($event->user?->nome ?? '')) !== ''
                    ? (string) $event->user->nome
                    : 'Sistema',
            ])
            ->values()
            ->all();
    }
}
