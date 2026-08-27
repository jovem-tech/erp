<?php

namespace App\Services\Agenda;

use App\Models\AgendaCompromisso;
use App\Models\User;
use App\Services\Agenda\Google\GoogleCalendarSyncDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Regras de leitura e escrita da agenda.
 *
 * Toda escrita passa por aqui para que dois invariantes valham sempre:
 *  1. compromisso gerido por uma origem (conta a pagar, prazo de OS...) nao tem
 *     data nem titulo editados a mao - quem manda e o modulo de origem;
 *  2. toda mudanca marca o item para sincronizar com o Google e enfileira o push.
 */
class AgendaService
{
    /** Teto do intervalo consultavel de uma vez, em dias. */
    private const MAX_WINDOW_DAYS = 400;

    /**
     * Horizonte de `proximos` no resumo do dashboard, em dias.
     *
     * Sem teto, a lista buscava os proximos 5 pendentes onde quer que
     * estivessem no calendario - numa semana calma isso trazia um boleto de
     * 3 semanas a frente so para completar a contagem, com o mesmo peso
     * visual de algo realmente iminente. Mesma janela de proximos_7_dias
     * abaixo, para as duas leituras do resumo concordarem.
     */
    private const PROXIMOS_HORIZON_DAYS = 7;

    public function __construct(
        private readonly GoogleCalendarSyncDispatcher $syncDispatcher
    ) {}

    /**
     * @param array<string, mixed> $filters
     * @return Collection<int, AgendaCompromisso>
     */
    public function list(array $filters, User $user, bool $canSeeAll): Collection
    {
        [$from, $to] = $this->resolveWindow($filters);

        return $this->baseQuery($user, $canSeeAll)
            ->noPeriodo($from->toDateTimeString(), $to->toDateTimeString())
            ->when(
                $this->stringFilter($filters, 'tipo') !== null,
                fn (Builder $q) => $q->where('tipo', $this->stringFilter($filters, 'tipo'))
            )
            ->when(
                $this->stringFilter($filters, 'prioridade') !== null,
                fn (Builder $q) => $q->where('prioridade', $this->stringFilter($filters, 'prioridade'))
            )
            ->when(
                $this->stringFilter($filters, 'status') !== null,
                fn (Builder $q) => $q->where('status', $this->stringFilter($filters, 'status')),
                // Sem filtro explicito, cancelados ficam fora: eles existem para
                // manter o rastro da obrigacao que deixou de valer, nao para
                // poluir o calendario do dia a dia.
                fn (Builder $q) => $q->where('status', '!=', AgendaCompromisso::STATUS_CANCELADO)
            )
            ->when(
                isset($filters['responsavel_id']) && (int) $filters['responsavel_id'] > 0,
                fn (Builder $q) => $q->where('responsavel_id', (int) $filters['responsavel_id'])
            )
            ->orderBy('inicio_em')
            ->orderBy('id')
            ->limit(2000)
            ->get();
    }

    /**
     * Numeros do topo da tela e do card do dashboard.
     *
     * @return array<string, mixed>
     */
    public function summary(User $user, bool $canSeeAll): array
    {
        $now = CarbonImmutable::now();
        $today = $now->startOfDay();

        $open = fn (): Builder => $this->baseQuery($user, $canSeeAll)->pendentes();

        return [
            'atrasados' => $this->baseQuery($user, $canSeeAll)->atrasados()->count(),
            'hoje' => $open()
                ->whereBetween('inicio_em', [$today, $today->endOfDay()])
                ->count(),
            'proximos_7_dias' => $open()
                ->whereBetween('inicio_em', [$today->addDay(), $today->addDays(7)->endOfDay()])
                ->count(),
            'proximos' => $open()
                ->whereBetween('inicio_em', [$today, $today->addDays(self::PROXIMOS_HORIZON_DAYS)->endOfDay()])
                ->orderBy('inicio_em')
                ->limit(5)
                ->get()
                ->all(),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function create(array $payload, User $author): AgendaCompromisso
    {
        $attributes = $this->normalizeWritablePayload($payload);

        $attributes['tipo'] = AgendaCompromisso::TIPO_MANUAL;
        $attributes['status'] = AgendaCompromisso::STATUS_PENDENTE;
        $attributes['criado_por'] = (int) $author->id;
        $attributes['responsavel_id'] ??= (int) $author->id;

        $item = AgendaCompromisso::query()->create($attributes);

        $this->syncDispatcher->queue($item);

        return $item;
    }

    /** @param array<string, mixed> $payload */
    public function update(AgendaCompromisso $item, array $payload): AgendaCompromisso
    {
        $attributes = $this->normalizeWritablePayload($payload);

        if ($item->isManaged()) {
            // Data, titulo e responsavel de um item gerido pertencem ao modulo
            // de origem - reescreve-los aqui duraria ate a proxima
            // reconciliacao e deixaria o usuario achando que mudou algo.
            $attributes = array_intersect_key($attributes, array_flip([
                'descricao', 'prioridade', 'lembrete_minutos',
            ]));
        }

        if ($attributes !== []) {
            $item->fill($attributes)->save();
            $this->syncDispatcher->queue($item);
        }

        return $item->refresh();
    }

    public function complete(AgendaCompromisso $item, User $user): AgendaCompromisso
    {
        if ($item->status === AgendaCompromisso::STATUS_CONCLUIDO) {
            return $item;
        }

        $item->forceFill([
            'status' => AgendaCompromisso::STATUS_CONCLUIDO,
            'concluido_em' => CarbonImmutable::now(),
            'concluido_por' => (int) $user->id,
        ])->save();

        $this->syncDispatcher->queue($item);

        return $item;
    }

    public function reopen(AgendaCompromisso $item): AgendaCompromisso
    {
        $item->forceFill([
            'status' => AgendaCompromisso::STATUS_PENDENTE,
            'concluido_em' => null,
            'concluido_por' => null,
        ])->save();

        $this->syncDispatcher->queue($item);

        return $item;
    }

    /**
     * Compromisso gerido nao e apagado: ele voltaria na proxima reconciliacao,
     * porque a obrigacao de origem continua existindo. Quem quer tira-lo da
     * frente resolve a origem (paga a conta, conclui o followup).
     */
    public function delete(AgendaCompromisso $item): void
    {
        if ($item->isManaged()) {
            throw new RuntimeException(
                'Este compromisso é mantido automaticamente pelo módulo de origem e não pode ser excluído. Conclua-o ou resolva o registro que o gerou.'
            );
        }

        DB::transaction(function () use ($item): void {
            // Some do Google antes de sumir daqui: depois do delete local nao
            // sobra o google_event_id para remover o evento la.
            $this->syncDispatcher->queueDeletion($item);
            $item->delete();
        });
    }

    /** @return Builder<AgendaCompromisso> */
    private function baseQuery(User $user, bool $canSeeAll): Builder
    {
        // Eager load do responsavel: a listagem mostra o nome de quem responde
        // por cada item, e sem isso seria uma consulta por linha.
        $query = AgendaCompromisso::query()->with(['responsible']);

        if (! $canSeeAll) {
            $query->visiveisPara((int) $user->id);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveWindow(array $filters): array
    {
        $from = $this->parseDate($filters['de'] ?? null) ?? CarbonImmutable::now()->startOfMonth();
        $to = $this->parseDate($filters['ate'] ?? null) ?? $from->endOfMonth();

        $from = $from->startOfDay();
        $to = $to->endOfDay();

        if ($to->lessThan($from)) {
            $to = $from->endOfDay();
        }

        if ($from->diffInDays($to) > self::MAX_WINDOW_DAYS) {
            $to = $from->addDays(self::MAX_WINDOW_DAYS)->endOfDay();
        }

        return [$from, $to];
    }

    /** Filtro de texto: string vazia e ausencia significam a mesma coisa aqui. */
    private function stringFilter(array $filters, string $key): ?string
    {
        $value = trim((string) ($filters[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeWritablePayload(array $payload): array
    {
        $attributes = [];

        foreach (['titulo', 'descricao', 'prioridade'] as $key) {
            if (array_key_exists($key, $payload)) {
                $value = trim((string) $payload[$key]);
                $attributes[$key] = $value === '' && $key !== 'titulo' ? null : $value;
            }
        }

        foreach (['responsavel_id', 'cliente_id', 'os_id', 'lembrete_minutos'] as $key) {
            if (array_key_exists($key, $payload)) {
                $value = (int) $payload[$key];
                $attributes[$key] = $value > 0 ? $value : null;
            }
        }

        if (array_key_exists('dia_inteiro', $payload)) {
            $attributes['dia_inteiro'] = (bool) $payload['dia_inteiro'];
        }

        if (array_key_exists('inicio_em', $payload)) {
            $attributes['inicio_em'] = CarbonImmutable::parse((string) $payload['inicio_em']);
        }

        if (array_key_exists('fim_em', $payload)) {
            $end = trim((string) $payload['fim_em']);
            $attributes['fim_em'] = $end === '' ? null : CarbonImmutable::parse($end);
        }

        // Fim antes do inicio inverteria a duracao do evento no Google.
        $start = $attributes['inicio_em'] ?? null;
        $end = $attributes['fim_em'] ?? null;
        if ($start instanceof CarbonImmutable && $end instanceof CarbonImmutable && $end->lessThan($start)) {
            $attributes['fim_em'] = $start;
        }

        return $attributes;
    }
}
