<?php

namespace App\Models\Inter;

use App\Models\FinanceiroMovimento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um Pix recebido, confirmado pelo banco.
 *
 * O `e2eid` tem UNIQUE no banco de dados — e' ali que mora a idempotencia da
 * integracao inteira. Nao remover esse indice: sem ele, duas entregas
 * concorrentes do mesmo pagamento viram duas baixas.
 */
class InterLiquidacao extends Model
{
    protected $table = 'inter_liquidacoes';

    protected $guarded = [];

    protected $casts = [
        'inter_cobranca_id' => 'integer',
        'financeiro_movimento_id' => 'integer',
        'valor' => 'decimal:2',
        'horario' => 'datetime',
        'payload' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function cobranca(): BelongsTo
    {
        return $this->belongsTo(InterCobranca::class, 'inter_cobranca_id', 'id');
    }

    public function movimento(): BelongsTo
    {
        return $this->belongsTo(FinanceiroMovimento::class, 'financeiro_movimento_id', 'id');
    }

    /**
     * Pix confirmado pelo banco que ainda nao virou baixa no financeiro.
     *
     * Estado que precisa ser visivel: significa dinheiro que entrou na conta e
     * nao esta refletido no sistema.
     */
    public function pendenteDeBaixa(): bool
    {
        return $this->financeiro_movimento_id === null;
    }
}
