<?php

namespace App\Services\Pdf\Contexts;

use App\Models\Budget;
use App\Models\BudgetItem;
use App\Models\Order;
use App\Services\Budgets\BudgetCommercialTermsService;

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
            'paymentMethods',
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

        // Condições comerciais (formas aceitas, chave Pix, parcelamento e
        // garantia) vêm do mesmo serviço que alimenta a tela e o link público:
        // o cliente lê exatamente o mesmo texto nos três lugares.
        $terms = app(BudgetCommercialTermsService::class)->forBudget($budget);

        $context['orcamento'] = [
            'numero' => trim((string) ($budget->numero ?? ('ORC-' . (int) $budget->id))),
            'titulo' => (string) ($budget->titulo ?? ''),
            'validade_dias' => (int) ($budget->validade_dias ?? 0),
            // Data limite para o cliente responder: vai na legenda do botao de
            // aprovacao, para ele saber ate quando o link vale.
            'validade_data' => $budget->validade_data,
            // Prazo que o backend realmente honra no link publico. Em geral
            // coincide com validade_data (o token expira no fim daquele dia),
            // mas quando o envio renovou o prazo é este que vale — a legenda do
            // botao nao pode prometer uma data que o 410 vai desmentir.
            'validade_link' => $budget->token_expira_em ?? $budget->validade_data,
            'prazo_execucao' => (string) ($budget->prazo_execucao ?? ''),
            'condicoes' => (string) ($budget->condicoes ?? ''),
            'observacoes' => (string) ($budget->observacoes ?? ''),
            'subtotal' => (float) ($budget->subtotal ?? 0),
            'desconto' => (float) ($budget->desconto ?? 0),
            'total' => (float) ($budget->total ?? 0),
            // Orçamento vencido: o link já devolve 410, então o botão some do
            // documento em vez de convidar o cliente a clicar em algo morto.
            // O condicional do modelo (`orcamento.link_aprovacao` preenchido)
            // cuida do resto — vale para qualquer modelo, não só o padrão.
            'link_aprovacao' => $budget->publicLinkExpired()
                ? ''
                : trim((string) ($options['approval_link'] ?? '')),
            'formas_pagamento' => (string) $terms['formas_pagamento_texto'],
            'chaves_pix' => (string) $terms['chaves_pix_texto'],
            'parcelamento' => (string) $terms['parcelamento_texto'],
            'garantia_dias' => $terms['garantia_dias'],
            'garantia_prazo' => (string) $terms['garantia_label'],
            'garantia_texto' => (string) $terms['garantia_texto'],
            'condicoes_comerciais' => (string) $terms['resumo'],
        ];

        $context['formas_pagamento'] = array_map(
            static fn (array $forma): array => ['nome' => $forma['nome']],
            $terms['formas_pagamento']
        );

        $context['chaves_pix'] = array_map(
            static fn (array $chave): array => [
                'tipo' => $chave['tipo_label'],
                'chave' => $chave['chave'],
                'titular' => $chave['titular'],
                'instituicao' => $chave['instituicao'],
            ],
            $terms['chaves_pix']
        );

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
