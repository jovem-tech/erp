<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Competência mensal `YYYY-MM` resolvida em intervalo de datas.
 *
 * Existe porque a mesma regra — validar o formato, cair no mês corrente quando
 * ele não bate, abrir o intervalo do primeiro ao último dia — estava escrita em
 * quatro lugares: `FinanceiroReportService::resolveMonthRange()`, o
 * `FinanceiroReportController` do backend, o do desktop e agora o Anexo X.
 *
 * O fallback silencioso para o mês corrente é deliberado e vem do
 * comportamento original: um `?mes=` malformado na URL não pode derrubar um
 * relatório, e o rótulo devolvido deixa claro qual mês acabou sendo lido.
 * Quem precisa recusar entrada inválida valida antes de chamar — é o caso das
 * rotas de fechamento do Anexo X, que não podem congelar o mês errado por
 * causa de um querystring torto.
 */
final class PeriodoMensal
{
    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string} início, fim e rótulo `MM/AAAA`
     */
    public static function resolver(string $mes): array
    {
        $mes = self::valido($mes) ? $mes : now()->format('Y-m');
        $inicio = CarbonImmutable::createFromFormat('Y-m-d', $mes.'-01')->startOfMonth();
        $fim = $inicio->endOfMonth();

        return [$inicio, $fim, $inicio->format('m/Y')];
    }

    public static function valido(string $mes): bool
    {
        return preg_match('/^\d{4}-\d{2}$/', $mes) === 1;
    }

    /**
     * Competência normalizada (`YYYY-MM`), com o mesmo fallback de `resolver()`.
     */
    public static function normalizar(string $mes): string
    {
        return self::valido($mes) ? $mes : now()->format('Y-m');
    }
}
