<?php

namespace App\Support;

/**
 * Como o preco daquela linha foi decidido.
 *
 * Substitui a string 'manual' literal, que ate specs/037 estava hard-coded em
 * cinco lugares (BudgetWorkflowService, orcamentos-form.js x3 e um hidden do
 * item-row) — o que tornava a coluna `modo_precificacao` incapaz de responder
 * a unica pergunta que ela existe para responder.
 *
 * O modo NAO e enviado pelo cliente: e RESOLVIDO no servidor comparando o
 * preco cobrado com o recomendado e com o de tabela. Cliente que informa o
 * proprio modo transformaria a coluna em declaracao de intencao, nao em fato.
 */
final class ModoPrecificacao
{
    /** Bateu com o valor que o motor recomendou. */
    public const SUGERIDO = 'sugerido';

    /** Bateu com o preco de catalogo, e o catalogo diverge do recomendado. */
    public const TABELA = 'tabela';

    /** Operador digitou um valor proprio. */
    public const MANUAL = 'manual';

    /** Item sem cadastro vinculado — nao ha com o que comparar. */
    public const AVULSO = 'avulso';

    /** Tolerancia de centavo na comparacao (arredondamento de exibicao). */
    private const TOLERANCIA = 0.01;

    /**
     * @return array<int, string>
     */
    public static function codigos(): array
    {
        return [self::SUGERIDO, self::TABELA, self::MANUAL, self::AVULSO];
    }

    public static function normalizar(?string $valor): string
    {
        $valor = strtolower(trim((string) $valor));

        return in_array($valor, self::codigos(), true) ? $valor : self::MANUAL;
    }

    /**
     * Resolve o modo comparando o preco cobrado com as referencias.
     *
     * A ordem importa: `sugerido` vence `tabela` quando os dois coincidem,
     * porque seguir a recomendacao e a informacao mais forte.
     */
    public static function resolver(
        float $valorCobrado,
        ?float $valorRecomendado,
        ?float $valorTabela,
        bool $temReferencia
    ): string {
        if (! $temReferencia) {
            return self::AVULSO;
        }

        if ($valorRecomendado !== null && abs($valorCobrado - $valorRecomendado) <= self::TOLERANCIA) {
            return self::SUGERIDO;
        }

        if ($valorTabela !== null && abs($valorCobrado - $valorTabela) <= self::TOLERANCIA) {
            return self::TABELA;
        }

        return self::MANUAL;
    }
}
