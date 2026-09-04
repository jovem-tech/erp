<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nível folha da taxonomia de estoque (Grupo → Categoria → Subcategoria).
 * Uma peça grava só o id daqui — Grupo e Categoria são derivados a partir da
 * Subcategoria em EstoqueController, para nunca existir uma tripla
 * inconsistente (ver App\Models\Peca::classificarPor()).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('estoque_subcategorias')) {
            Schema::create('estoque_subcategorias', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('categoria_id')->constrained('estoque_categorias')->restrictOnDelete();
                $table->string('nome', 120);
                $table->boolean('ativo')->default(true);
                $table->integer('ordem')->default(0);
                $table->timestamps();

                $table->unique(['categoria_id', 'nome'], 'ux_estoque_subcategorias_categoria_nome');
                $table->index(['categoria_id', 'ativo', 'ordem'], 'idx_estoque_subcategorias_categoria_ativo');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('estoque_subcategorias');
    }
};
