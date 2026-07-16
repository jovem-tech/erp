<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\DesktopOrderStatusFlowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class OrderStatusFlowController extends DesktopController
{
    public function __construct(
        private readonly DesktopOrderStatusFlowService $orderStatusFlowService
    ) {
    }

    public function index(Request $request): View|RedirectResponse
    {
        try {
            $result = $this->orderStatusFlowService->index();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('dashboard')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('dashboard')->with('error', $exception->getMessage());
        }

        $statuses = $result['statuses'];
        $transitions = $result['transitions'];
        $workflow = $this->buildWorkflowViewData($statuses, $transitions);

        return view('knowledge.os-flow.index', array_merge([
            'pageTitle' => 'Fluxo de Trabalho OS',
            'statuses' => $statuses,
            'transitions' => $transitions,
        ], $workflow));
    }

    public function storeStatus(Request $request): RedirectResponse
    {
        $payload = $this->validatedStatusPayload($request, true);

        try {
            $this->orderStatusFlowService->createStatus($payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.os-flow.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput($request->except(['status_final', 'status_pausa', 'gera_evento_crm', 'ativo']))
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput($request->except(['status_final', 'status_pausa', 'gera_evento_crm', 'ativo']))
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos do status.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput($request->except(['status_final', 'status_pausa', 'gera_evento_crm', 'ativo']))
                ->with('error', 'Não foi possível cadastrar o status agora. Tente novamente.');
        }

        return redirect()
            ->route('knowledge.os-flow.index')
            ->with('success', 'Status criado com sucesso.');
    }

    public function updateStatus(Request $request, int $status): RedirectResponse
    {
        $payload = $this->validatedStatusPayload($request, false);

        try {
            $this->orderStatusFlowService->updateStatus($status, $payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.os-flow.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput($request->except(['status_final', 'status_pausa', 'gera_evento_crm', 'ativo']))
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput($request->except(['status_final', 'status_pausa', 'gera_evento_crm', 'ativo']))
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos do status.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput($request->except(['status_final', 'status_pausa', 'gera_evento_crm', 'ativo']))
                ->with('error', 'Não foi possível atualizar o status agora. Tente novamente.');
        }

        return redirect()
            ->route('knowledge.os-flow.index')
            ->with('success', 'Status atualizado com sucesso.');
    }

    public function updateTransitions(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'transitions' => ['nullable', 'array'],
            'transitions.*' => ['array'],
            'transitions.*.*' => ['integer'],
        ], [], [
            'transitions' => 'matriz de transições',
        ]);

        try {
            $this->orderStatusFlowService->updateTransitions($validated['transitions'] ?? []);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.os-flow.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('knowledge.os-flow.index')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('knowledge.os-flow.index')
                ->with('error', 'Não foi possível atualizar a matriz de transições agora. Tente novamente.');
        }

        return redirect()
            ->route('knowledge.os-flow.index')
            ->with('success', 'Matriz de transições atualizada com sucesso.');
    }

    /**
     * @param array<int, array<string, mixed>> $statuses
     * @param array<int, array<string, mixed>> $transitions
     * @return array<string, mixed>
     */
    private function buildWorkflowViewData(array $statuses, array $transitions): array
    {
        $statusCollection = collect($statuses)
            ->sortBy(static fn (array $status): string => sprintf(
                '%010d-%s',
                (int) ($status['ordem_fluxo'] ?? 0),
                mb_strtolower(trim((string) ($status['nome'] ?? '')))
            ))
            ->values();

        $statusById = $statusCollection
            ->keyBy(static fn (array $status): int => (int) ($status['id'] ?? 0))
            ->all();

        $activeStatuses = $statusCollection
            ->filter(static fn (array $status): bool => (bool) ($status['ativo'] ?? false))
            ->values();

        $activeTransitions = collect($transitions)
            ->filter(static fn (array $transition): bool => (bool) ($transition['ativo'] ?? false))
            ->values();

        $transitionLookup = $activeTransitions
            ->map(static fn (array $transition): string => (int) ($transition['status_origem_id'] ?? 0) . '-' . (int) ($transition['status_destino_id'] ?? 0))
            ->values()
            ->all();

        $transitionsByOrigin = $activeTransitions
            ->groupBy(static fn (array $transition): int => (int) ($transition['status_origem_id'] ?? 0))
            ->all();

        $workflowGroups = $statusCollection
            ->groupBy(static fn (array $status): string => trim((string) ($status['grupo_macro'] ?? '')))
            ->sortBy(static fn ($groupStatuses): int => (int) $groupStatuses->min('ordem_fluxo'))
            ->map(function ($groupStatuses, string $grupoMacro) use ($statusById, $transitionsByOrigin): array {
                $groupStatuses = $groupStatuses->values();

                $statuses = $groupStatuses
                    ->map(function (array $status) use ($statusById, $transitionsByOrigin): array {
                        $statusId = (int) ($status['id'] ?? 0);
                        $targets = $this->resolveOutgoingTransitions($statusId, $statusById, $transitionsByOrigin);

                        return array_merge($status, [
                            'flow_state_label' => $this->humanizeFlowState((string) ($status['estado_fluxo_padrao'] ?? '')),
                            'outgoing_transitions' => $targets,
                            'outgoing_count' => count($targets),
                            'accent_color' => $this->normalizeWorkflowColor((string) ($status['cor'] ?? '')),
                        ]);
                    })
                    ->values();

                return [
                    'key' => $grupoMacro,
                    'label' => $this->humanizeMacroGroup($grupoMacro),
                    'description' => $this->describeMacroGroup($grupoMacro),
                    'accent' => $this->macroGroupAccent($grupoMacro),
                    'soft_accent' => $this->macroGroupSoftAccent($grupoMacro),
                    'status_count' => $statuses->count(),
                    'active_status_count' => $statuses->filter(static fn (array $status): bool => (bool) ($status['ativo'] ?? false))->count(),
                    'final_count' => $statuses->filter(static fn (array $status): bool => (bool) ($status['status_final'] ?? false))->count(),
                    'pause_count' => $statuses->filter(static fn (array $status): bool => (bool) ($status['status_pausa'] ?? false))->count(),
                    'transition_count' => $statuses->sum(static fn (array $status): int => (int) ($status['outgoing_count'] ?? 0)),
                    'statuses' => $statuses->all(),
                ];
            })
            ->values()
            ->all();

        $transitionMatrix = $this->buildTransitionMatrix($activeStatuses);

        return [
            'workflowGroups' => $workflowGroups,
            'transitionMatrix' => $transitionMatrix,
            'workflowTrail' => collect($workflowGroups)
                ->pluck('label')
                ->filter(static fn (mixed $label): bool => is_string($label) && trim($label) !== '')
                ->values()
                ->all(),
            'workflowStats' => [
                'status_count' => $statusCollection->count(),
                'active_status_count' => $activeStatuses->count(),
                'inactive_status_count' => max(0, $statusCollection->count() - $activeStatuses->count()),
                'final_status_count' => $statusCollection->filter(static fn (array $status): bool => (bool) ($status['status_final'] ?? false))->count(),
                'pause_status_count' => $statusCollection->filter(static fn (array $status): bool => (bool) ($status['status_pausa'] ?? false))->count(),
                'group_count' => count($workflowGroups),
                'transition_count' => $activeTransitions->count(),
            ],
            'activeStatuses' => $activeStatuses->all(),
            'transitionLookup' => $transitionLookup,
        ];
    }

    /**
     * Monta a estrutura em dois níveis (super-grupo → macrofase → status) que
     * a "Matriz operacional de transições" usa para agrupar linhas e colunas.
     * Só status ativos, mantendo paridade com a matriz plana anterior.
     *
     * @param \Illuminate\Support\Collection<int, array<string, mixed>> $activeStatuses
     * @return array{super_groups: array<int, array<string, mixed>>, ordered_statuses: array<int, array<string, mixed>>}
     */
    private function buildTransitionMatrix(\Illuminate\Support\Collection $activeStatuses): array
    {
        $superGroups = $activeStatuses
            ->groupBy(fn (array $status): string => $this->superGroupForMacro((string) ($status['grupo_macro'] ?? ''))['key'])
            ->map(function ($groupStatuses, string $superKey): array {
                $meta = $this->superGroupForMacro((string) ($groupStatuses->first()['grupo_macro'] ?? ''));

                $macros = $groupStatuses
                    ->groupBy(static fn (array $status): string => trim((string) ($status['grupo_macro'] ?? '')))
                    ->sortBy(static fn ($macroStatuses): int => (int) $macroStatuses->min('ordem_fluxo'))
                    ->map(function ($macroStatuses, string $grupoMacro): array {
                        $ordered = $macroStatuses
                            ->sortBy(static fn (array $status): int => (int) ($status['ordem_fluxo'] ?? 0))
                            ->values();

                        return [
                            'key' => $grupoMacro,
                            'label' => $this->humanizeMacroGroup($grupoMacro),
                            'accent' => $this->macroGroupAccent($grupoMacro),
                            'soft_accent' => $this->macroGroupSoftAccent($grupoMacro),
                            'span' => $ordered->count(),
                            'statuses' => $ordered->all(),
                        ];
                    })
                    ->values();

                return [
                    'key' => $meta['key'],
                    'label' => $meta['label'],
                    'ordem' => $meta['ordem'],
                    'accent' => $meta['accent'],
                    'soft_accent' => $meta['soft_accent'],
                    'span' => (int) $macros->sum('span'),
                    'macros' => $macros->all(),
                ];
            })
            ->sortBy('ordem')
            ->values();

        $orderedStatuses = $superGroups
            ->flatMap(static fn (array $super): array => collect($super['macros'])
                ->flatMap(static fn (array $macro): array => $macro['statuses'])
                ->all())
            ->values()
            ->all();

        return [
            'super_groups' => $superGroups->all(),
            'ordered_statuses' => $orderedStatuses,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $statusById
     * @param array<int, array<int, array<string, mixed>>> $transitionsByOrigin
     * @return array<int, array<string, mixed>>
     */
    private function resolveOutgoingTransitions(int $statusId, array $statusById, array $transitionsByOrigin): array
    {
        $statusRow = $statusById[$statusId] ?? null;
        if (! is_array($statusRow)) {
            return [];
        }

        $transitionRows = collect($transitionsByOrigin[$statusId] ?? [])
            ->sortBy(function (array $transition) use ($statusById): int {
                $targetId = (int) ($transition['status_destino_id'] ?? 0);
                $targetStatus = $statusById[$targetId] ?? null;

                return (int) ($targetStatus['ordem_fluxo'] ?? PHP_INT_MAX);
            })
            ->values();

        return $transitionRows
            ->map(function (array $transition) use ($statusById): ?array {
                $targetId = (int) ($transition['status_destino_id'] ?? 0);
                $targetStatus = $statusById[$targetId] ?? null;

                if (! is_array($targetStatus)) {
                    return null;
                }

                return [
                    'id' => $targetId,
                    'codigo' => (string) ($targetStatus['codigo'] ?? ''),
                    'nome' => (string) ($targetStatus['nome'] ?? ''),
                    'grupo_macro' => (string) ($targetStatus['grupo_macro'] ?? ''),
                    'grupo_macro_label' => $this->humanizeMacroGroup((string) ($targetStatus['grupo_macro'] ?? '')),
                    'cor' => $this->normalizeWorkflowColor((string) ($targetStatus['cor'] ?? '')),
                    'accent_color' => $this->normalizeWorkflowColor((string) ($targetStatus['cor'] ?? '')),
                    'status_final' => (bool) ($targetStatus['status_final'] ?? false),
                    'status_pausa' => (bool) ($targetStatus['status_pausa'] ?? false),
                    'ativo' => (bool) ($targetStatus['ativo'] ?? false),
                ];
            })
            ->filter(static fn (?array $target): bool => is_array($target))
            ->unique(static fn (array $target): int => (int) ($target['id'] ?? 0))
            ->values()
            ->all();
    }

    private function humanizeMacroGroup(string $grupoMacro): string
    {
        return match (mb_strtolower(trim($grupoMacro))) {
            'recepcao' => 'Recepção',
            'diagnostico' => 'Diagnóstico',
            'orcamento' => 'Orçamento',
            'execucao' => 'Execução',
            'qualidade' => 'Qualidade',
            'interrupcao' => 'Interrupção',
            'concluido' => 'Concluído',
            'finalizado_sem_reparo' => 'Finalizado sem Reparo',
            'encerrado' => 'Encerramento',
            'cancelado' => 'Cancelado',
            default => $this->humanizeSlug($grupoMacro),
        };
    }

    /**
     * Mapeia cada macrofase (grupo_macro) para um dos 3 super-grupos do fluxo:
     * Início, Execução e Término. Usado para agrupar em dois níveis a matriz
     * operacional de transições.
     *
     * @return array{key: string, label: string, ordem: int, accent: string, soft_accent: string}
     */
    private function superGroupForMacro(string $grupoMacro): array
    {
        $key = match (mb_strtolower(trim($grupoMacro))) {
            'recepcao', 'diagnostico', 'orcamento' => 'inicio',
            'execucao', 'qualidade', 'interrupcao' => 'execucao',
            'concluido', 'finalizado_sem_reparo', 'encerrado', 'cancelado' => 'termino',
            default => 'outros',
        };

        return match ($key) {
            'inicio' => ['key' => 'inicio', 'label' => 'Grupo 1 · Início', 'ordem' => 1, 'accent' => '#0ea5e9', 'soft_accent' => 'rgba(14, 165, 233, 0.12)'],
            'execucao' => ['key' => 'execucao', 'label' => 'Grupo 2 · Execução', 'ordem' => 2, 'accent' => '#16a34a', 'soft_accent' => 'rgba(22, 163, 74, 0.12)'],
            'termino' => ['key' => 'termino', 'label' => 'Grupo 3 · Término', 'ordem' => 3, 'accent' => '#64748b', 'soft_accent' => 'rgba(100, 116, 139, 0.12)'],
            default => ['key' => 'outros', 'label' => 'Outros', 'ordem' => 99, 'accent' => '#6f5afc', 'soft_accent' => 'rgba(111, 90, 252, 0.12)'],
        };
    }

    private function describeMacroGroup(string $grupoMacro): string
    {
        return match (mb_strtolower(trim($grupoMacro))) {
            'recepcao' => 'Entrada, conferência inicial e triagem da OS.',
            'diagnostico' => 'Levantamento técnico, verificação de causa e definição do caminho.',
            'orcamento' => 'Aprovação comercial, retorno do cliente e decisão de continuidade.',
            'execucao' => 'Reparo, testes, validação técnica e acompanhamento da solução.',
            'qualidade' => 'Testes operacionais e finais antes de liberar a OS.',
            'interrupcao' => 'Pausas do fluxo: espera de peça, pagamento ou pendência financeira.',
            'concluido' => 'Reparo concluído, disponível na loja ou garantia concluída.',
            'finalizado_sem_reparo' => 'Sem reparo: irreparável, disponível para retirada ou recusado.',
            'encerrado' => 'Entrega, devolução sem reparo ou descarte do equipamento.',
            'cancelado' => 'Atendimento cancelado.',
            default => 'Fase operacional agrupada por macroprocesso.',
        };
    }

    private function macroGroupAccent(string $grupoMacro): string
    {
        return match (mb_strtolower(trim($grupoMacro))) {
            'recepcao' => '#0ea5e9',
            'diagnostico' => '#6f5afc',
            'orcamento' => '#f59e0b',
            'execucao' => '#16a34a',
            'qualidade' => '#d6b656',
            'interrupcao' => '#d79b00',
            'concluido' => '#22c55e',
            'finalizado_sem_reparo' => '#b85450',
            'encerrado' => '#64748b',
            'cancelado' => '#ef4444',
            default => '#6f5afc',
        };
    }

    private function macroGroupSoftAccent(string $grupoMacro): string
    {
        return match (mb_strtolower(trim($grupoMacro))) {
            'recepcao' => 'rgba(14, 165, 233, 0.12)',
            'diagnostico' => 'rgba(111, 90, 252, 0.12)',
            'orcamento' => 'rgba(245, 158, 11, 0.14)',
            'execucao' => 'rgba(22, 163, 74, 0.12)',
            'qualidade' => 'rgba(214, 182, 86, 0.16)',
            'interrupcao' => 'rgba(215, 155, 0, 0.14)',
            'concluido' => 'rgba(34, 197, 94, 0.12)',
            'finalizado_sem_reparo' => 'rgba(184, 84, 80, 0.12)',
            'encerrado' => 'rgba(100, 116, 139, 0.12)',
            'cancelado' => 'rgba(239, 68, 68, 0.12)',
            default => 'rgba(111, 90, 252, 0.12)',
        };
    }

    private function humanizeFlowState(string $flowState): string
    {
        return match (mb_strtolower(trim($flowState))) {
            'em_atendimento' => 'Em atendimento',
            'em_execucao' => 'Em execução',
            'pausado' => 'Pausado',
            'encerrado' => 'Encerrado',
            default => $this->humanizeSlug($flowState),
        };
    }

    private function humanizeSlug(string $value): string
    {
        $value = trim(str_replace(['_', '-'], ' ', $value));

        return $value !== '' ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : 'Sem grupo macro';
    }

    private function normalizeWorkflowColor(string $color): string
    {
        $color = trim($color);

        if ($color === '') {
            return '#6f5afc';
        }

        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $color) === 1) {
            return $color;
        }

        if (preg_match('/^rgba?\(\s*(?:\d{1,3}\s*,\s*){2}\d{1,3}(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\)$/i', $color) === 1) {
            return $color;
        }

        if (preg_match('/^hsla?\(\s*(?:\d{1,3}(?:\.\d+)?)\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*(?:,\s*(?:0|1|0?\.\d+))?\s*\)$/i', $color) === 1) {
            return $color;
        }

        if (preg_match('/^var\(--[A-Za-z0-9_-]+\)$/', $color) === 1) {
            return $color;
        }

        return match (mb_strtolower($color)) {
            'primary' => '#0d6efd',
            'secondary' => '#64748b',
            'success' => '#16a34a',
            'info' => '#0ea5e9',
            'warning' => '#f59e0b',
            'danger' => '#ef4444',
            'dark' => '#334155',
            'light' => '#cbd5e1',
            'indigo' => '#4f46e5',
            'purple' => '#8b5cf6',
            'orange' => '#f97316',
            default => '#6f5afc',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedStatusPayload(Request $request, bool $includeCodigo): array
    {
        $rules = [
            'nome' => ['required', 'string', 'max:120'],
            'grupo_macro' => ['required', 'string', 'max:60'],
            'icone' => ['nullable', 'string', 'max:60'],
            'cor' => ['nullable', 'string', 'max:30'],
            'ordem_fluxo' => ['nullable', 'integer'],
            'status_final' => ['nullable', 'boolean'],
            'status_pausa' => ['nullable', 'boolean'],
            'gera_evento_crm' => ['nullable', 'boolean'],
            'estado_fluxo_padrao' => ['nullable', 'string', 'max:40'],
            'ativo' => ['nullable', 'boolean'],
        ];

        $attributes = [
            'codigo' => 'código',
            'nome' => 'nome',
            'grupo_macro' => 'grupo macro',
            'icone' => 'ícone',
            'cor' => 'cor',
            'ordem_fluxo' => 'ordem no fluxo',
            'status_final' => 'status final',
            'status_pausa' => 'status de pausa',
            'gera_evento_crm' => 'gera evento CRM',
            'estado_fluxo_padrao' => 'estado de fluxo padrão',
            'ativo' => 'status',
        ];

        if ($includeCodigo) {
            $rules['codigo'] = ['required', 'string', 'max:80'];
        }

        $validated = $request->validate($rules, [], $attributes);

        $payload = [];

        foreach ($validated as $field => $value) {
            if (in_array($field, ['status_final', 'status_pausa', 'gera_evento_crm', 'ativo'], true)) {
                continue;
            }

            $payload[$field] = $this->normalizeValue($value);
        }

        $payload['status_final'] = $request->boolean('status_final', false);
        $payload['status_pausa'] = $request->boolean('status_pausa', false);
        $payload['gera_evento_crm'] = $request->boolean('gera_evento_crm', false);
        $payload['ativo'] = $request->boolean('ativo', false);

        return $payload;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function formatApiErrors(ApiRequestException $exception): array
    {
        $details = $exception->details();

        if (! is_array($details)) {
            return [];
        }

        $errors = [];

        foreach ($details as $field => $messages) {
            if (! is_string($field) || ! is_array($messages)) {
                continue;
            }

            $errors[$field] = array_values(array_filter($messages, 'is_string'));
        }

        return $errors;
    }
}
