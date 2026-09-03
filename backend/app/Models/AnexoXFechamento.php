<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Retrato congelado do Anexo X de um mês (Res. CGSN 140/2018, art. 106).
 *
 * O que congela de fato são as colunas `linha_*` e o `payload_json`. O
 * `payload_hash_sha256` NÃO congela nada — ele é evidência de adulteração:
 * prova que ninguém editou o JSON direto no banco depois de fechado. Quem
 * denuncia dado de origem alterado é a reconferência
 * (`AnexoXFechamentoService::divergencias()`), que recalcula ao vivo e compara
 * com o que está gravado aqui.
 *
 * Vigente = maior `versao` do par (competência, regime) com `status` fechado.
 */
class AnexoXFechamento extends Model
{
    protected $table = 'anexo_x_fechamentos';

    protected $guarded = [];

    public const STATUS_FECHADO = 'fechado';

    public const STATUS_REABERTO = 'reaberto';

    /**
     * As dez linhas do formulário oficial, na numeração da norma.
     *
     * @var array<int, string>
     */
    public const LINHAS = ['i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x'];

    protected $casts = [
        'id' => 'integer',
        'versao' => 'integer',
        'linha_i' => 'decimal:2',
        'linha_ii' => 'decimal:2',
        'linha_iii' => 'decimal:2',
        'linha_iv' => 'decimal:2',
        'linha_v' => 'decimal:2',
        'linha_vi' => 'decimal:2',
        'linha_vii' => 'decimal:2',
        'linha_viii' => 'decimal:2',
        'linha_ix' => 'decimal:2',
        'linha_x' => 'decimal:2',
        'deducao_descontos' => 'decimal:2',
        'deducao_devolucoes' => 'decimal:2',
        'acumulado_ano' => 'decimal:2',
        'limite_aplicado' => 'decimal:2',
        'ajuste_total' => 'decimal:2',
        'ajuste_quantidade' => 'integer',
        'fechado_por' => 'integer',
        'reaberto_por' => 'integer',
        'fechado_em' => 'datetime',
        'reaberto_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * O fechamento que vale hoje para este mês e regime — ou nenhum.
     */
    public function scopeVigente(Builder $query, string $competencia, string $regime): Builder
    {
        return $query
            ->where('competencia', $competencia)
            ->where('regime', $regime)
            ->where('status', self::STATUS_FECHADO)
            ->orderByDesc('versao');
    }

    public function autorFechamento(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fechado_por', 'id');
    }

    public function autorReabertura(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reaberto_por', 'id');
    }

    /**
     * @return array<string, float> as dez linhas como float, prontas para comparação
     */
    public function linhas(): array
    {
        $linhas = [];

        foreach (self::LINHAS as $linha) {
            $linhas[$linha] = round((float) $this->{'linha_'.$linha}, 2);
        }

        return $linhas;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $decoded = json_decode((string) $this->payload_json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
