<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liga `pecas` à nova taxonomia Grupo → Categoria → Subcategoria. As 3
 * colunas nascem nullable de propósito: as peças já cadastradas ficam sem
 * classificação (nenhum backfill automático) e continuam exibindo o texto
 * legado de `categoria`/`tipo_equipamento`, que permanece intocado.
 *
 * `tipo_equipamento_id` e `estoque_categoria_id` são denormalizados aqui só
 * para filtro/consulta direta — a fonte da verdade ao salvar uma peça é
 * sempre `estoque_subcategoria_id`; os outros dois são derivados dela no
 * backend (EstoqueController), nunca aceitos crus do cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pecas')) {
            return;
        }

        $missingTipoEquipamentoId = ! Schema::hasColumn('pecas', 'tipo_equipamento_id');
        $missingEstoqueCategoriaId = ! Schema::hasColumn('pecas', 'estoque_categoria_id');
        $missingEstoqueSubcategoriaId = ! Schema::hasColumn('pecas', 'estoque_subcategoria_id');

        if ($missingTipoEquipamentoId || $missingEstoqueCategoriaId || $missingEstoqueSubcategoriaId) {
            Schema::table('pecas', function (Blueprint $table) use (
                $missingTipoEquipamentoId,
                $missingEstoqueCategoriaId,
                $missingEstoqueSubcategoriaId
            ): void {
                if ($missingTipoEquipamentoId) {
                    // integer() simples: equipamentos_tipos.id é INT SIGNED legado,
                    // não BIGINT nem UNSIGNED — precisa bater exatamente para o FK.
                    $table->integer('tipo_equipamento_id')->nullable();
                    $table->foreign('tipo_equipamento_id', 'fk_pecas_tipo_equipamento')
                        ->references('id')->on('equipamentos_tipos')->restrictOnDelete();
                    $table->index('tipo_equipamento_id', 'idx_pecas_tipo_equipamento_id');
                }

                if ($missingEstoqueCategoriaId) {
                    $table->unsignedBigInteger('estoque_categoria_id')->nullable();
                    $table->foreign('estoque_categoria_id', 'fk_pecas_estoque_categoria')
                        ->references('id')->on('estoque_categorias')->restrictOnDelete();
                    $table->index('estoque_categoria_id', 'idx_pecas_estoque_categoria_id');
                }

                if ($missingEstoqueSubcategoriaId) {
                    $table->unsignedBigInteger('estoque_subcategoria_id')->nullable();
                    $table->foreign('estoque_subcategoria_id', 'fk_pecas_estoque_subcategoria')
                        ->references('id')->on('estoque_subcategorias')->restrictOnDelete();
                    $table->index('estoque_subcategoria_id', 'idx_pecas_estoque_subcategoria_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pecas')) {
            return;
        }

        Schema::table('pecas', function (Blueprint $table): void {
            if (Schema::hasColumn('pecas', 'estoque_subcategoria_id')) {
                $table->dropForeign('fk_pecas_estoque_subcategoria');
                $table->dropIndex('idx_pecas_estoque_subcategoria_id');
                $table->dropColumn('estoque_subcategoria_id');
            }

            if (Schema::hasColumn('pecas', 'estoque_categoria_id')) {
                $table->dropForeign('fk_pecas_estoque_categoria');
                $table->dropIndex('idx_pecas_estoque_categoria_id');
                $table->dropColumn('estoque_categoria_id');
            }

            if (Schema::hasColumn('pecas', 'tipo_equipamento_id')) {
                $table->dropForeign('fk_pecas_tipo_equipamento');
                $table->dropIndex('idx_pecas_tipo_equipamento_id');
                $table->dropColumn('tipo_equipamento_id');
            }
        });
    }
};
