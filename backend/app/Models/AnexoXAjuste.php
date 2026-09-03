<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ajuste manual declarado numa linha do Anexo X.
 *
 * É um LANÇAMENTO somado ao valor apurado, nunca uma substituição dele: a
 * apuração continua visível ao lado, e a tela mostra a tríade
 * Calculado / Ajuste / Declarado.
 *
 * Existe porque o Anexo X tem que declarar toda a receita bruta do mês, e o
 * sistema só conhece o que passou por ele. Sem este registro, a receita cobrada
 * fora do ERP obrigaria a preencher o formulário à mão, fora do sistema — sem
 * encerramento, sem hash e sem rastro.
 *
 * **Imutável.** Não existe UPDATE: corrigir é cancelar e lançar de novo. O
 * cancelado continua listado, riscado, com autor e motivo. O produto aqui é a
 * trilha de quem declarou o quê e quando.
 */
class AnexoXAjuste extends Model
{
    protected $table = 'anexo_x_ajustes';

    protected $guarded = [];

    public $timestamps = true;

    /**
     * As seis linhas-folha do formulário.
     *
     * III, VI, IX e X são somas das demais (`AnexoXService::montarLinhas()`).
     * Ajustar uma delas exigiria ratear o valor de volta para as folhas, e esse
     * rateio é decisão fiscal — a receita extra teve documento emitido ou não? —
     * que só quem lança sabe responder. Por isso a recusa é explícita, com a
     * mensagem mandando ajustar a linha de origem.
     *
     * @var array<int, string>
     */
    public const LINHAS_AJUSTAVEIS = ['i', 'ii', 'iv', 'v', 'vii', 'viii'];

    /**
     * Onde cada linha-folha entra nos blocos da apuração.
     *
     * @var array<string, array{0: string, 1: string}> linha => [atividade, coluna]
     */
    public const MAPA_DE_BLOCO = [
        'i' => ['comercio', 'sem'],
        'ii' => ['comercio', 'com'],
        'iv' => ['industria', 'sem'],
        'v' => ['industria', 'com'],
        'vii' => ['servicos', 'sem'],
        'viii' => ['servicos', 'com'],
    ];

    protected $casts = [
        'id' => 'integer',
        'valor' => 'decimal:2',
        'criado_por' => 'integer',
        'cancelado_por' => 'integer',
        'criado_em' => 'datetime',
        'cancelado_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function linhaAjustavel(string $linha): bool
    {
        return in_array(strtolower(trim($linha)), self::LINHAS_AJUSTAVEIS, true);
    }

    /**
     * Ajustes que contam na apuração — os que não foram cancelados.
     */
    public function scopeVigentes(Builder $query): Builder
    {
        return $query->whereNull('cancelado_em');
    }

    public function scopeDoMes(Builder $query, string $competencia, string $regime): Builder
    {
        return $query->where('competencia', $competencia)->where('regime', $regime);
    }

    public function cancelado(): bool
    {
        return $this->cancelado_em !== null;
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por', 'id');
    }

    public function autorCancelamento(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelado_por', 'id');
    }
}
