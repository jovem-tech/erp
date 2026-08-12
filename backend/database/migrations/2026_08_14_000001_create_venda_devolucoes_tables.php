<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devolução e troca de venda — ver specs/029-devolucao-troca/spec.md.
 *
 * Sem FK física para as tabelas legadas, seguindo a regra do repositório.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('venda_devolucoes')) {
            Schema::create('venda_devolucoes', function (Blueprint $table): void {
                $table->id();
                $table->string('numero', 40)->unique();
                $table->unsignedBigInteger('venda_id');
                // Troca: a venda nova que o cliente levou no lugar.
                $table->unsignedBigInteger('venda_troca_id')->nullable();

                $table->string('status', 30)->default('concluida');
                $table->date('data_devolucao');
                $table->text('motivo');

                // Soma dos itens devolvidos pelo valor de lista da venda.
                $table->decimal('subtotal_itens', 12, 2)->default(0);
                // Crédito total do cliente pelos itens devolvidos, proporcional
                // ao total pago (a venda pode ter tido desconto geral).
                $table->decimal('valor_devolvido', 12, 2)->default(0);
                // Dinheiro que efetivamente volta ao cliente. Numa venda fiada
                // é menor que o crédito: não se devolve o que nunca foi pago.
                $table->decimal('valor_reembolsado', 12, 2)->default(0);
                // Parte do crédito que abate a dívida em aberto em vez de virar
                // dinheiro de volta.
                $table->decimal('valor_abatido', 12, 2)->default(0);
                // Custo dos itens que voltaram, para reverter a margem da venda.
                $table->decimal('custo_devolvido', 12, 2)->default(0);
                // Taxa de cartão que a operadora não devolve. Guardada só para
                // exibição: a despesa já foi lançada na venda e NÃO é revertida
                // aqui, senão o DRE contaria a perda duas vezes.
                $table->decimal('valor_taxa_nao_estornada', 12, 2)->default(0);

                // Turno em que o dinheiro saiu da gaveta — o de AGORA, não o da
                // venda: é hoje que a nota sai do caixa.
                $table->unsignedBigInteger('caixa_sessao_id')->nullable();
                $table->unsignedBigInteger('financeiro_id')->nullable();

                $table->unsignedBigInteger('criado_por')->nullable();
                // Administrador que liberou a devolução fora do prazo.
                $table->unsignedBigInteger('autorizado_por')->nullable();

                $table->uuid('creation_request_id')->nullable();
                $table->char('creation_request_fingerprint', 64)->nullable();

                $table->timestamps();

                $table->unique('creation_request_id', 'ux_venda_devolucoes_request');
                $table->index(['venda_id', 'created_at'], 'idx_venda_devolucoes_venda');
                $table->index(['data_devolucao', 'id'], 'idx_venda_devolucoes_data');
                $table->index('caixa_sessao_id', 'idx_venda_devolucoes_caixa');
                $table->index('venda_troca_id', 'idx_venda_devolucoes_troca');
            });
        }

        if (! Schema::hasTable('venda_devolucao_itens')) {
            Schema::create('venda_devolucao_itens', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('venda_devolucao_id');
                $table->unsignedBigInteger('venda_item_id');

                $table->decimal('quantidade', 10, 3);
                $table->decimal('valor_unitario', 12, 2)->default(0);
                // Valor de lista da linha devolvida.
                $table->decimal('valor_total', 12, 2)->default(0);
                // Valor efetivamente reembolsado, já com o rateio do desconto
                // geral da venda aplicado.
                $table->decimal('valor_reembolsado', 12, 2)->default(0);
                $table->decimal('custo_unitario', 12, 2)->default(0);
                $table->decimal('custo_total', 12, 2)->default(0);

                // Só peça que baixou estoque na venda volta à prateleira.
                $table->boolean('retorna_estoque')->default(false);
                $table->text('observacoes')->nullable();
                $table->timestamps();

                $table->index('venda_devolucao_id', 'idx_venda_devolucao_itens_dev');
                $table->index('venda_item_id', 'idx_venda_devolucao_itens_item');
            });
        }

        if (! Schema::hasTable('venda_devolucao_pagamentos')) {
            Schema::create('venda_devolucao_pagamentos', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('venda_devolucao_id');
                // Pagamento original que está sendo estornado.
                $table->unsignedBigInteger('venda_pagamento_id')->nullable();

                $table->string('forma_pagamento', 40);
                $table->unsignedBigInteger('conta_financeira_id')->nullable();
                $table->decimal('valor', 12, 2)->default(0);
                $table->decimal('valor_taxa_nao_estornada', 12, 2)->default(0);
                // financeiro_movimentos.id da saída gerada.
                $table->unsignedBigInteger('movimento_id')->nullable();

                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();

                $table->index('venda_devolucao_id', 'idx_venda_devolucao_pag_dev');
                $table->index(['forma_pagamento', 'created_at'], 'idx_venda_devolucao_pag_forma');
            });
        }

        // Quanto desta venda já voltou. Materializado para a listagem não
        // precisar somar devoluções por linha, e porque a margem da venda passa
        // a ser líquida do que foi devolvido.
        if (Schema::hasTable('vendas') && ! Schema::hasColumn('vendas', 'total_devolvido')) {
            Schema::table('vendas', function (Blueprint $table): void {
                $table->decimal('total_devolvido', 12, 2)->default(0)->after('total');
            });
        }

        // Devolução em dinheiro sai da gaveta do turno em que acontece, então
        // entra na conferência de fechamento daquele turno.
        if (Schema::hasTable('caixa_sessoes') && ! Schema::hasColumn('caixa_sessoes', 'total_devolucoes_dinheiro')) {
            Schema::table('caixa_sessoes', function (Blueprint $table): void {
                $table->decimal('total_devolucoes_dinheiro', 12, 2)->default(0)->after('total_sangrias');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('caixa_sessoes') && Schema::hasColumn('caixa_sessoes', 'total_devolucoes_dinheiro')) {
            Schema::table('caixa_sessoes', function (Blueprint $table): void {
                $table->dropColumn('total_devolucoes_dinheiro');
            });
        }

        if (Schema::hasTable('vendas') && Schema::hasColumn('vendas', 'total_devolvido')) {
            Schema::table('vendas', function (Blueprint $table): void {
                $table->dropColumn('total_devolvido');
            });
        }

        Schema::dropIfExists('venda_devolucao_pagamentos');
        Schema::dropIfExists('venda_devolucao_itens');
        Schema::dropIfExists('venda_devolucoes');
    }
};
