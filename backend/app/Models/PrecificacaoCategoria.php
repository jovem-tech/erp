<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PrecificacaoCategoria extends Model
{
    protected $table = 'precificacao_categorias';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'encargos_percentual' => 'decimal:2',
        'margem_percentual' => 'decimal:2',
        'ativo' => 'boolean',
        'ordem' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeActiveForType(Builder $query, string $type): Builder
    {
        return $query->where('tipo', $type)->where('ativo', 1);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAtivosPorTipo(string $type): array
    {
        return $this->query()
            ->activeForType($type)
            ->orderBy('ordem')
            ->orderBy('categoria_nome')
            ->get()
            ->toArray();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getMapaPorTipo(string $type): array
    {
        $map = [];

        foreach ($this->getAtivosPorTipo($type) as $row) {
            $nome = trim((string) ($row['categoria_nome'] ?? ''));
            if ($nome === '') {
                continue;
            }

            $key = function_exists('mb_strtolower') ? mb_strtolower($nome) : strtolower($nome);
            $map[$key] = $row;
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    public static function activeNamesForType(string $type): array
    {
        return self::query()
            ->activeForType($type)
            ->orderBy('ordem')
            ->orderBy('categoria_nome')
            ->pluck('categoria_nome')
            ->filter()
            ->values()
            ->all();
    }
}
