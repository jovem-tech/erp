<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('precificacao_categoria_encargos')) {
            Schema::create('precificacao_categoria_encargos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('categoria_id')->constrained('precificacao_categorias')->cascadeOnDelete();
                $table->string('nome', 140);
                $table->decimal('percentual', 8, 2)->default(0);
                $table->boolean('ativo')->default(true);
                $table->integer('ordem')->default(0);
                $table->timestamps();

                $table->index(['categoria_id', 'ativo'], 'idx_precificacao_cat_encargos_categoria_ativo');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('precificacao_categoria_encargos');
    }
};
