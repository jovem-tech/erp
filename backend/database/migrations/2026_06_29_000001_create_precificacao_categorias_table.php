<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('precificacao_categorias')) {
            Schema::create('precificacao_categorias', function (Blueprint $table): void {
                $table->id();
                $table->string('tipo', 30);
                $table->string('categoria_nome', 120);
                $table->decimal('encargos_percentual', 10, 2)->default(0);
                $table->decimal('margem_percentual', 10, 2)->default(0);
                $table->boolean('ativo')->default(true);
                $table->integer('ordem')->default(0);
                $table->timestamps();

                $table->index(['tipo', 'ativo'], 'idx_precificacao_categorias_tipo_ativo');
            });
        }

        $this->seedDefaults();
    }

    public function down(): void
    {
        Schema::dropIfExists('precificacao_categorias');
    }

    private function seedDefaults(): void
    {
        if (! Schema::hasTable('precificacao_categorias')) {
            return;
        }

        if (DB::table('precificacao_categorias')->count() > 0) {
            return;
        }

        $now = now();

        DB::table('precificacao_categorias')->insert([
            [
                'tipo' => 'peca',
                'categoria_nome' => 'Insumos',
                'encargos_percentual' => 5,
                'margem_percentual' => 20,
                'ativo' => true,
                'ordem' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'tipo' => 'servico',
                'categoria_nome' => 'Software',
                'encargos_percentual' => 10,
                'margem_percentual' => 35,
                'ativo' => true,
                'ordem' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'tipo' => 'produto',
                'categoria_nome' => 'Padrão',
                'encargos_percentual' => 8,
                'margem_percentual' => 35,
                'ativo' => true,
                'ordem' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
};
