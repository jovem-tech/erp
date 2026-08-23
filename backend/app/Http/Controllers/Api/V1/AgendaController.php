<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreAgendaCompromissoRequest;
use App\Models\AgendaCompromisso;
use App\Services\Agenda\AgendaService;
use App\Services\Agenda\Sources\AgendaSourceRegistry;
use App\Services\Auth\RbacAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class AgendaController extends BaseApiController
{
    public function __construct(
        private readonly AgendaService $agendaService,
        private readonly AgendaSourceRegistry $sourceRegistry,
        private readonly RbacAuthorizationService $rbac
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('agenda:visualizar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        try {
            $items = $this->agendaService->list(
                $request->only(['de', 'ate', 'tipo', 'status', 'prioridade', 'responsavel_id']),
                $user,
                $this->canSeeAll($request)
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Não foi possível carregar a agenda.', 500, 'AGENDA_QUERY_FAILED', request: $request);
        }

        return $this->success([
            'compromissos' => $items->map(fn (AgendaCompromisso $item): array => $this->present($item))->all(),
            'tipos' => $this->sourceRegistry->options(),
            'pode_ver_todos' => $this->canSeeAll($request),
        ], request: $request);
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorize('agenda:visualizar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        try {
            $summary = $this->agendaService->summary($user, $this->canSeeAll($request));
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Não foi possível carregar o resumo da agenda.', 500, 'AGENDA_SUMMARY_FAILED', request: $request);
        }

        $summary['proximos'] = array_map(
            fn (AgendaCompromisso $item): array => $this->present($item),
            $summary['proximos']
        );

        return $this->success($summary, request: $request);
    }

    public function store(StoreAgendaCompromissoRequest $request): JsonResponse
    {
        $this->authorize('agenda:criar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        try {
            $item = $this->agendaService->create($request->validated(), $user);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Não foi possível criar o compromisso.', 500, 'AGENDA_SAVE_FAILED', request: $request);
        }

        return $this->success(['compromisso' => $this->present($item)], 201, request: $request);
    }

    public function update(StoreAgendaCompromissoRequest $request, AgendaCompromisso $compromisso): JsonResponse
    {
        $this->authorize('agenda:editar');

        if (! $this->canTouch($request, $compromisso)) {
            return $this->forbidden($request);
        }

        try {
            $item = $this->agendaService->update($compromisso, $request->validated());
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Não foi possível atualizar o compromisso.', 500, 'AGENDA_SAVE_FAILED', request: $request);
        }

        return $this->success(['compromisso' => $this->present($item)], request: $request);
    }

    public function complete(Request $request, AgendaCompromisso $compromisso): JsonResponse
    {
        $this->authorize('agenda:editar');

        if (! $this->canTouch($request, $compromisso)) {
            return $this->forbidden($request);
        }

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        return $this->success(
            ['compromisso' => $this->present($this->agendaService->complete($compromisso, $user))],
            request: $request
        );
    }

    public function reopen(Request $request, AgendaCompromisso $compromisso): JsonResponse
    {
        $this->authorize('agenda:editar');

        if (! $this->canTouch($request, $compromisso)) {
            return $this->forbidden($request);
        }

        return $this->success(
            ['compromisso' => $this->present($this->agendaService->reopen($compromisso))],
            request: $request
        );
    }

    public function destroy(Request $request, AgendaCompromisso $compromisso): JsonResponse
    {
        $this->authorize('agenda:excluir');

        if (! $this->canTouch($request, $compromisso)) {
            return $this->forbidden($request);
        }

        try {
            $this->agendaService->delete($compromisso);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'AGENDA_DELETE_BLOCKED', request: $request);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Não foi possível excluir o compromisso.', 500, 'AGENDA_DELETE_FAILED', request: $request);
        }

        return $this->success(['excluido' => true], request: $request);
    }

    private function canSeeAll(Request $request): bool
    {
        $user = $this->authenticatedUser($request);

        return $user !== null && $this->rbac->allows($user, 'agenda', 'ver_todos');
    }

    /**
     * Sem `ver_todos`, so se mexe no que e seu ou no que nao tem dono - a mesma
     * fronteira do scopeVisiveisPara usado na listagem. Sem esta checagem, um
     * usuario poderia editar por id um compromisso que a listagem dele nunca
     * mostraria.
     */
    private function canTouch(Request $request, AgendaCompromisso $item): bool
    {
        if ($this->canSeeAll($request)) {
            return true;
        }

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return false;
        }

        return $item->responsavel_id === null || (int) $item->responsavel_id === (int) $user->id;
    }

    private function forbidden(Request $request): JsonResponse
    {
        return $this->error(
            'Este compromisso é de outro responsável.',
            403,
            'AGENDA_FORBIDDEN',
            request: $request
        );
    }

    /** @return array<string, mixed> */
    private function present(AgendaCompromisso $item): array
    {
        return [
            'id' => (int) $item->id,
            'titulo' => (string) $item->titulo,
            'descricao' => $item->descricao,
            'tipo' => (string) $item->tipo,
            'origem_tipo' => $item->origem_tipo,
            'origem_id' => $item->origem_id,
            'gerido' => $item->isManaged(),
            'inicio_em' => $item->inicio_em?->toIso8601String(),
            'fim_em' => $item->fim_em?->toIso8601String(),
            'data' => $item->inicio_em?->toDateString(),
            'hora' => (bool) $item->dia_inteiro ? null : $item->inicio_em?->format('H:i'),
            'dia_inteiro' => (bool) $item->dia_inteiro,
            'status' => (string) $item->status,
            'prioridade' => (string) $item->prioridade,
            'atrasado' => $item->isLate(),
            'responsavel_id' => $item->responsavel_id,
            'responsavel_nome' => $item->relationLoaded('responsible')
                ? ($item->responsible?->nome ?? null)
                : null,
            'cliente_id' => $item->cliente_id,
            'os_id' => $item->os_id,
            'lembrete_minutos' => $item->lembrete_minutos,
            'google_event_id' => $item->google_event_id,
            'google_sync_estado' => (string) $item->google_sync_estado,
            'concluido_em' => $item->concluido_em?->toIso8601String(),
        ];
    }
}
