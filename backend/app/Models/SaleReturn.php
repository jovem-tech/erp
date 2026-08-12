<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Devolução de venda — specs/029-devolucao-troca/spec.md.
 *
 * Imutável depois de registrada, pela mesma razão da venda: corrigir é assunto
 * de uma operação nova, não de edição.
 */
class SaleReturn extends Model
{
    public const STATUS_CONCLUIDA = 'concluida';

    /**
     * Prazo em que devolver é operação normal. Depois disso, exige credencial
     * de administrador.
     */
    public const PRAZO_LIVRE_DIAS = 7;

    public const FINANCE_CATEGORY = 'Devolução de venda';

    protected $table = 'venda_devolucoes';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'venda_id' => 'integer',
        'venda_troca_id' => 'integer',
        'caixa_sessao_id' => 'integer',
        'financeiro_id' => 'integer',
        'criado_por' => 'integer',
        'autorizado_por' => 'integer',
        'data_devolucao' => 'date',
        'subtotal_itens' => 'float',
        'valor_devolvido' => 'float',
        'valor_reembolsado' => 'float',
        'valor_abatido' => 'float',
        'custo_devolvido' => 'float',
        'valor_taxa_nao_estornada' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class, 'venda_devolucao_id', 'id')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SaleReturnPayment::class, 'venda_devolucao_id', 'id')->orderBy('id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'venda_id', 'id');
    }

    public function exchangeSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'venda_troca_id', 'id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CaixaSessao::class, 'caixa_sessao_id', 'id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por', 'id');
    }

    public function payable(): BelongsTo
    {
        return $this->belongsTo(Financeiro::class, 'financeiro_id', 'id');
    }
}
