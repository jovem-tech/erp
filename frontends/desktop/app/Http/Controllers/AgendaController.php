<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\AgendaService;
use App\Support\AgendaTimeGrid;
use App\Support\CalendarGrid;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class AgendaController extends DesktopController
{
    /** Ordem em que as visões aparecem no seletor. */
    private const VIEW_MODES = ['dia', 'semana', 'mes', 'ano', 'lista'];

    public function __construct(
        private readonly AgendaService $agendaService
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $viewMode = $this->resolveViewMode($request);
        $cursor = $this->resolveCursor($request);
        [$from, $to] = $this->windowFor($viewMode, $cursor);

        $filters = [
            'de' => $from->toDateString(),
            'ate' => $to->toDateString(),
            'tipo' => trim((string) $request->query('tipo', '')),
            'status' => trim((string) $request->query('status', '')),
            'responsavel_id' => (int) $request->query('responsavel_id', 0),
        ];

        try {
            $payload = $this->agendaService->list(array_filter(
                $filters,
                static fn ($value): bool => $value !== '' && $value !== 0
            ));
            $summary = $this->agendaService->summary();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return redirect()->route('dashboard')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('dashboard')->with('error', 'Não foi possível carregar a agenda agora.');
        }

        $compromissos = is_array($payload['compromissos'] ?? null) ? $payload['compromissos'] : [];
        $byDate = $this->groupByDate($compromissos);

        // Estado da conexão é informativo no topo da tela; se falhar, a agenda
        // continua sendo a razão de a página existir.
        try {
            $google = $this->agendaService->googleStatus();
        } catch (Throwable) {
            $google = [];
        }

        return view('agenda.index', array_merge([
            'pageTitle' => 'Agenda',
            'viewMode' => $viewMode,
            'cursor' => $cursor->toDateString(),
            'periodLabel' => $this->periodLabel($viewMode, $cursor),
            'anterior' => $this->step($viewMode, $cursor, -1)->toDateString(),
            'proximo' => $this->step($viewMode, $cursor, 1)->toDateString(),
            'hojeCursor' => CarbonImmutable::today()->toDateString(),
            'compromissos' => $compromissos,
            'resumo' => $summary,
            'tipos' => is_array($payload['tipos'] ?? null) ? $payload['tipos'] : [],
            'podeVerTodos' => (bool) ($payload['pode_ver_todos'] ?? false),
            'filtros' => $filters,
            'google' => is_array($google['summary'] ?? null) ? $google['summary'] : [],
        ], $this->viewData($viewMode, $cursor, $byDate)));
    }

    /**
     * Cada visão recebe só a estrutura de que precisa. Montar as quatro em toda
     * requisição custaria doze grades mensais para exibir um único dia.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $byDate
     * @return array<string, mixed>
     */
    private function viewData(string $viewMode, CarbonImmutable $cursor, array $byDate): array
    {
        $cell = fn (string $date): array => $this->dayCell($byDate, $date);

        return match ($viewMode) {
            'dia' => [
                'dias' => CalendarGrid::days($cursor, $cursor, $cell),
                'horas' => $this->hourLabels(),
            ],
            'semana' => [
                'dias' => CalendarGrid::week($cursor, $cell),
                'horas' => $this->hourLabels(),
            ],
            'ano' => [
                'meses' => CalendarGrid::year((int) $cursor->year, $cell),
            ],
            'lista' => [],
            default => [
                'calendar' => CalendarGrid::build($cursor->startOfMonth(), $cell),
            ],
        };
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $byDate
     * @return array<string, mixed>
     */
    private function dayCell(array $byDate, string $date): array
    {
        $items = $byDate[$date] ?? [];
        $split = AgendaTimeGrid::forDay($items);

        return [
            'items' => $items,
            'all_day' => $split['all_day'],
            'timed' => $split['timed'],
            'total' => count($items),
            'atrasados' => count(array_filter(
                $items,
                static fn (array $item): bool => (bool) ($item['atrasado'] ?? false)
            )),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $compromissos
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupByDate(array $compromissos): array
    {
        $byDate = [];

        foreach ($compromissos as $item) {
            $date = (string) ($item['data'] ?? '');
            if ($date !== '') {
                $byDate[$date][] = $item;
            }
        }

        return $byDate;
    }

    /** @return array<int, string> */
    private function hourLabels(): array
    {
        return array_map(
            static fn (int $hour): string => sprintf('%02d:00', $hour),
            range(0, 23)
        );
    }

    /**
     * Janela consultada na API. Dia e semana pedem exatamente o que mostram;
     * mês e ano precisam das bordas, porque a grade mensal exibe dias do mês
     * vizinho e sem eles a primeira e a última semana apareceriam vazias.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function windowFor(string $viewMode, CarbonImmutable $cursor): array
    {
        return match ($viewMode) {
            'dia' => [$cursor, $cursor],
            'semana' => [
                $cursor->startOfWeek(CarbonImmutable::MONDAY),
                $cursor->endOfWeek(CarbonImmutable::SUNDAY),
            ],
            'ano' => [
                $cursor->startOfYear()->startOfWeek(CarbonImmutable::MONDAY),
                $cursor->endOfYear()->endOfWeek(CarbonImmutable::SUNDAY),
            ],
            default => [
                $cursor->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY),
                $cursor->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY),
            ],
        };
    }

    private function periodLabel(string $viewMode, CarbonImmutable $cursor): string
    {
        return match ($viewMode) {
            'dia' => CalendarGrid::dayLabel($cursor),
            'semana' => CalendarGrid::weekLabel($cursor),
            'ano' => (string) $cursor->year,
            default => CalendarGrid::monthLabel($cursor->startOfMonth()),
        };
    }

    /** Avança ou recua uma unidade da visão corrente. */
    private function step(string $viewMode, CarbonImmutable $cursor, int $direction): CarbonImmutable
    {
        return match ($viewMode) {
            'dia' => $cursor->addDays($direction),
            'semana' => $cursor->addWeeks($direction),
            'ano' => $cursor->addYears($direction),
            // NoOverflow: 31/03 recuando um mês precisa cair em fevereiro, não
            // saltar para 02/03 ou 03/03.
            default => $cursor->addMonthsNoOverflow($direction),
        };
    }

    public function store(Request $request): RedirectResponse

    {
        $validated = $this->validatePayload($request);

        try {
            $this->agendaService->create($validated);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return $this->backToAgenda($request)->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->backToAgenda($request)->with('error', 'Não foi possível criar o compromisso.');
        }

        return $this->backToAgenda($request)->with('success', 'Compromisso criado.');
    }

    public function update(Request $request, int $compromisso): RedirectResponse
    {
        $validated = $this->validatePayload($request);

        try {
            $this->agendaService->update($compromisso, $validated);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return $this->backToAgenda($request)->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->backToAgenda($request)->with('error', 'Não foi possível atualizar o compromisso.');
        }

        return $this->backToAgenda($request)->with('success', 'Compromisso atualizado.');
    }

    public function complete(Request $request, int $compromisso): RedirectResponse
    {
        return $this->simpleAction(
            $request,
            fn (): array => $this->agendaService->complete($compromisso),
            'Compromisso concluído.'
        );
    }

    public function reopen(Request $request, int $compromisso): RedirectResponse
    {
        return $this->simpleAction(
            $request,
            fn (): array => $this->agendaService->reopen($compromisso),
            'Compromisso reaberto.'
        );
    }

    public function destroy(Request $request, int $compromisso): RedirectResponse
    {
        return $this->simpleAction(
            $request,
            function () use ($compromisso): array {
                $this->agendaService->delete($compromisso);

                return [];
            },
            'Compromisso excluído.'
        );
    }

    /** @param callable(): array<string, mixed> $action */
    private function simpleAction(Request $request, callable $action, string $successMessage): RedirectResponse
    {
        try {
            $action();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return $this->backToAgenda($request)->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->backToAgenda($request)->with('error', 'Não foi possível concluir a ação.');
        }

        return $this->backToAgenda($request)->with('success', $successMessage);
    }

    /** @return array<string, mixed> */
    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'titulo' => ['required', 'string', 'max:180'],
            'descricao' => ['nullable', 'string', 'max:5000'],
            'data' => ['required', 'date_format:Y-m-d'],
            'hora' => ['nullable', 'date_format:H:i'],
            'dia_inteiro' => ['nullable', 'boolean'],
            'prioridade' => ['nullable', 'in:baixa,normal,alta'],
            'responsavel_id' => ['nullable', 'integer'],
            'lembrete_minutos' => ['nullable', 'integer', 'min:0', 'max:40320'],
        ], [], [
            'titulo' => 'título',
            'data' => 'data',
            'hora' => 'hora',
        ]);

        $diaInteiro = (bool) ($validated['dia_inteiro'] ?? false);
        $hora = trim((string) ($validated['hora'] ?? ''));

        // Sem hora informada o compromisso vira de dia inteiro: e o que o
        // usuario quis dizer ao deixar o campo vazio.
        if ($hora === '') {
            $diaInteiro = true;
        }

        return [
            'titulo' => $validated['titulo'],
            'descricao' => $validated['descricao'] ?? null,
            'inicio_em' => $validated['data'].' '.($diaInteiro ? '00:00' : $hora).':00',
            'dia_inteiro' => $diaInteiro,
            'prioridade' => $validated['prioridade'] ?? 'normal',
            'responsavel_id' => (int) ($validated['responsavel_id'] ?? 0) ?: null,
            'lembrete_minutos' => array_key_exists('lembrete_minutos', $validated)
                ? (int) $validated['lembrete_minutos']
                : null,
        ];
    }

    /**
     * Volta para o mês que o usuário estava vendo, não para o mês corrente.
     */
    private function backToAgenda(Request $request): RedirectResponse
    {
        return redirect()->route('agenda.index', array_filter([
            'data' => trim((string) $request->input('data', '')),
            'view' => trim((string) $request->input('view', '')),
        ]));
    }

    /**
     * Cursor único de navegação (Y-m-d) para todas as visões — é o que permite
     * trocar de dia para semana ou mês sem perder a data que se está olhando.
     * `mes` (Y-m) continua aceito para não quebrar link antigo.
     */
    private function resolveCursor(Request $request): CarbonImmutable
    {
        $data = trim((string) $request->query('data', ''));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) === 1) {
            try {
                return CarbonImmutable::createFromFormat('Y-m-d', $data)->startOfDay();
            } catch (Throwable) {
                // Data sintaticamente válida mas inexistente (2026-02-31).
            }
        }

        $mes = trim((string) $request->query('mes', ''));

        if (preg_match('/^\d{4}-\d{2}$/', $mes) === 1) {
            return CarbonImmutable::createFromFormat('Y-m-d', $mes.'-01')->startOfDay();
        }

        return CarbonImmutable::today();
    }

    private function resolveViewMode(Request $request): string
    {
        $view = strtolower(trim((string) $request->query('view', 'mes')));

        return in_array($view, self::VIEW_MODES, true) ? $view : 'mes';
    }
}
