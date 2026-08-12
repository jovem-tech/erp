<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item de uma venda de balcão.
 *
 * O par `tipo_item` + `referencia_id` repete o desenho de `orcamento_itens`:
 * a referência não é FK de propósito, porque aponta para tabelas diferentes
 * (`pecas` ou `servicos`) e pode ser nula em item avulso.
 */
class SaleItem extends Model
{
    public const TYPE_PART = 'peca';
    public const TYPE_SERVICE = 'servico';
    public const TYPE_LOOSE = 'avulso';

    protected $table = 'venda_itens';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'venda_id' => 'integer',
        'referencia_id' => 'integer',
        'quantidade' => 'float',
        'valor_unitario' => 'float',
        'desconto' => 'float',
        'desconto_percentual' => 'float',
        'acrescimo' => 'float',
        'acrescimo_percentual' => 'float',
        'total' => 'float',
        'custo_unitario' => 'float',
        'custo_total' => 'float',
        'preco_venda_referencia' => 'float',
        'baixa_estoque' => 'boolean',
        'ordem' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'venda_id', 'id');
    }

    /**
     * @return array<int, string>
     */
    public static function types(): array
    {
        return [self::TYPE_PART, self::TYPE_SERVICE, self::TYPE_LOOSE];
    }

    /**
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_PART => 'Produto',
            self::TYPE_SERVICE => 'Serviço',
            self::TYPE_LOOSE => 'Avulso',
        ];
    }

    public static function typeLabel(?string $value): string
    {
        return self::typeLabels()[trim((string) $value)] ?? 'Avulso';
    }
}
