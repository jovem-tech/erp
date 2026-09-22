<?php

namespace App\Services\Budgets;

use App\Models\Budget;
use App\Models\BudgetApproval;
use App\Models\BudgetItem;
use App\Support\BudgetTotals;
use Illuminate\Support\Carbon;

/**
 * "O que foi oferecido ao cliente" num orçamento em níveis de manutenção —
 * a mesma coisa vista de dois momentos:
 *
 * - antes da decisão, é a projeção viva (`capture()`): itens e condições
 *   comerciais de cada opção, como a landing pública mostra;
 * - depois da decisão, é o snapshot gravado na aprovação
 *   (`orcamento_aprovacoes.niveis_snapshot`), já que a poda deixa em
 *   `orcamento_itens` só o escopo contratado.
 *
 * `forBudget()` é o único ponto que decide qual dos dois vale — página
 * pública, detalhe do orçamento e detalhe da OS leem daqui e nunca
 * reimplementam a regra. Snapshots antigos (só nomes de item + totais)
 * continuam legíveis via `normalize()`.
 */
class BudgetOfferedOptionsService
{
    /**
     * Campos das condições comerciais comparados entre opções para decidir
     * se são ditos uma vez ("em qualquer opção") ou dentro de cada cartão.
     *
     * @var array<string, string>
     */
    public const TERM_FIELDS = [
        'garantia' => 'garantia_label',
        'formas_pagamento' => 'formas_pagamento_texto',
        'parcelamento' => 'parcelamento_texto',
        'entrega_domicilio' => 'entrega_domicilio_label',
    ];

    public function __construct(
        private readonly BudgetCommercialTermsService $budgetCommercialTermsService
    ) {
    }

    /**
     * Projeção viva das opções (vazio sem níveis para escolher). É o que
     * vai para a landing pública e o que a aprovação congela no snapshot.
     *
     * @return array<int, array<string, mixed>>
     */
    public function capture(Budget $budget): array
    {
        if (! $budget->hasTiers()) {
            return [];
        }

        $levels = BudgetTotals::perLevel($budget);
        $terms = $this->budgetCommercialTermsService->forEachLevel($budget);

        foreach ($levels as $index => $level) {
            $nivel = (int) ($level['nivel'] ?? 0);
            $levels[$index]['itens_detalhe'] = BudgetTotals::itemsForLevel($budget, $nivel)
                ->map(fn (BudgetItem $item): array => $this->describeItem($item))
                ->all();
            $levels[$index]['condicoes_comerciais'] = $terms[$nivel] ?? null;
        }

        return $levels;
    }

    /**
     * Por campo, se o valor é o mesmo em todas as opções (modo
     * `compartilhado`, dito uma vez) ou varia (`por_opcao`, dito em cada
     * cartão). Olha TODAS as opções recebidas — esconder uma diferença real
     * é o erro que esta regra existe para evitar.
     *
     * @param  array<int, array<string, mixed>>  $levels
     * @return array<string, array{modo: string, valor: string}>
     */
    public function termsLayout(array $levels): array
    {
        $perLevelTerms = [];
        foreach ($levels as $level) {
            if (is_array($level['condicoes_comerciais'] ?? null)) {
                $perLevelTerms[] = $level['condicoes_comerciais'];
            }
        }

        $layout = [];
        foreach (self::TERM_FIELDS as $chave => $campo) {
            $compartilhado = count($perLevelTerms) <= 1
                || ! BudgetCommercialTermsService::diffAcrossLevels($perLevelTerms, $campo);
            $primeiro = $perLevelTerms[0] ?? [];
            $layout[$chave] = [
                'modo' => $compartilhado ? 'compartilhado' : 'por_opcao',
                'valor' => $compartilhado ? (string) ($primeiro[$campo] ?? '') : '',
            ];
        }

        return $layout;
    }

    /**
     * Snapshot como lista de opções com todas as chaves garantidas — aceita
     * o formato antigo (`itens` só como strings, sem condições) e o atual.
     *
     * @return array<int, array<string, mixed>>
     */
    public function normalize(mixed $snapshot): array
    {
        if (! is_array($snapshot)) {
            return [];
        }

        $levels = [];
        foreach (array_values($snapshot) as $index => $level) {
            if (! is_array($level)) {
                continue;
            }

            $nivel = Budget::normalizeLevel($level['nivel'] ?? ($index + 1)) ?? ($index + 1);
            $itens = array_values(array_filter(
                array_map(static fn (mixed $descricao): string => trim((string) $descricao), is_array($level['itens'] ?? null) ? $level['itens'] : []),
                static fn (string $descricao): bool => $descricao !== ''
            ));
            $detalhe = array_values(array_filter(
                is_array($level['itens_detalhe'] ?? null) ? $level['itens_detalhe'] : [],
                static fn (mixed $item): bool => is_array($item)
            ));

            $levels[] = [
                'nivel' => $nivel,
                'label' => trim((string) ($level['label'] ?? '')) ?: Budget::levelLabel($nivel),
                'subtitle' => trim((string) ($level['subtitle'] ?? (Budget::NIVEIS[$nivel]['subtitle'] ?? ''))),
                'subtotal' => round((float) ($level['subtotal'] ?? 0), 2),
                'desconto' => round((float) ($level['desconto'] ?? 0), 2),
                'acrescimo' => round((float) ($level['acrescimo'] ?? 0), 2),
                'total' => round((float) ($level['total'] ?? 0), 2),
                'itens' => $itens,
                'itens_count' => (int) ($level['itens_count'] ?? count($itens)),
                'recomendado' => (bool) ($level['recomendado'] ?? false),
                'itens_detalhe' => $detalhe,
                'condicoes_comerciais' => is_array($level['condicoes_comerciais'] ?? null) ? $level['condicoes_comerciais'] : null,
            ];
        }

        return $levels;
    }

    /**
     * O que mostrar para este orçamento: a projeção viva enquanto há opções
     * a escolher; depois da decisão, o snapshot da última aprovação que
     * registrou opções. Vazio para orçamento comum.
     *
     * `aprovacao.vigente` diz se a opção daquela aprovação ainda é a
     * aprovada — depois de uma edição que reabriu a decisão, o histórico
     * continua visível, mas deixa de ser "a opção contratada".
     *
     * @return array{niveis: array<int, array<string, mixed>>, layout: array<string, array{modo: string, valor: string}>, origem: ?string, aprovacao: ?array<string, mixed>}
     */
    public function forBudget(Budget $budget): array
    {
        if ($budget->hasTiers()) {
            $levels = $this->capture($budget);

            return [
                'niveis' => $levels,
                'layout' => $this->termsLayout($levels),
                'origem' => 'atual',
                'aprovacao' => null,
            ];
        }

        $approval = $this->lastApprovalWithSnapshot($budget);
        if (! $approval instanceof BudgetApproval) {
            return ['niveis' => [], 'layout' => $this->termsLayout([]), 'origem' => null, 'aprovacao' => null];
        }

        $levels = $this->normalize($approval->niveis_snapshot);
        $nivel = Budget::normalizeLevel($approval->nivel);

        return [
            'niveis' => $levels,
            'layout' => $this->termsLayout($levels),
            'origem' => 'aprovacao',
            'aprovacao' => [
                'id' => (int) $approval->id,
                'created_at' => $approval->created_at instanceof Carbon ? $approval->created_at->format('d/m/Y H:i') : '',
                'origem' => trim((string) ($approval->origem ?? '')),
                'origem_label' => self::approvalOriginLabel((string) ($approval->origem ?? '')),
                'usuario_nome' => trim((string) ($approval->usuario_nome ?? '')),
                'nivel' => $nivel,
                'nivel_label' => Budget::levelLabel($nivel),
                'vigente' => $nivel !== null && Budget::normalizeLevel($budget->nivel_aprovado) === $nivel,
            ],
        ];
    }

    /**
     * Resumo de uma linha por opção (para o histórico de aprovações).
     *
     * @return array<int, array{nivel: int, label: string, total: float, itens_count: int, recomendado: bool}>
     */
    public function summarize(mixed $snapshot): array
    {
        return array_map(static fn (array $level): array => [
            'nivel' => (int) $level['nivel'],
            'label' => (string) $level['label'],
            'total' => (float) $level['total'],
            'itens_count' => (int) $level['itens_count'],
            'recomendado' => (bool) $level['recomendado'],
        ], $this->normalize($snapshot));
    }

    public static function approvalOriginLabel(string $origem): string
    {
        return match ($origem) {
            'link_publico' => 'pelo link público',
            'painel' => 'pelo painel',
            default => '',
        };
    }

    private function lastApprovalWithSnapshot(Budget $budget): ?BudgetApproval
    {
        $budget->loadMissing('approvals');

        return $budget->approvals
            ->filter(static fn (BudgetApproval $approval): bool => (string) $approval->acao === 'aprovado'
                && is_array($approval->niveis_snapshot)
                && $approval->niveis_snapshot !== [])
            ->sortByDesc(static fn (BudgetApproval $approval): string => sprintf(
                '%s-%010d',
                $approval->created_at instanceof Carbon ? $approval->created_at->format('YmdHis') : '00000000000000',
                (int) $approval->id
            ))
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function describeItem(BudgetItem $item): array
    {
        return [
            'id' => (int) $item->id,
            'tipo_item' => trim((string) ($item->tipo_item ?? '')),
            'referencia_id' => $item->referencia_id !== null ? (int) $item->referencia_id : null,
            'descricao' => trim((string) ($item->descricao ?? '')),
            'quantidade' => (float) ($item->quantidade ?? 0),
            'valor_unitario' => (float) ($item->valor_unitario ?? 0),
            'desconto' => (float) ($item->desconto ?? 0),
            'acrescimo' => (float) ($item->acrescimo ?? 0),
            'total' => (float) ($item->total ?? 0),
            'niveis' => Budget::normalizeLevels($item->niveis),
            'observacoes' => trim((string) ($item->observacoes ?? '')),
        ];
    }
}
