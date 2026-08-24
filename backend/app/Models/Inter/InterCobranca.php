<?php

namespace App\Models\Inter;

use App\Models\Financeiro;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cobranca Pix emitida no Banco Inter.
 */
class InterCobranca extends Model
{
    /** Status do padrao Pix (BACEN), guardados como o banco devolve. */
    public const STATUS_ATIVA = 'ATIVA';
    public const STATUS_CONCLUIDA = 'CONCLUIDA';
    public const STATUS_REMOVIDA_PELO_RECEBEDOR = 'REMOVIDA_PELO_USUARIO_RECEBEDOR';
    public const STATUS_REMOVIDA_PELO_PSP = 'REMOVIDA_PELO_PSP';

    /*
     * Status LOCAIS, que nao existem no padrao Pix.
     *
     * EMITINDO: linha gravada antes de chamar o banco. Existe para que um
     * timeout no meio da chamada nao deixe cobranca orfa: como o txid e' nosso,
     * sempre da' para perguntar depois "essa cobranca existe ai?".
     *
     * FALHA_EMISSAO: a chamada falhou, mas NAO sabemos se o banco criou a
     * cobranca. Nunca apagar a linha nesse estado — ela e' o unico rastro do
     * txid que pode existir do outro lado.
     */
    public const STATUS_EMITINDO = 'EMITINDO';
    public const STATUS_FALHA_EMISSAO = 'FALHA_EMISSAO';

    protected $table = 'inter_cobrancas';

    protected $guarded = [];

    protected $casts = [
        'financeiro_id' => 'integer',
        'os_id' => 'integer',
        'orcamento_id' => 'integer',
        'criado_por_usuario_id' => 'integer',
        'valor' => 'decimal:2',
        'expira_em' => 'datetime',
        'cancelada_em' => 'datetime',
        'solicitacao_payload' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function financeiro(): BelongsTo
    {
        return $this->belongsTo(Financeiro::class, 'financeiro_id', 'id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'os_id', 'id');
    }

    public function criadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por_usuario_id', 'id');
    }

    public function liquidacoes(): HasMany
    {
        return $this->hasMany(InterLiquidacao::class, 'inter_cobranca_id', 'id');
    }

    /**
     * Cobrancas que o comando de conciliacao precisa reconsultar.
     *
     * Expirada nao entra: o Pix ja nao pode ser pago, e insistir nela gastaria
     * chamada de API por nada.
     */
    public function scopeAbertas(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_ATIVA)
            ->whereNull('cancelada_em')
            ->where(function (Builder $q): void {
                $q->whereNull('expira_em')->orWhere('expira_em', '>=', now());
            });
    }

    /**
     * Cobrancas que precisam ser confrontadas com o banco.
     *
     * Inclui FALHA_EMISSAO de proposito: e' justamente o estado em que nao
     * sabemos o que existe do outro lado, e ignora-lo deixaria uma cobranca
     * possivelmente ativa fora de qualquer conferencia.
     */
    public function scopeParaReconciliar(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [self::STATUS_ATIVA, self::STATUS_FALHA_EMISSAO])
            ->whereNull('cancelada_em');
    }

    /** Emissao em andamento ou com desfecho desconhecido. */
    public function emiteuComFalha(): bool
    {
        return $this->status === self::STATUS_FALHA_EMISSAO;
    }

    /** Soma do que ja foi efetivamente recebido nesta cobranca. */
    public function valorLiquidado(): float
    {
        return round((float) $this->liquidacoes()->sum('valor'), 2);
    }

    public function estaQuitada(): bool
    {
        return $this->valorLiquidado() >= round((float) $this->valor, 2) - 0.001;
    }
}
