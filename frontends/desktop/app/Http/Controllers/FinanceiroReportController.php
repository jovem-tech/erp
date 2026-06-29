<?php

namespace App\Http\Controllers;

use App\Services\FinanceiroService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinanceiroReportController extends DesktopController
{
    public function __construct(
        private readonly FinanceiroService $financeiroService
    ) {
    }

    public function dre(Request $request): View
    {
        $mes = $this->resolveMes($request);

        return view('financeiro.relatorios.dre', [
            'pageTitle' => 'DRE por competência',
            'mes' => $mes,
            'dre' => $this->financeiroService->dre($mes),
        ]);
    }

    public function dreCaixa(Request $request): View
    {
        $mes = $this->resolveMes($request);

        return view('financeiro.relatorios.dre', [
            'pageTitle' => 'DRE de caixa',
            'mes' => $mes,
            'dre' => $this->financeiroService->dreCaixa($mes),
            'caixa' => true,
        ]);
    }

    public function fluxoCaixa(Request $request): View
    {
        $mes = $this->resolveMes($request);
        $viewMode = $this->resolveViewMode($request);
        $monthStart = CarbonImmutable::createFromFormat('Y-m-d', $mes . '-01');
        $fluxo = $this->financeiroService->fluxoCaixa($mes);
        $calendar = $this->buildCalendar($monthStart, $fluxo['linhas_diarias'] ?? []);

        return view('financeiro.relatorios.fluxo-caixa', [
            'pageTitle' => 'Fluxo de caixa',
            'mes' => $mes,
            'viewMode' => $viewMode,
            'mesAnterior' => $monthStart->subMonthNoOverflow()->format('Y-m'),
            'mesProximo' => $monthStart->addMonthNoOverflow()->format('Y-m'),
            'calendar' => $calendar,
            'fluxo' => $fluxo,
        ]);
    }

    private function resolveMes(Request $request): string
    {
        $mes = trim((string) $request->query('mes', ''));

        return preg_match('/^\d{4}-\d{2}$/', $mes) === 1 ? $mes : now()->format('Y-m');
    }

    private function resolveViewMode(Request $request): string
    {
        $view = strtolower(trim((string) $request->query('view', 'list')));

        return in_array($view, ['calendar', 'calendario'], true) ? 'calendar' : 'list';
    }

    /**
     * @param array<int, array<string, mixed>> $linhasDiarias
     * @return array{month_label: string, weekdays: array<int, string>, weeks: array<int, array<int, array<string, mixed>>>}
     */
    private function buildCalendar(CarbonImmutable $monthStart, array $linhasDiarias): array
    {
        $monthEnd = $monthStart->endOfMonth();
        $gridStart = $monthStart->startOfWeek(CarbonImmutable::MONDAY);
        $gridEnd = $monthEnd->endOfWeek(CarbonImmutable::SUNDAY);
        $rowsByDate = collect($linhasDiarias)->keyBy('data');

        $weeks = [];
        $week = [];
        $cursor = $gridStart;

        while ($cursor->lte($gridEnd)) {
            $date = $cursor->toDateString();
            $row = $rowsByDate->get($date, []);
            $entries = round((float) ($row['entradas_realizadas'] ?? 0), 2);
            $exits = round((float) ($row['saidas_realizadas'] ?? 0), 2);
            $balance = round((float) ($row['saldo_realizado'] ?? 0), 2);
            $inMonth = $cursor->year === $monthStart->year && $cursor->month === $monthStart->month;

            $week[] = [
                'date' => $date,
                'day' => $cursor->day,
                'in_month' => $inMonth,
                'is_today' => $cursor->isToday(),
                'entries' => $entries,
                'exits' => $exits,
                'balance' => $balance,
                'net' => round($entries - $exits, 2),
                'has_movement' => ($entries !== 0.0 || $exits !== 0.0),
            ];

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
            'month_label' => $this->formatMonthLabel($monthStart),
            'weekdays' => ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'],
            'weeks' => $weeks,
        ];
    }

    private function formatMonthLabel(CarbonImmutable $monthStart): string
    {
        $months = [
            1 => 'janeiro',
            2 => 'fevereiro',
            3 => 'março',
            4 => 'abril',
            5 => 'maio',
            6 => 'junho',
            7 => 'julho',
            8 => 'agosto',
            9 => 'setembro',
            10 => 'outubro',
            11 => 'novembro',
            12 => 'dezembro',
        ];

        $monthName = $months[(int) $monthStart->month] ?? $monthStart->format('m');

        return ucfirst($monthName) . ' de ' . $monthStart->year;
    }
}
