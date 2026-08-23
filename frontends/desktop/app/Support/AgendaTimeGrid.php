<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Posiciona compromissos com hora marcada numa coluna de 24 horas.
 *
 * Traduz cada item em quatro percentuais (topo, altura, esquerda, largura) que
 * a view aplica como estilo inline. Percentual, e nao pixel, para que a altura
 * da hora fique definida num unico lugar no CSS: mudar `--agenda-hour-height`
 * reposiciona tudo sem tocar em PHP.
 *
 * Compromissos de dia inteiro NAO entram aqui - eles vao para a faixa fixa no
 * topo da grade, como no Google Agenda. Um vencimento nao acontece "as 9h",
 * ele vale o dia todo; coloca-lo numa linha de horario mentiria sobre o dado.
 */
class AgendaTimeGrid
{
    /** Duracao assumida quando o compromisso nao tem fim definido. */
    private const DEFAULT_DURATION_MINUTES = 60;

    /**
     * Altura minima em minutos. Um evento de 10 minutos ficaria com 7px de
     * altura e o titulo seria ilegivel.
     */
    private const MIN_VISIBLE_MINUTES = 30;

    private const MINUTES_IN_DAY = 1440;

    /** Ate esta duracao o bloco nao comporta duas linhas de texto. */
    private const COMPACT_MAX_MINUTES = 45;

    /** Folga entre colunas paralelas, em pontos percentuais. */
    private const LANE_GUTTER = 1.5;

    /**
     * @param  array<int, array<string, mixed>>  $items  Compromissos do dia.
     * @return array{all_day: array<int, array<string, mixed>>, timed: array<int, array<string, mixed>>}
     */
    public static function forDay(array $items): array
    {
        $allDay = [];
        $timed = [];

        foreach ($items as $item) {
            if (! empty($item['dia_inteiro']) || empty($item['hora'])) {
                $allDay[] = $item;

                continue;
            }

            $start = self::minutesFromMidnight((string) $item['inicio_em']);
            $end = self::resolveEnd($item, $start);

            $item['_start'] = $start;
            $item['_end'] = $end;
            $timed[] = $item;
        }

        usort($timed, static fn (array $a, array $b): int => $a['_start'] <=> $b['_start']);

        return [
            'all_day' => $allDay,
            'timed' => self::assignLanes($timed),
        ];
    }

    /**
     * Divide a largura entre compromissos que se sobrepoem no tempo.
     *
     * Trabalha por CLUSTER, nao par a par: A(9-10), B(9:30-11) e C(10:30-12) se
     * encadeiam sem que A e C se toquem, mas os tres precisam da mesma largura,
     * senao C ficaria por cima de B. O cluster fecha quando comeca um item
     * depois do fim mais tardio ja visto.
     *
     * @param  array<int, array<string, mixed>>  $timed  Ja ordenados por inicio.
     * @return array<int, array<string, mixed>>
     */
    private static function assignLanes(array $timed): array
    {
        $positioned = [];
        $cluster = [];
        $clusterEnd = null;

        foreach ($timed as $item) {
            if ($clusterEnd !== null && $item['_start'] >= $clusterEnd) {
                $positioned = array_merge($positioned, self::layoutCluster($cluster));
                $cluster = [];
                $clusterEnd = null;
            }

            $cluster[] = $item;
            $clusterEnd = max($clusterEnd ?? 0, $item['_end']);
        }

        return array_merge($positioned, self::layoutCluster($cluster));
    }

    /**
     * @param  array<int, array<string, mixed>>  $cluster
     * @return array<int, array<string, mixed>>
     */
    private static function layoutCluster(array $cluster): array
    {
        if ($cluster === []) {
            return [];
        }

        /** @var array<int, int> $laneEnds Fim do ultimo item de cada coluna. */
        $laneEnds = [];

        foreach ($cluster as $index => $item) {
            $lane = null;

            foreach ($laneEnds as $laneIndex => $laneEnd) {
                if ($item['_start'] >= $laneEnd) {
                    $lane = $laneIndex;
                    break;
                }
            }

            if ($lane === null) {
                $lane = count($laneEnds);
            }

            $laneEnds[$lane] = $item['_end'];
            $cluster[$index]['_lane'] = $lane;
        }

        $lanes = max(1, count($laneEnds));
        $laneWidth = 100 / $lanes;

        foreach ($cluster as $index => $item) {
            $duration = max(self::MIN_VISIBLE_MINUTES, $item['_end'] - $item['_start']);

            $cluster[$index]['position'] = [
                'top' => round($item['_start'] / self::MINUTES_IN_DAY * 100, 4),
                'height' => round(min($duration, self::MINUTES_IN_DAY - $item['_start']) / self::MINUTES_IN_DAY * 100, 4),
                // Bloco baixo demais para empilhar hora e titulo em duas
                // linhas: a view usa isto para deita-los lado a lado, em vez de
                // deixar o titulo sumir sob o overflow.
                'compact' => $duration <= self::COMPACT_MAX_MINUTES,
                'left' => round($item['_lane'] * $laneWidth, 4),
                // Desconta a folga só quando há vizinho; num item sozinho ela
                // deixaria uma faixa morta na direita da coluna.
                'width' => round($lanes > 1 ? $laneWidth - self::LANE_GUTTER : $laneWidth, 4),
            ];

            unset($cluster[$index]['_start'], $cluster[$index]['_end'], $cluster[$index]['_lane']);
        }

        return array_values($cluster);
    }

    private static function resolveEnd(array $item, int $start): int
    {
        if (empty($item['fim_em'])) {
            return min(self::MINUTES_IN_DAY, $start + self::DEFAULT_DURATION_MINUTES);
        }

        $startDay = CarbonImmutable::parse((string) $item['inicio_em'])->toDateString();
        $endMoment = CarbonImmutable::parse((string) $item['fim_em']);

        // Termina noutro dia: nesta coluna ele vai até a meia-noite. Sem esta
        // checagem, um evento que fecha 01:00 do dia seguinte viraria 60
        // minutos contados do início do dia — ou seja, altura negativa.
        if ($endMoment->toDateString() !== $startDay) {
            return self::MINUTES_IN_DAY;
        }

        return min(self::MINUTES_IN_DAY, max(self::minutesFromMidnight((string) $item['fim_em']), $start + 1));
    }

    private static function minutesFromMidnight(string $iso): int
    {
        $moment = CarbonImmutable::parse($iso);

        return (int) ($moment->hour * 60 + $moment->minute);
    }
}
