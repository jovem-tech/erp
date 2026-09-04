<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        // decimal:4, nao integer: as colunas viraram DECIMAL(14,4) na
        // migration 2026_08_27_000001 e um cast 'integer' truncaria 0,5 para
        // 0 em silencio, sem erro nenhum.
        'quantidade_atual' => 'decimal:4',
        'estoque_minimo' => 'decimal:4',
        'estoque_maximo' => 'decimal:4',
        'ativo' => 'boolean',
        'encerrado_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'tipo_equipamento_id' => 'integer',
        'estoque_categoria_id' => 'integer',
        'estoque_subcategoria_id' => 'integer',
    ];

    public function movimentacoes(): HasMany
    {
        return $this->hasMany(Movimentacao::class, 'peca_id', 'id');
    }

    /**
     * Taxonomia de estoque (Grupo). Denormalizado a partir de
     * estoqueSubcategoria->categoria->tipoEquipamento só para filtro/consulta
     * direta — nunca gravado cru a partir do cliente, ver EstoqueController.
     */
    public function tipoEquipamento(): BelongsTo
    {
        return $this->belongsTo(EquipmentType::class, 'tipo_equipamento_id', 'id');
    }

    /** Taxonomia de estoque (Categoria). Mesma nota de denormalização acima. */
    public function estoqueCategoria(): BelongsTo
    {
        return $this->belongsTo(EstoqueCategoria::class, 'estoque_categoria_id', 'id');
    }

    /** Taxonomia de estoque (Subcategoria) — a fonte da verdade da classificação. */
    public function estoqueSubcategoria(): BelongsTo
    {
        return $this->belongsTo(EstoqueSubcategoria::class, 'estoque_subcategoria_id', 'id');
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
                ->orWhereRaw('LOWER(COALESCE(status, "")) LIKE ?', [$search])
                // Texto legado continua valendo (peças antigas, nunca reclassificadas),
                // mas uma peça só classificada pela árvore nova também precisa
                // aparecer na busca por "Display"/"Smartphone" etc.
                ->orWhereHas('tipoEquipamento', function (Builder $relation) use ($search): void {
                    $relation->whereRaw('LOWER(nome) LIKE ?', [$search]);
                })
                ->orWhereHas('estoqueCategoria', function (Builder $relation) use ($search): void {
                    $relation->whereRaw('LOWER(nome) LIKE ?', [$search]);
                })
                ->orWhereHas('estoqueSubcategoria', function (Builder $relation) use ($search): void {
                    $relation->whereRaw('LOWER(nome) LIKE ?', [$search]);
                });
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
