<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completa a margem de contribuicao da OS.
 *
 * Ate aqui `os_margem` guardava apenas receita - pecas - comissao. Faltavam
 * dois custos variaveis que o proprio ERP ja conhece e que, somados, chegam a
 * ~9 p.p. numa OS media paga no cartao:
 *
 *  - taxa de recebimento: ja lancada como despesa real na baixa da OS
 *    (OrderClosureService::registerCardFeeExpense, origem_tipo
 *    'os_recebimento_cartao'), mas nunca abatida da margem;
 *  - imposto sobre a venda: a precificacao ja divide por ele
 *    (precificacao_servico_imposto_percentual) para chegar no preco minimo,
 *    e o realizado ignorava.
 *
 * Sem esses dois a margem reportada fica inflada, e toda decisao de desconto
 * tomada em cima dela parte de um numero que nao existe.
 *
 * `tempo_tecnico_horas` habilita a margem por hora de tecnico — o criterio
 * correto de priorizacao quando a bancada, e nao o caixa, e o recurso
 * restrito. Fica em `os` (dado operacional, informado pelo tecnico ao
 * concluir o reparo) e e copiado para `os_margem` no calculo.
 *
 * A tabela `os` e legada (compartilhada com o sistema-hml) e nao nasce de
 * migration deste repositorio; em teste ela e montada por
 * tests/Concerns/BuildsLegacyErpSchema.php, que roda DEPOIS das migrations.
 * Dai o guard hasTable() — a coluna precisa ser declarada nos dois lugares.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('os') && ! Schema::hasColumn('os', 'tempo_tecnico_horas')) {
            Schema::table('os', function (Blueprint $table): void {
                $table->decimal('tempo_tecnico_horas', 10, 2)
                    ->nullable()
                    ->after('data_conclusao');
            });
        }

        if (! Schema::hasTable('os_margem')) {
            return;
        }

        Schema::table('os_margem', function (Blueprint $table): void {
            if (! Schema::hasColumn('os_margem', 'custo_taxa_recebimento')) {
                $table->decimal('custo_taxa_recebimento', 12, 2)->default(0)->after('custo_comissao');
            }

            if (! Schema::hasColumn('os_margem', 'custo_imposto')) {
                $table->decimal('custo_imposto', 12, 2)->default(0)->after('custo_taxa_recebimento');
            }

            if (! Schema::hasColumn('os_margem', 'tempo_tecnico_horas')) {
                $table->decimal('tempo_tecnico_horas', 10, 2)->nullable()->after('percentual_margem');
            }

            if (! Schema::hasColumn('os_margem', 'margem_por_hora')) {
                $table->decimal('margem_por_hora', 12, 2)->nullable()->after('tempo_tecnico_horas');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('os_margem')) {
            Schema::table('os_margem', function (Blueprint $table): void {
                foreach (['custo_taxa_recebimento', 'custo_imposto', 'tempo_tecnico_horas', 'margem_por_hora'] as $coluna) {
                    if (Schema::hasColumn('os_margem', $coluna)) {
                        $table->dropColumn($coluna);
                    }
                }
            });
        }

        if (Schema::hasTable('os') && Schema::hasColumn('os', 'tempo_tecnico_horas')) {
            Schema::table('os', function (Blueprint $table): void {
                $table->dropColumn('tempo_tecnico_horas');
            });
        }
    }
};
