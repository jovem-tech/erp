<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sangria ou suprimento dentro de um turno de caixa.
 *
 * Sangria com conta de destino gera transferência real entre contas
 * (FinanceiroContaService::createTransfer), e `transferencia_id` é o ponteiro de
 * reconciliação. Sem destino, é apenas uma saída registrada da gaveta.
 */
class CaixaMovimento extends Model
{
    public const TIPO_SANGRIA = 'sangria';
    public const TIPO_SUPRIMENTO = 'suprimento';

    protected $table = 'caixa_movimentos';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'caixa_sessao_id' => 'integer',
        'responsavel_id' => 'integer',
        'conta_destino_id' => 'integer',
        'transferencia_id' => 'integer',
        'valor' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CaixaSessao::class, 'caixa_sessao_id', 'id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id', 'id');
    }

    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceiroConta::class, 'conta_destino_id', 'id');
    }

    /**
     * @return array<int, string>
     */
    public static function types(): array
    {
        return [self::TIPO_SANGRIA, self::TIPO_SUPRIMENTO];
    }

    public static function typeLabel(?string $value): string
    {
        return match (trim((string) $value)) {
            self::TIPO_SANGRIA => 'Sangria',
            self::TIPO_SUPRIMENTO => 'Suprimento',
            default => 'Movimento',
        };
    }
}
