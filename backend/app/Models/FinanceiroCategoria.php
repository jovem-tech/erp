<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceiroCategoria extends Model
{
    public const TIPO_RECEBER = 'receber';
    public const TIPO_PAGAR = 'pagar';
    public const TIPO_AMBOS = 'ambos';

    protected $table = 'financeiro_categorias';

    protected $guarded = [];

    protected $casts = [
        'dre_grupo_id' => 'integer',
        'dre_subgrupo_id' => 'integer',
        'impacta_dre_padrao' => 'boolean',
        'impacta_fluxo_caixa_padrao' => 'boolean',
        'dre_fixo_mensal_padrao' => 'boolean',
        'ordem_exibicao' => 'integer',
        'ativo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function dre_grupo(): BelongsTo
    {
        return $this->belongsTo(FinanceiroDreGrupo::class, 'dre_grupo_id', 'id');
    }

    public function dre_subgrupo(): BelongsTo
    {
        return $this->belongsTo(FinanceiroDreSubgrupo::class, 'dre_subgrupo_id', 'id');
    }
}
