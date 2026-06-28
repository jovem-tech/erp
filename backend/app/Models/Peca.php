<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use App\Models\EquipmentType;

class Peca extends Model
{
    protected $table = 'pecas';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'preco_custo' => 'decimal:2',
        'preco_venda' => 'decimal:2',
        'quantidade_atual' => 'integer',
        'estoque_minimo' => 'integer',
        'estoque_maximo' => 'integer',
        'ativo' => 'boolean',
        'encerrado_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function movimentacoes(): HasMany
    {
        return $this->hasMany(Movimentacao::class, 'peca_id', 'id');
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $search = '%' . mb_strtolower(trim($term)) . '%';

        return $query->where(static function (Builder $builder) use ($search): void {
            $builder
                ->whereRaw('LOWER(COALESCE(codigo, "")) LIKE ?', [$search])
                ->orWhereRaw('LOWER(COALESCE(codigo_fabricante, "")) LIKE ?', [$search])
                ->orWhereRaw('LOWER(COALESCE(nome, "")) LIKE ?', [$search])
                ->orWhereRaw('LOWER(COALESCE(categoria, "")) LIKE ?', [$search])
                ->orWhereRaw('LOWER(COALESCE(modelos_compativeis, "")) LIKE ?', [$search])
                ->orWhereRaw('LOWER(COALESCE(tipo_equipamento, "")) LIKE ?', [$search])
                ->orWhereRaw('LOWER(COALESCE(fornecedor, "")) LIKE ?', [$search])
                ->orWhereRaw('LOWER(COALESCE(localizacao, "")) LIKE ?', [$search])
                ->orWhereRaw('LOWER(COALESCE(status, "")) LIKE ?', [$search]);
        });
    }

    public static function generateCodigo(): string
    {
        $lastId = (int) self::query()->max('id');

        return 'PC' . str_pad((string) ($lastId + 1), 5, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<int, string>
     */
    public static function categoriasAtivas(): array
    {
        $legacyCategories = self::query()
            ->whereNotNull('categoria')
            ->where('categoria', '<>', '')
            ->distinct()
            ->orderBy('categoria')
            ->pluck('categoria')
            ->filter()
            ->values()
            ->all();

        $catalogCategories = DB::table('precificacao_categorias')
            ->where('tipo', 'peca')
            ->where('ativo', 1)
            ->orderBy('ordem')
            ->orderBy('categoria_nome')
            ->pluck('categoria_nome')
            ->filter()
            ->values()
            ->all();

        return array_values(array_unique(array_merge($legacyCategories, $catalogCategories)));
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

    /**
     * @return array<int, self>
     */
    public static function estoqueBaixo(): array
    {
        return self::query()
            ->where('ativo', 1)
            ->whereColumn('quantidade_atual', '<=', 'estoque_minimo')
            ->orderBy('nome')
            ->get()
            ->all();
    }
}
