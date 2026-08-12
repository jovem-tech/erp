<?php

namespace App\Support;

/**
 * Desconto/acréscimo comercial em R$ ou %.
 *
 * Extração dos helpers privados de BudgetWorkflowService (resolveMoney,
 * resolveDecimal, resolveAdjustmentMode, resolveAdjustment) para que Vendas
 * calcule ajustes exatamente como Orçamentos — inclusive na tolerância a
 * entradas com máscara pt-BR ("R$ 1.234,56", "10,5%"), que é o que o desktop
 * envia.
 *
 * Orçamentos continua com a cópia própria por ora: unificar os dois exige mexer
 * num serviço de ~1.900 linhas e não pertence à entrega do módulo de vendas
 * (ver specs/027-vendas-balcao-pdv/spec.md).
 */
final class CommercialAdjustment
{
    public const MODE_VALUE = 'valor';
    public const MODE_PERCENT = 'percentual';

    /**
     * Normaliza um valor monetário vindo do formulário.
     *
     * Aceita "R$ 1.234,56", "1234.56" e "1.234,56": quando há vírgula, o ponto
     * é separador de milhar.
     */
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

    /**
     * Normaliza um decimal com escala arbitrária (percentuais, quantidades).
     *
     * Quando vírgula e ponto convivem, o último separador manda; um ponto
     * isolado seguido de exatamente 3 dígitos é tratado como milhar ("1.234").
     */
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

    public static function mode(mixed $value, string $fallback = self::MODE_VALUE): string
    {
        $mode = strtolower(trim((string) $value));

        return in_array($mode, [self::MODE_VALUE, self::MODE_PERCENT], true) ? $mode : $fallback;
    }

    /**
     * Resolve um ajuste sobre uma base.
     *
     * No modo percentual o valor em R$ é derivado da base e o percentual fica
     * guardado; no modo valor o percentual é nulo. Ajuste negativo é zerado —
     * desconto e acréscimo têm campos próprios, um nunca vira o outro.
     *
     * @return array{mode: string, percent: float|null, amount: float}
     */
    public static function resolve(float $base, mixed $type, mixed $percentual, mixed $amount): array
    {
        $mode = self::mode($type);

        if ($mode === self::MODE_PERCENT) {
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
}
