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
    private const DAYS_BACK = 60;

    /**
     * Horizonte da varredura periodica. Casa com o teto de janela da listagem
     * (AgendaService::MAX_WINDOW_DAYS): nao adianta materializar compromisso
     * que a tela nunca conseguiria pedir.
     *
     * O valor anterior (180) cortava em silencio exatamente o padrao de retorno
     * pos-servico de seis meses - a obrigacao existia no banco e nao aparecia
     * na agenda ate o tempo passar. Para datas alem deste horizonte, quem
     * garante a presenca imediata e reconcileForDate().
     */
    private const DAYS_AHEAD = 400;

    public function __construct(
        private readonly AgendaSourceRegistry $registry,
        private readonly GoogleCalendarSyncDispatcher $syncDispatcher
    ) {}

    /**
     * @param array<int, string>|null $keys Fontes a reconciliar; null = todas.
     * @return array<string, array{criados: int, atualizados: int, concluidos: int, cancelados: int, erro?: string}>
     */
    public function reconcile(?CarbonImmutable $from = null, ?CarbonImmutable $to = null, ?array $keys = null): array
    {
        $from ??= CarbonImmutable::now()->subDays(self::DAYS_BACK)->startOfDay();
        $to ??= CarbonImmutable::now()->addDays(self::DAYS_AHEAD)->endOfDay();

        $sources = $keys === null
            ? $this->registry->all()
            : array_filter(
                $this->registry->all(),
                static fn (string $key): bool => in_array($key, $keys, true),
                ARRAY_FILTER_USE_KEY
            );

        $report = [];

        foreach ($sources as $key => $source) {
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
     * Reconcilia uma fonte AGORA, numa janela garantidamente contendo a data
     * informada.
     *
     * Existe por causa da expectativa do usuario: quem agenda um retorno na
     * baixa da OS espera ve-lo na agenda ao abri-la em seguida, nao no proximo
     * tique de quinze minutos do agendador. E, como a janela acompanha a data
     * pedida, funciona tambem para prazos alem do horizonte da varredura
     * periodica.
     *
     * Nunca lanca: a agenda e um espelho: falhar aqui nao pode derrubar a
     * operacao que criou a obrigacao (fechar uma OS, lancar um titulo).
     *
     * @return array{criados: int, atualizados: int, concluidos: int, cancelados: int}|null
     */
    public function reconcileForDate(string $key, CarbonImmutable $date): ?array
    {
        try {
            $from = CarbonImmutable::now()->subDays(self::DAYS_BACK)->startOfDay();
            $to = $date->endOfDay()->max(CarbonImmutable::now()->addDays(self::DAYS_AHEAD)->endOfDay());

            return $this->reconcile($from, $to, [$key])[$key] ?? null;
        } catch (Throwable $exception) {
            report($exception);
            Log::warning('Falha ao refletir obrigação na agenda imediatamente.', [
                'fonte' => $key,
                'erro' => $exception->getMessage(),
            ]);

            return null;
        }
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
