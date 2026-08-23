<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um compromisso da agenda - manual ou espelho de uma obrigacao de outro modulo.
 *
 * Itens com origem (origem_tipo/origem_id preenchidos) sao mantidos pelo
 * AgendaSourceReconciler e NAO devem ser editados livremente: quem manda na
 * data e no titulo deles e o registro de origem. Ver isManaged().
 */
class AgendaCompromisso extends Model
{
    public const STATUS_PENDENTE = 'pendente';
    public const STATUS_CONCLUIDO = 'concluido';
    public const STATUS_CANCELADO = 'cancelado';

    public const TIPO_MANUAL = 'manual';

    public const PRIORIDADE_BAIXA = 'baixa';
    public const PRIORIDADE_NORMAL = 'normal';
    public const PRIORIDADE_ALTA = 'alta';

    public const SYNC_PENDENTE = 'pendente';
    public const SYNC_SINCRONIZADO = 'sincronizado';
    public const SYNC_ERRO = 'erro';
    /** Conexao com o Google ausente ou desligada: nao ha o que sincronizar. */
    public const SYNC_DESLIGADO = 'desligado';

    /**
     * origem_tipo dos compromissos que nasceram no proprio Google Agenda
     * (usuario criou pelo celular, dentro do calendario dedicado). Sao tratados
     * como manuais para toda regra de edicao - a origem so registra de onde
     * vieram.
     */
    public const ORIGEM_GOOGLE = 'google';

    /** @var array<int, string> */
    public const STATUSES = [self::STATUS_PENDENTE, self::STATUS_CONCLUIDO, self::STATUS_CANCELADO];

    /** @var array<int, string> */
    public const PRIORIDADES = [self::PRIORIDADE_BAIXA, self::PRIORIDADE_NORMAL, self::PRIORIDADE_ALTA];

    protected $table = 'agenda_compromissos';

    protected $guarded = [];

    protected $casts = [
        'origem_id' => 'integer',
        'inicio_em' => 'datetime',
        'fim_em' => 'datetime',
        'dia_inteiro' => 'boolean',
        'responsavel_id' => 'integer',
        'cliente_id' => 'integer',
        'os_id' => 'integer',
        'lembrete_minutos' => 'integer',
        'concluido_em' => 'datetime',
        'concluido_por' => 'integer',
        'criado_por' => 'integer',
        'google_sincronizado_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id', 'id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'cliente_id', 'id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'os_id', 'id');
    }

    /**
     * Compromisso gerado e mantido por uma AgendaSource. O usuario pode
     * concluir e anotar, mas data/titulo pertencem ao modulo de origem.
     */
    public function isManaged(): bool
    {
        $origin = trim((string) $this->origem_tipo);

        return $origin !== '' && $origin !== self::ORIGEM_GOOGLE;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDENTE;
    }

    /** @param Builder<self> $query */
    public function scopePendentes(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDENTE);
    }

    /**
     * Sobreposicao com a janela, nao "comeca dentro dela": um compromisso de
     * varios dias precisa aparecer no mes em que ele atravessa, mesmo tendo
     * comecado no mes anterior.
     *
     * @param Builder<self> $query
     */
    public function scopeNoPeriodo(Builder $query, string $from, string $to): void
    {
        // Fim efetivo = fim_em quando existe, senao o proprio inicio (evento
        // pontual). Escrito com COALESCE para nao depender da precedencia
        // implicita entre AND e OR de um encadeamento orWhereNull.
        $query->where('inicio_em', '<=', $to)
            ->whereRaw('COALESCE(fim_em, inicio_em) >= ?', [$from]);
    }

    /**
     * Atrasado = o momento passou.
     *
     * Compromisso de dia inteiro so vence quando o DIA acaba - um vencimento
     * marcado para hoje as 9h nao esta atrasado as 10h da manha. Compromisso
     * com hora marcada vence na hora.
     *
     * Esta e a definicao unica de "atrasado" do modulo: o contador do topo da
     * tela e a marca vermelha de cada item precisam responder a mesma pergunta,
     * ou a tela se contradiz.
     *
     * @param Builder<self> $query
     */
    public function scopeAtrasados(Builder $query): void
    {
        $now = CarbonImmutable::now();

        $query->pendentes()->where(function (Builder $inner) use ($now): void {
            $inner->where(function (Builder $allDay) use ($now): void {
                $allDay->where('dia_inteiro', true)
                    ->whereDate('inicio_em', '<', $now->toDateString());
            })->orWhere(function (Builder $timed) use ($now): void {
                $timed->where('dia_inteiro', false)
                    ->where('inicio_em', '<', $now);
            });
        });
    }

    /** A mesma regra do scopeAtrasados, aplicada a uma linha carregada. */
    public function isLate(): bool
    {
        if (! $this->isOpen() || $this->inicio_em === null) {
            return false;
        }

        return (bool) $this->dia_inteiro
            ? $this->inicio_em->endOfDay()->isPast()
            : $this->inicio_em->isPast();
    }

    /**
     * Visibilidade pessoal: os meus mais os que ainda nao tem dono. Um
     * vencimento sem responsavel definido e de todo mundo - esconde-lo faria a
     * agenda mentir por omissao justamente na obrigacao que ninguem assumiu.
     *
     * @param Builder<self> $query
     */
    public function scopeVisiveisPara(Builder $query, int $userId): void
    {
        $query->where(function (Builder $inner) use ($userId): void {
            $inner->where('responsavel_id', $userId)->orWhereNull('responsavel_id');
        });
    }
}
