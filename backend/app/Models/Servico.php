<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Servico extends Model
{
    protected $table = 'servicos';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'valor' => 'decimal:2',
        'tempo_padrao_horas' => 'decimal:2',
        'custo_direto_padrao' => 'decimal:2',
        'encerrado_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $search = '%' . mb_strtolower(trim($term)) . '%';

        return $query->where(static function (Builder $builder) use ($search): void {
            $builder
                ->whereRaw('LOWER(COALESCE(nome, "")) LIKE ?', [$search])
                ->orWhereRaw('LOWER(COALESCE(descricao, "")) LIKE ?', [$search])
                ->orWhereRaw('LOWER(COALESCE(tipo_equipamento, "")) LIKE ?', [$search])
                ->orWhereRaw('LOWER(COALESCE(status, "")) LIKE ?', [$search]);
        });
    }

    /**
     * @return array<int, string>
     */
    public static function tiposEquipamentoAtivos(): array
    {
        return EquipmentType::query()
            ->where('ativo', 1)
            ->orderBy('nome')
            ->pluck('nome')
            ->filter()
            ->values()
            ->all();
    }
}
