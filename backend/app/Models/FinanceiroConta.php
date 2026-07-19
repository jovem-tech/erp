<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceiroConta extends Model
{
    public const TIPO_CAIXA = 'caixa';

    public const TIPO_BANCO = 'banco';

    public const TIPO_ADQUIRENTE = 'adquirente';

    public const TIPO_RESERVA = 'reserva';

    public const TIPO_CARTEIRA_DIGITAL = 'carteira_digital';

    public const TIPO_OUTRA = 'outra';

    protected $table = 'financeiro_contas';

    protected $guarded = [];

    protected $casts = [
        'data_inicio_controle' => 'date',
        'considera_disponivel' => 'boolean',
        'ativo' => 'boolean',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return array<int, array{value: string, label: string}> */
    public static function typeOptions(): array
    {
        return [
            ['value' => self::TIPO_CAIXA, 'label' => 'Caixa físico'],
            ['value' => self::TIPO_BANCO, 'label' => 'Banco'],
            ['value' => self::TIPO_ADQUIRENTE, 'label' => 'Adquirente / maquininha'],
            ['value' => self::TIPO_RESERVA, 'label' => 'Reserva'],
            ['value' => self::TIPO_CARTEIRA_DIGITAL, 'label' => 'Carteira digital'],
            ['value' => self::TIPO_OUTRA, 'label' => 'Outra'],
        ];
    }

    /** @return array<int, string> */
    public static function typeValues(): array
    {
        return array_column(self::typeOptions(), 'value');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('ativo', true);
    }

    public function movimentosPatrimoniais(): HasMany
    {
        return $this->hasMany(FinanceiroContaMovimento::class, 'conta_financeira_id');
    }

    public function defaults(): HasMany
    {
        return $this->hasMany(FinanceiroContaDefault::class, 'conta_financeira_id');
    }
}
