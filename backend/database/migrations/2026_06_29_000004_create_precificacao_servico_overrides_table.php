<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('precificacao_servico_overrides')) {
            Schema::create('precificacao_servico_overrides', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('servico_id')->constrained('servicos')->cascadeOnDelete();
                $table->decimal('custo_hora_produtiva', 14, 4)->default(0);
                $table->decimal('custos_diretos_total', 14, 4)->default(0);
                $table->decimal('margem_percentual', 8, 4)->default(0);
                $table->decimal('taxa_recebimento_percentual', 8, 4)->default(0);
                $table->decimal('imposto_percentual', 8, 4)->default(0);
                $table->decimal('tempo_tecnico_horas', 10, 4)->default(0);
                $table->decimal('risco_percentual', 8, 4)->default(0);
                $table->decimal('preco_tabela_referencia', 14, 4)->default(0);
                $table->decimal('custos_fixos_mensais', 14, 4)->default(0);
                $table->decimal('tecnicos_ativos', 10, 4)->default(1);
                $table->decimal('horas_produtivas_dia', 10, 4)->default(0);
                $table->decimal('dias_uteis_mes', 10, 4)->default(1);
                $table->decimal('consumiveis_valor', 14, 4)->default(0);
                $table->decimal('tempo_indireto_horas', 10, 4)->default(0);
                $table->decimal('reserva_garantia_valor', 14, 4)->default(0);
                $table->decimal('perdas_pequenas_valor', 14, 4)->default(0);
                $table->decimal('tempo_desmontagem_min', 10, 4)->default(0);
                $table->decimal('tempo_substituicao_min', 10, 4)->default(0);
                $table->decimal('tempo_montagem_min', 10, 4)->default(0);
                $table->decimal('tempo_teste_final_min', 10, 4)->default(0);
                $table->boolean('ativo')->default(true);
                $table->timestamps();

                $table->unique('servico_id', 'ux_precificacao_servico_overrides_servico');
                $table->index('ativo', 'idx_precificacao_servico_overrides_ativo');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('precificacao_servico_overrides');
    }
};
