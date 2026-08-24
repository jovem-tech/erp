<?php

namespace App\Models\Inter;

use Illuminate\Database\Eloquent\Model;

/**
 * Trilha append-only da integracao com o Banco Inter.
 *
 * Regra de projeto, igual a de `os_eventos`: linhas desta tabela NUNCA sao
 * atualizadas nem excluidas. Correcao e' linha nova. Sem isso, investigar uma
 * baixa indevida depende de log em arquivo, que rotaciona e se perde.
 */
class InterEvento extends Model
{
    public const ORIGEM_WEBHOOK = 'webhook';
    public const ORIGEM_POLLING = 'polling';
    public const ORIGEM_MANUAL = 'manual';

    public const DECISAO_LIQUIDADO = 'liquidado';
    public const DECISAO_JA_PROCESSADO = 'ja_processado';
    public const DECISAO_VALOR_DIVERGENTE = 'valor_divergente';
    public const DECISAO_IGNORADO = 'ignorado';
    public const DECISAO_ERRO = 'erro';

    protected $table = 'inter_eventos';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'http_status' => 'integer',
        'payload_recebido' => 'array',
        'payload_reconsulta' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * @param  array<string, mixed>  $atributos
     */
    public static function registrar(array $atributos): self
    {
        return static::query()->create($atributos + ['created_at' => now()]);
    }
}
