<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sessões de caixa — ver specs/028-caixa-sessoes/spec.md.
 *
 * A camada de turno que faltava sobre a máquina financeira existente: quem
 * abriu, com quanto, o que passou pela gaveta e quanto foi contado no fim.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('caixa_sessoes')) {
            Schema::create('caixa_sessoes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('conta_financeira_id');
                $table->unsignedBigInteger('operador_id')->nullable();

                $table->string('status', 20)->default('aberta');

                $table->dateTime('aberto_em');
                $table->unsignedBigInteger('aberto_por')->nullable();
                $table->decimal('valor_abertura', 12, 2)->default(0);
                // Marca a sessão que nasceu da primeira venda em dinheiro do dia
                // em vez de uma abertura declarada — o valor de abertura herdado
                // pode precisar de correção.
                $table->boolean('abertura_automatica')->default(false);
                $table->text('observacoes_abertura')->nullable();

                $table->dateTime('fechado_em')->nullable();
                $table->unsignedBigInteger('fechado_por')->nullable();
                // Preenchidos só no fechamento: o esperado é calculado no
                // momento da contagem, nunca antes (conferência cega).
                $table->decimal('valor_esperado', 12, 2)->nullable();
                $table->decimal('valor_informado', 12, 2)->nullable();
                $table->decimal('diferenca', 12, 2)->nullable();
                $table->text('observacoes_fechamento')->nullable();

                // Fotografia do turno, congelada no fechamento. Recalcular a
                // partir das vendas depois traria números diferentes se alguma
                // venda for cancelada mais tarde.
                $table->decimal('total_vendas_dinheiro', 12, 2)->default(0);
                $table->decimal('total_suprimentos', 12, 2)->default(0);
                $table->decimal('total_sangrias', 12, 2)->default(0);
                $table->integer('quantidade_vendas')->default(0);

                $table->timestamps();

                $table->index(['conta_financeira_id', 'status'], 'idx_caixa_sessoes_conta_status');
                $table->index(['operador_id', 'aberto_em'], 'idx_caixa_sessoes_operador');
                $table->index(['status', 'aberto_em'], 'idx_caixa_sessoes_status_data');
            });
        }

        if (! Schema::hasTable('caixa_movimentos')) {
            Schema::create('caixa_movimentos', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('caixa_sessao_id');
                $table->string('tipo', 20); // sangria | suprimento
                $table->decimal('valor', 12, 2);
                $table->string('motivo', 255);
                $table->unsignedBigInteger('responsavel_id')->nullable();
                // Sangria com destino gera transferência real entre contas;
                // sem destino é apenas uma saída registrada da gaveta.
                $table->unsignedBigInteger('conta_destino_id')->nullable();
                $table->unsignedBigInteger('transferencia_id')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();

                $table->index(['caixa_sessao_id', 'created_at'], 'idx_caixa_movimentos_sessao');
                $table->index('transferencia_id', 'idx_caixa_movimentos_transferencia');
            });
        }

        if (Schema::hasTable('vendas') && ! Schema::hasColumn('vendas', 'caixa_sessao_id')) {
            Schema::table('vendas', function (Blueprint $table): void {
                $table->unsignedBigInteger('caixa_sessao_id')->nullable()->after('financeiro_id');
                $table->index('caixa_sessao_id', 'idx_vendas_caixa_sessao');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vendas') && Schema::hasColumn('vendas', 'caixa_sessao_id')) {
            Schema::table('vendas', function (Blueprint $table): void {
                if ($this->hasIndex('vendas', 'idx_vendas_caixa_sessao')) {
                    $table->dropIndex('idx_vendas_caixa_sessao');
                }
                $table->dropColumn('caixa_sessao_id');
            });
        }

        Schema::dropIfExists('caixa_movimentos');
        Schema::dropIfExists('caixa_sessoes');
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('{$table}')"))
                ->contains(static fn (object $index): bool => (string) ($index->name ?? '') === $indexName);
        }

        return DB::select('SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?', [$indexName]) !== [];
    }
};
