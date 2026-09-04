<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Taxonomia de estoque (Grupo → Categoria → Subcategoria). Categoria pertence
 * a um Grupo (equipamentos_tipos) — o mesmo nome de categoria (ex.: "Peça")
 * pode existir uma vez por Grupo, exatamente como o cliente desenhou a árvore
 * (Smartphone > Peça, Computador > Peça, como ramos independentes).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('estoque_categorias')) {
            Schema::create('estoque_categorias', function (Blueprint $table): void {
                $table->id();
                // integer() simples, não foreignId()/unsignedInteger(): a
                // legada `equipamentos_tipos.id` é INT SIGNED (nem bigint,
                // nem unsigned) — o MySQL rejeita FK entre tipos com
                // signedness ou largura diferentes.
                $table->integer('tipo_equipamento_id');
                $table->foreign('tipo_equipamento_id')->references('id')->on('equipamentos_tipos')->restrictOnDelete();
                $table->string('nome', 120);
                $table->boolean('ativo')->default(true);
                $table->integer('ordem')->default(0);
                $table->timestamps();

                $table->unique(['tipo_equipamento_id', 'nome'], 'ux_estoque_categorias_grupo_nome');
                $table->index(['tipo_equipamento_id', 'ativo', 'ordem'], 'idx_estoque_categorias_grupo_ativo');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('estoque_categorias');
    }
};
