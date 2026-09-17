<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Condições comerciais de UM nível de manutenção do orçamento.
 *
 * Só existe linha para nível que sobrescreve alguma coisa; campo nulo herda
 * o valor correspondente do orçamento. `entrega_domicilio` é nulável de
 * propósito: false é override explícito ("este nível não inclui"), não
 * ausência.
 */
class BudgetLevelTerms extends Model
{
    protected $table = 'orcamento_nivel_condicoes';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'orcamento_id' => 'integer',
        'nivel' => 'integer',
        'garantia_dias' => 'integer',
        'parcelas_sem_juros' => 'integer',
        'entrega_domicilio' => 'boolean',
        'beneficios' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'orcamento_id', 'id');
    }
}
