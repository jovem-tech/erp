<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Documento fiscal de uma OS ou venda (spec 041, fase 042).
 *
 * Nasce como rascunho: o ERP monta a discriminação e os dados do tomador, o
 * operador emite no portal do gov.br e devolve número e chave. Só então vira
 * `emitido`.
 *
 * `tomador_nome` e `tomador_documento` são congelados na criação de propósito —
 * corrigir o cadastro do cliente depois não pode reescrever o que já foi
 * declarado ao fisco.
 */
class DocumentoFiscal extends Model
{
    protected $table = 'documentos_fiscais';

    protected $guarded = [];

    public const TIPO_NFSE = 'nfse';

    public const TIPO_NFE = 'nfe';

    public const TIPO_NFCE = 'nfce';

    public const STATUS_RASCUNHO = 'rascunho';

    public const STATUS_EMITIDO = 'emitido';

    public const STATUS_CANCELADO = 'cancelado';

    public const STATUS_REJEITADO = 'rejeitado';

    protected $casts = [
        'id' => 'integer',
        'os_id' => 'integer',
        'venda_id' => 'integer',
        'cliente_id' => 'integer',
        'valor_servicos' => 'decimal:2',
        'valor_pecas' => 'decimal:2',
        'valor_total' => 'decimal:2',
        'valor_xml' => 'decimal:2',
        'assinatura_conferida' => 'boolean',
        'competencia' => 'date',
        'emitido_em' => 'datetime',
        'cancelado_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @return array<int, string>
     */
    public static function tipos(): array
    {
        return [self::TIPO_NFSE, self::TIPO_NFE, self::TIPO_NFCE];
    }

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [self::STATUS_RASCUNHO, self::STATUS_EMITIDO, self::STATUS_CANCELADO, self::STATUS_REJEITADO];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'os_id', 'id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'cliente_id', 'id');
    }

    /**
     * Documento que ja' existe no fisco. Cancelado continua existindo — o que
     * ele nao e' e' rascunho.
     */
    public function foiEmitido(): bool
    {
        return in_array((string) $this->status, [self::STATUS_EMITIDO, self::STATUS_CANCELADO], true);
    }

    /**
     * O valor do XML diverge do que a OS calculou?
     *
     * Vale ate' um centavo de diferenca — arredondamento de rateio de desconto
     * nao e' divergencia. Acima disso, alguem emitiu no portal um valor
     * diferente do que o sistema preparou, e a tela precisa dizer isso em vez
     * de mostrar o numero certo de uma nota com outro valor.
     */
    public function valorDivergeDoXml(): bool
    {
        if ($this->valor_xml === null) {
            return false;
        }

        return abs((float) $this->valor_xml - (float) $this->valor_total) > 0.01;
    }
}
