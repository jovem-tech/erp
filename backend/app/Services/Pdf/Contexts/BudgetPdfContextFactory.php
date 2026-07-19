<?php

namespace App\Services\Pdf\Contexts;

use App\Models\Budget;
use App\Models\BudgetItem;
use App\Models\Order;

/**
 * Contexto do documento de orçamento: tudo do OrderPdfContextFactory
 * (quando o orçamento tem OS vinculada) + orcamento.* + itens do orçamento
 * (que substituem os itens da OS na coleção `itens`).
 */
class BudgetPdfContextFactory extends OrderPdfContextFactory
{
    public function build(array $subject, array $options = []): array
    {
        $budget = $this->resolveBudget($subject);
        if (! $budget instanceof Budget) {
            return [];
        }

        $budget->loadMissing([
            'client',
            'equipment',
            'equipment.type',
            'equipment.brand',
            'equipment.model',
            'order',
            'items',
        ]);

        $context = [];
        if ($budget->order instanceof Order) {
            $context = parent::build(['order' => $budget->order], $options);
        }

        // Orçamento sem OS vinculada: monta cliente/equipamento direto do orçamento.
        if ($context === []) {
            $context = [
                'os' => [],
                'cliente' => [
                    'nome' => (string) ($budget->client?->nome_razao ?? ''),
                    'telefone' => (string) ($budget->client?->telefone1 ?? $budget->client?->telefone_contato ?? ''),
                    'email' => (string) ($budget->client?->email ?? ''),
                    'documento' => (string) ($budget->client?->cpf_cnpj ?? ''),
                    'endereco' => '',
                ],
                'equipamento' => [
                    'descricao' => (string) ($budget->equipment?->resumo_tecnico ?? ''),
                    'tipo' => (string) ($budget->equipment?->type?->nome ?? ''),
                    'marca' => (string) ($budget->equipment?->brand?->nome ?? ''),
                    'modelo' => (string) ($budget->equipment?->model?->nome ?? ''),
                    'serie' => (string) ($budget->equipment?->numero_serie ?? ''),
                    'foto_principal_base64' => $this->shouldIncludeEquipmentPhoto($options)
                        ? $this->equipmentPhotoBase64($budget->equipment)
                        : '',
                ],
                'acessorios' => [],
                'estado_fisico' => [],
            ];
        }

        $context['orcamento'] = [
            'numero' => trim((string) ($budget->numero ?? ('ORC-' . (int) $budget->id))),
            'titulo' => (string) ($budget->titulo ?? ''),
            'validade_dias' => (int) ($budget->validade_dias ?? 0),
            'prazo_execucao' => (string) ($budget->prazo_execucao ?? ''),
            'condicoes' => (string) ($budget->condicoes ?? ''),
            'observacoes' => (string) ($budget->observacoes ?? ''),
            'subtotal' => (float) ($budget->subtotal ?? 0),
            'desconto' => (float) ($budget->desconto ?? 0),
            'total' => (float) ($budget->total ?? 0),
            'link_aprovacao' => trim((string) ($options['approval_link'] ?? '')),
        ];

        // A coleção `itens` do documento de orçamento são os itens comerciais
        // do orçamento, não os itens operacionais da OS.
        $context['itens'] = $budget->items
            ->map(static fn (BudgetItem $item): array => [
                'tipo' => (string) ($item->tipo_item ?? ''),
                'descricao' => (string) ($item->descricao ?? ''),
                'quantidade' => (int) ($item->quantidade ?? 0),
                'valor_unitario' => (float) ($item->valor_unitario ?? 0),
                'desconto' => (float) ($item->desconto ?? 0),
                'acrescimo' => (float) ($item->acrescimo ?? 0),
                'valor_total' => (float) ($item->total ?? 0),
                'observacoes' => (string) ($item->observacoes ?? ''),
            ])
            ->values()
            ->all();

        return $context;
    }

    private function resolveBudget(array $subject): ?Budget
    {
        $budget = $subject['budget'] ?? null;
        if ($budget instanceof Budget) {
            return $budget;
        }

        $budgetId = (int) ($subject['budget_id'] ?? 0);

        return $budgetId > 0 ? Budget::query()->find($budgetId) : null;
    }
}
