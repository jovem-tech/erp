<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Grades de calendario compartilhadas pelas telas do desktop.
 *
 * Nasceu dentro do FinanceiroReportController (relatorio de Fluxo de Caixa) e
 * foi extraida quando a Agenda passou a precisar da mesma grade mensal. Manter
 * uma copia em cada tela faria as duas divergirem no primeiro ajuste de semana
 * ou de rotulo de mes.
 *
 * A grade so monta as celulas e diz o que cada dia e; o conteudo de cada dia
 * quem preenche e' o callback, porque financeiro e agenda mostram coisas
 * completamente diferentes ali dentro.
 *
 * Semana comeca na segunda em todas as grades - inclusive na do ano, cujos
 * mini-meses precisam bater com o cabecalho da grade mensal.
 */
class CalendarGrid
{
    /** @var array<int, string> */
    public const WEEKDAYS = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];

    /** @var array<int, string> */
    public const WEEKDAYS_LONG = ['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo'];

    /** @var array<int, string> */
    private const MONTHS = [
        1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril',
        5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto',
        9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
    ];

    /**
     * @param  callable(string, CarbonImmutable): array<string, mixed>  $cellBuilder
     *         Recebe a data (Y-m-d) e devolve os dados extras da celula.
     * @return array{month_label: string, weekdays: array<int, string>, weeks: array<int, array<int, array<string, mixed>>>}
     */
    public static function build(CarbonImmutable $monthStart, callable $cellBuilder): array
    {
        $monthEnd = $monthStart->endOfMonth();
        // A grade comeca na segunda da semana do dia 1 e termina no domingo da
        // semana do ultimo dia: por isso ela quase sempre mostra alguns dias do
        // mes vizinho, marcados com in_month = false.
        $gridStart = $monthStart->startOfWeek(CarbonImmutable::MONDAY);
        $gridEnd = $monthEnd->endOfWeek(CarbonImmutable::SUNDAY);

        $weeks = [];
        $week = [];
        $cursor = $gridStart;

        while ($cursor->lte($gridEnd)) {
            $date = $cursor->toDateString();

            $week[] = array_merge([
                'date' => $date,
                'day' => $cursor->day,
                'in_month' => $cursor->year === $monthStart->year && $cursor->month === $monthStart->month,
                'is_today' => $cursor->isToday(),
                'is_weekend' => $cursor->isWeekend(),
            ], $cellBuilder($date, $cursor));

            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }

            $cursor = $cursor->addDay();
        }

        if ($week !== []) {
            $weeks[] = $week;
        }

        return [
            'month_label' => self::monthLabel($monthStart),
            'weekdays' => self::WEEKDAYS,
            'weeks' => $weeks,
        ];
    }

    /**
     * Os sete dias (segunda a domingo) da semana que contem a data ancora.
     *
     * @param  callable(string, CarbonImmutable): array<string, mixed>  $cellBuilder
     * @return array<int, array<string, mixed>>
     */
    public static function week(CarbonImmutable $anchor, callable $cellBuilder): array
    {
        return self::days(
            $anchor->startOfWeek(CarbonImmutable::MONDAY),
            $anchor->endOfWeek(CarbonImmutable::SUNDAY),
            $cellBuilder
        );
    }

    /**
     * Sequencia de dias entre duas datas, inclusive. Base da visao de dia (um
     * dia so) e da de semana.
     *
     * @param  callable(string, CarbonImmutable): array<string, mixed>  $cellBuilder
     * @return array<int, array<string, mixed>>
     */
    public static function days(CarbonImmutable $from, CarbonImmutable $to, callable $cellBuilder): array
    {
        $days = [];
        $cursor = $from->startOfDay();
        $limit = $to->startOfDay();

        while ($cursor->lte($limit)) {
            $date = $cursor->toDateString();

            $days[] = array_merge([
                'date' => $date,
                'day' => $cursor->day,
                'weekday_short' => self::WEEKDAYS[$cursor->dayOfWeekIso - 1] ?? '',
                'weekday_long' => self::WEEKDAYS_LONG[$cursor->dayOfWeekIso - 1] ?? '',
                'is_today' => $cursor->isToday(),
                'is_weekend' => $cursor->isWeekend(),
                'in_month' => true,
            ], $cellBuilder($date, $cursor));

            $cursor = $cursor->addDay();
        }

        return $days;
    }

    /**
     * Doze mini-meses do ano. Cada celula passa pelo mesmo callback das outras
     * grades, entao a visao de ano mostra densidade sem precisar de uma segunda
     * consulta ou de um formato de dado proprio.
     *
     * @param  callable(string, CarbonImmutable): array<string, mixed>  $cellBuilder
     * @return array<int, array<string, mixed>>
     */
    public static function year(int $year, callable $cellBuilder): array
    {
        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $monthStart = CarbonImmutable::create($year, $month, 1)->startOfDay();

            $months[] = [
                'month' => $month,
                'label' => self::MONTHS[$month] ?? (string) $month,
                'month_param' => $monthStart->format('Y-m'),
                'grid' => self::build($monthStart, $cellBuilder),
            ];
        }

        return $months;
    }

    /** Rotulo curto de um dia: "Domingo, 23 de agosto de 2026". */
    public static function dayLabel(CarbonImmutable $date): string
    {
        return sprintf(
            '%s, %d de %s de %d',
            self::WEEKDAYS_LONG[$date->dayOfWeekIso - 1] ?? '',
            $date->day,
            self::MONTHS[(int) $date->month] ?? '',
            $date->year
        );
    }

    /**
     * Rotulo de uma semana. Encurta quando ela nao atravessa mes nem ano -
     * "24 a 30 de agosto de 2026" em vez de repetir mes e ano nas duas pontas.
     */
    public static function weekLabel(CarbonImmutable $anchor): string
    {
        $start = $anchor->startOfWeek(CarbonImmutable::MONDAY);
        $end = $anchor->endOfWeek(CarbonImmutable::SUNDAY);

        $startMonth = self::MONTHS[(int) $start->month] ?? '';
        $endMonth = self::MONTHS[(int) $end->month] ?? '';

        if ($start->year !== $end->year) {
            return sprintf(
                '%d de %s de %d a %d de %s de %d',
                $start->day, $startMonth, $start->year,
                $end->day, $endMonth, $end->year
            );
        }

        if ($start->month !== $end->month) {
            return sprintf(
                '%d de %s a %d de %s de %d',
                $start->day, $startMonth, $end->day, $endMonth, $end->year
            );
        }

        return sprintf('%d a %d de %s de %d', $start->day, $end->day, $startMonth, $end->year);
    }

    public static function monthLabel(CarbonImmutable $monthStart): string
    {
        $monthName = self::MONTHS[(int) $monthStart->month] ?? $monthStart->format('m');

        return ucfirst($monthName).' de '.$monthStart->year;
    }
}
