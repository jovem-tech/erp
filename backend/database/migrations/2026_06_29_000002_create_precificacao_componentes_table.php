<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('precificacao_componentes')) {
            Schema::create('precificacao_componentes', function (Blueprint $table): void {
                $table->id();
                $table->string('grupo', 50);
                $table->string('nome', 120);
                $table->string('tipo_valor', 20)->default('percentual');
                $table->decimal('valor', 12, 4)->default(0);
                $table->string('origem', 20)->default('manual');
                $table->boolean('ativo')->default(true);
                $table->integer('ordem')->default(0);
                $table->timestamps();

                $table->index(['grupo', 'ativo'], 'idx_precificacao_componentes_grupo_ativo');
                $table->index(['grupo', 'tipo_valor'], 'idx_precificacao_componentes_grupo_tipo');
            });
        }

        $this->seedDefaults();
    }

    public function down(): void
    {
        Schema::dropIfExists('precificacao_componentes');
    }

    private function seedDefaults(): void
    {
        if (! Schema::hasTable('precificacao_componentes')) {
            return;
        }

        if (DB::table('precificacao_componentes')->count() > 0) {
            return;
        }

        $now = now();

        DB::table('precificacao_componentes')->insert([
            [
                'grupo' => 'encargo_peca_percentual',
                'nome' => 'Triagem e testes da peça',
                'tipo_valor' => 'percentual',
                'valor' => 4,
                'origem' => 'manual',
                'ativo' => true,
                'ordem' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'grupo' => 'encargo_peca_percentual',
                'nome' => 'Risco de garantia da peça',
                'tipo_valor' => 'percentual',
                'valor' => 5,
                'origem' => 'manual',
                'ativo' => true,
                'ordem' => 20,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'grupo' => 'encargo_peca_percentual',
                'nome' => 'Armazenagem e obsolescência',
                'tipo_valor' => 'percentual',
                'valor' => 3,
                'origem' => 'manual',
                'ativo' => true,
                'ordem' => 30,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'grupo' => 'custo_servico_fixo',
                'nome' => 'Consumíveis e limpeza técnica',
                'tipo_valor' => 'valor',
                'valor' => 6,
                'origem' => 'manual',
                'ativo' => true,
                'ordem' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'grupo' => 'risco_servico_percentual',
                'nome' => 'Reserva de garantia e retrabalho',
                'tipo_valor' => 'percentual',
                'valor' => 3,
                'origem' => 'manual',
                'ativo' => true,
                'ordem' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
};
