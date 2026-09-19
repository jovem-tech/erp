<?php

namespace App\Support;

use App\Models\Budget;
use App\Models\BudgetItem;
use Illuminate\Support\Collection;

/**
 * Matemática de totais do orçamento (desconto/acréscimo em valor ou
 * percentual) e projeção por nível de manutenção.
 *
 * Vive fora dos serviços porque BudgetWorkflowService injeta
 * BudgetApprovalService — o funil de aprovação, que precisa recalcular o
 * total ao aplicar o nível escolhido, não pode injetar o workflow de volta.
 * Estático e sem dependências, no mesmo espírito de ModoPrecificacao.
 */
final class BudgetTotals
{
    public static function adjustmentMode(mixed $value, string $fallback = Budget::ADJUSTMENT_MODE_VALUE): string
    {
        $mode = strtolower(trim((string) $value));

        return in_array($mode, [Budget::ADJUSTMENT_MODE_VALUE, Budget::ADJUSTMENT_MODE_PERCENT], true)
            ? $mode
            : $fallback;
    }

    /**
     * @return array{mode: string, percent: ?float, amount: float}
     */
    public static function adjustment(float $base, mixed $type, mixed $percentual, mixed $amount): array
    {
        $mode = self::adjustmentMode($type);

        if ($mode === Budget::ADJUSTMENT_MODE_PERCENT) {
            $percent = max(0, self::decimal($percentual, 4));

            return [
                'mode' => $mode,
                'percent' => $percent,
                'amount' => round($base * ($percent / 100), 2),
            ];
        }

        return [
            'mode' => $mode,
            'percent' => null,
            'amount' => max(0, self::money($amount)),
        ];
    }

    /**
     * Regrava subtotal/desconto/acréscimo/total do orçamento a partir dos
     * itens (ou do subtotal informado), aplicando o ajuste global gravado.
     */
    public static function recalculate(Budget $budget, ?float $itemsSubtotal = null, mixed $subtotalFallback = null): void
    {
        $subtotal = $itemsSubtotal;

        if ($subtotal === null) {
            $hasItems = BudgetItem::query()->where('orcamento_id', (int) $budget->id)->exists();
            $subtotal = $hasItems
                ? round((float) BudgetItem::query()->where('orcamento_id', (int) $budget->id)->sum('total'), 2)
                : self::money($subtotalFallback ?? $budget->subtotal);
        }

        $totals = self::totalsFor($budget, $subtotal);

        $budget->updateQuietly([
            'subtotal' => $totals['subtotal'],
            'desconto' => $totals['desconto'],
            'desconto_tipo' => $totals['desconto_tipo'],
            'desconto_percentual' => $totals['desconto_percentual'],
            'acrescimo' => $totals['acrescimo'],
            'acrescimo_tipo' => $totals['acrescimo_tipo'],
            'acrescimo_percentual' => $totals['acrescimo_percentual'],
            'total' => $totals['total'],
        ]);
    }

    /**
     * Itens que entram no nível informado — associação exata (cada item
     * declara em quais níveis aparece, sem cascata), na ordem da proposta.
     *
     * @return Collection<int, BudgetItem>
     */
    public static function itemsForLevel(Budget $budget, int $nivel): Collection
    {
        return $budget->items
            ->filter(static function (BudgetItem $item) use ($nivel): bool {
                $niveis = is_array($item->niveis) && $item->niveis !== [] ? $item->niveis : [Budget::NIVEL_MINIMO];

                return in_array($nivel, $niveis, true);
            })
            ->sortBy('ordem')
            ->values();
    }

    /**
     * Uma projeção por nível oferecido — o que o cliente compara na página
     * pública. Aplica o mesmo ajuste global do orçamento sobre o subtotal de
     * cada nível, então o último nível reproduz exatamente o total gravado.
     *
     * Vazio quando o orçamento não tem níveis para escolher.
     *
     * @return array<int, array{nivel: int, label: string, subtitle: string, subtotal: float, desconto: float, acrescimo: float, total: float, itens: array<int, string>, itens_count: int, recomendado: bool}>
     */
    public static function perLevel(Budget $budget): array
    {
        if (! $budget->hasTiers()) {
            return [];
        }

        $levels = [];
        $recommended = Budget::normalizeLevel($budget->nivel_recomendado);

        $describe = static fn (Collection $collection): array => $collection
            ->map(static fn (BudgetItem $item): string => trim((string) ($item->descricao ?? '')))
            ->filter(static fn (string $descricao): bool => $descricao !== '')
            ->values()
            ->all();

        for ($nivel = Budget::NIVEL_MINIMO; $nivel <= $budget->maxLevel(); $nivel++) {
            $items = self::itemsForLevel($budget, $nivel);
            $subtotal = round((float) $items->sum(static fn (BudgetItem $item): float => (float) ($item->total ?? 0)), 2);
            $totals = self::totalsFor($budget, $subtotal);

            $levels[] = [
                'nivel' => $nivel,
                'label' => Budget::levelLabel($nivel),
                'subtitle' => (string) (Budget::NIVEIS[$nivel]['subtitle'] ?? ''),
                'subtotal' => $totals['subtotal'],
                'desconto' => $totals['desconto'],
                'acrescimo' => $totals['acrescimo'],
                'total' => $totals['total'],
                // Tudo o que a opção cobre — lista literal e completa, sem
                // recorte incremental (cada item pode estar em qualquer
                // subconjunto de níveis, não só "a partir de X").
                'itens' => $describe($items),
                'itens_count' => $items->count(),
                'recomendado' => $recommended === $nivel,
            ];
        }

        return $levels;
    }

    /**
     * @return array{subtotal: float, desconto: float, desconto_tipo: string, desconto_percentual: ?float, acrescimo: float, acrescimo_tipo: string, acrescimo_percentual: ?float, total: float}
     */
    private static function totalsFor(Budget $budget, float $subtotal): array
    {
        $discount = self::adjustment($subtotal, $budget->desconto_tipo, $budget->desconto_percentual, $budget->desconto);
        $addition = self::adjustment($subtotal, $budget->acrescimo_tipo, $budget->acrescimo_percentual, $budget->acrescimo);

        return [
            'subtotal' => round($subtotal, 2),
            'desconto' => round($discount['amount'], 2),
            'desconto_tipo' => $discount['mode'],
            'desconto_percentual' => $discount['percent'],
            'acrescimo' => round($addition['amount'], 2),
            'acrescimo_tipo' => $addition['mode'],
            'acrescimo_percentual' => $addition['percent'],
            'total' => round(max(0, $subtotal - $discount['amount'] + $addition['amount']), 2),
        ];
    }

    public static function money(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $normalized = (string) $value;
        $normalized = str_replace(['R$', '%', ' '], '', $normalized);

        if (str_contains($normalized, ',')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        }

        return round((float) $normalized, 2);
    }

    public static function decimal(mixed $value, int $scale = 4): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $normalized = preg_replace('/[^\d,.\-]/u', '', trim((string) $value)) ?? '';
        if ($normalized === '' || $normalized === '-' || $normalized === '.' || $normalized === ',') {
            return 0.0;
        }

        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif ($lastComma !== false) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif ($lastDot !== false) {
            $parts = explode('.', $normalized);
            $lastPart = (string) end($parts);

            if (count($parts) > 2 || strlen($lastPart) === 3) {
                $normalized = str_replace('.', '', $normalized);
            }
        }

        return round((float) $normalized, $scale);
    }
}
