<?php

namespace App\Services\Agenda;

use App\Models\AgendaCompromisso;
use App\Services\Agenda\Google\GoogleCalendarSyncDispatcher;
use App\Services\Agenda\Sources\AgendaSource;
use App\Services\Agenda\Sources\AgendaSourceItem;
use App\Services\Agenda\Sources\AgendaSourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reconcilia a agenda com as obrigacoes que vivem em outros modulos.
 *
 * Reconciliar, e nao "importar": a cada rodada compara o que as fontes dizem
 * existir com o que ja esta gravado, e converge um no outro. E por isso que o
 * comando pode rodar de quinze em quinze minutos, duas vezes em paralelo ou
 * depois de meses parado, sempre com o mesmo resultado.
 *
 * Quatro transicoes:
 *   fonte tem, agenda nao  -> cria
 *   fonte tem, agenda tem  -> atualiza se algo mudou
 *   fonte diz resolvido    -> conclui (a conta foi paga)
 *   fonte nao tem mais     -> cancela (o titulo foi cancelado/apagado)
 *
 * Itens manuais nunca sao tocados: nao tem origem, logo nao pertencem a fonte
 * alguma.
 */
class AgendaSourceReconciler
{
    private const DAYS_BACK = 30;
    private const DAYS_AHEAD = 180;

    public function __construct(
        private readonly AgendaSourceRegistry $registry,
        private readonly GoogleCalendarSyncDispatcher $syncDispatcher
    ) {}

    /**
     * @return array<string, array{criados: int, atualizados: int, concluidos: int, cancelados: int, erro?: string}>
     */
    public function reconcile(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $from ??= CarbonImmutable::now()->subDays(self::DAYS_BACK)->startOfDay();
        $to ??= CarbonImmutable::now()->addDays(self::DAYS_AHEAD)->endOfDay();

        $report = [];

        foreach ($this->registry->all() as $key => $source) {
            try {
                $report[$key] = $this->reconcileSource($source, $from, $to);
            } catch (Throwable $exception) {
                // Uma fonte quebrada (modulo em migracao, tabela ausente) nao
                // pode impedir as outras de atualizar a agenda.
                report($exception);
                Log::warning('Falha ao reconciliar fonte da agenda.', [
                    'fonte' => $key,
                    'erro' => $exception->getMessage(),
                ]);

                $report[$key] = [
                    'criados' => 0, 'atualizados' => 0, 'concluidos' => 0, 'cancelados' => 0,
                    'erro' => $exception->getMessage(),
                ];
            }
        }

        return $report;
    }

    /**
     * @return array{criados: int, atualizados: int, concluidos: int, cancelados: int}
     */
    private function reconcileSource(AgendaSource $source, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $key = $source->key();
        $stats = ['criados' => 0, 'atualizados' => 0, 'concluidos' => 0, 'cancelados' => 0];

        /** @var array<int, AgendaSourceItem> $collected */
        $collected = [];
        foreach ($source->collect($from, $to) as $item) {
            $collected[$item->originId] = $item;
        }

        // Todos os status, inclusive cancelados: a unique (origem_tipo,
        // origem_id) impede recriar a linha, entao uma obrigacao que volta a
        // valer precisa ser reaberta - nunca inserida de novo.
        $existing = AgendaCompromisso::query()
            ->where('origem_tipo', $key)
            ->get()
            ->keyBy('origem_id');

        foreach ($collected as $originId => $item) {
            $current = $existing->get($originId);

            if (! $current instanceof AgendaCompromisso) {
                if ($item->resolvido) {
                    // Nasceu ja resolvida (conta paga antes de a agenda
                    // existir): nao ha por que criar um compromisso concluido.
                    continue;
                }

                $this->createFrom($key, $source, $item);
                $stats['criados']++;

                continue;
            }

            if ($item->resolvido) {
                if ($current->status !== AgendaCompromisso::STATUS_CONCLUIDO) {
                    $current->forceFill([
                        'status' => AgendaCompromisso::STATUS_CONCLUIDO,
                        'concluido_em' => now(),
                    ])->save();
                    $this->syncDispatcher->queue($current);
                    $stats['concluidos']++;
                }

                continue;
            }

            if ($this->applyChanges($current, $item)) {
                $stats['atualizados']++;
            }
        }

        // O que a fonte parou de reportar dentro da janela deixou de valer.
        // Fora da janela nao se mexe: ausencia ali significa apenas "nao foi
        // consultado", nao "nao existe mais".
        foreach ($existing as $originId => $current) {
            if (isset($collected[$originId])) {
                continue;
            }

            if ($current->inicio_em === null
                || $current->inicio_em->lessThan($from)
                || $current->inicio_em->greaterThan($to)) {
                continue;
            }

            if ($current->status !== AgendaCompromisso::STATUS_PENDENTE) {
                continue;
            }

            $current->forceFill(['status' => AgendaCompromisso::STATUS_CANCELADO])->save();
            $this->syncDispatcher->queue($current);
            $stats['cancelados']++;
        }

        return $stats;
    }

    private function createFrom(string $key, AgendaSource $source, AgendaSourceItem $item): void
    {
        $compromisso = AgendaCompromisso::query()->create([
            'titulo' => mb_substr($item->titulo, 0, 180),
            'descricao' => $item->descricao,
            'tipo' => $key,
            'origem_tipo' => $key,
            'origem_id' => $item->originId,
            'inicio_em' => $item->inicioEm,
            'fim_em' => null,
            'dia_inteiro' => $item->diaInteiro,
            'status' => AgendaCompromisso::STATUS_PENDENTE,
            'prioridade' => $item->prioridade,
            'responsavel_id' => $item->responsavelId,
            'cliente_id' => $item->clienteId,
            'os_id' => $item->osId,
            'lembrete_minutos' => $item->lembreteMinutos,
        ]);

        $this->syncDispatcher->queue($compromisso);
    }

    /** Devolve true se algo mudou de fato. */
    private function applyChanges(AgendaCompromisso $current, AgendaSourceItem $item): bool
    {
        $changes = [];

        $titulo = mb_substr($item->titulo, 0, 180);
        if ($current->titulo !== $titulo) {
            $changes['titulo'] = $titulo;
        }

        if (! $current->inicio_em?->equalTo($item->inicioEm)) {
            $changes['inicio_em'] = $item->inicioEm;
        }

        if ((bool) $current->dia_inteiro !== $item->diaInteiro) {
            $changes['dia_inteiro'] = $item->diaInteiro;
        }

        if ($current->descricao !== $item->descricao) {
            $changes['descricao'] = $item->descricao;
        }

        // Responsavel so e reafirmado quando a origem tem um: uma atribuicao
        // feita a mao na agenda nao pode ser apagada por uma fonte que
        // simplesmente nao sabe de quem e a tarefa.
        if ($item->responsavelId !== null && (int) $current->responsavel_id !== $item->responsavelId) {
            $changes['responsavel_id'] = $item->responsavelId;
        }

        // A obrigacao voltou a valer: titulo reaberto, pagamento estornado,
        // prazo redefinido. Vale tanto para concluido quanto para cancelado.
        if ($current->status !== AgendaCompromisso::STATUS_PENDENTE) {
            $changes['status'] = AgendaCompromisso::STATUS_PENDENTE;
            $changes['concluido_em'] = null;
            $changes['concluido_por'] = null;
        }

        if ($changes === []) {
            return false;
        }

        $current->forceFill($changes)->save();
        $this->syncDispatcher->queue($current);

        return true;
    }
}
