<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Itens das opções de manutenção que o cliente NÃO escolheu.
 *
 * Na aprovação de um orçamento em níveis, os itens fora da opção escolhida
 * saem de `orcamento_itens` (a OS, a reserva de estoque e o financeiro leem
 * essa lista como escopo contratado — ver BudgetApprovalService::applyApprovedLevel).
 * Antes de sair, cada linha é copiada integralmente para cá, ligada à
 * aprovação que a descartou.
 *
 * Uso exclusivamente técnico: o formulário de edição oferece "reaproveitar"
 * esses itens quando o cliente muda de ideia (aprovou a Básica, agora quer a
 * Completa). O que o cliente vê depois da decisão é o snapshot da aprovação
 * (`orcamento_aprovacoes.niveis_snapshot`), nunca esta tabela.
 *
 * Tabela própria, e não um flag em `orcamento_itens`: há leitores crus e
 * joins da tabela de itens (peças da OS, custo do fechamento, PDF da OS
 * completa) que ignorariam um escopo global — o invariante "itens do
 * orçamento = escopo contratado" fica intocado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orcamento_itens_descartados')) {
            return;
        }

        Schema::create('orcamento_itens_descartados', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('orcamento_id');
            $table->unsignedBigInteger('aprovacao_id')->nullable();
            // Só referência histórica: `syncItems` recria as linhas a cada
            // edição, então o id original não é chave para nada.
            $table->unsignedBigInteger('item_original_id')->nullable();
            $table->unsignedTinyInteger('nivel_aprovado');
            // Espelho fiel de orcamento_itens — o suficiente para recriar a
            // linha no formulário sem perder precificação.
            $table->string('tipo_item', 30)->default('servico');
            $table->unsignedBigInteger('referencia_id')->nullable();
            $table->string('descricao', 255);
            $table->decimal('quantidade', 14, 4)->default(1);
            $table->decimal('valor_unitario', 12, 2)->default(0);
            $table->decimal('desconto', 12, 2)->default(0);
            $table->string('desconto_tipo', 20)->default('valor');
            $table->decimal('desconto_percentual', 8, 4)->nullable();
            $table->decimal('acrescimo', 12, 2)->default(0);
            $table->string('acrescimo_tipo', 20)->default('valor');
            $table->decimal('acrescimo_percentual', 8, 4)->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->integer('ordem')->default(0);
            $table->json('niveis')->nullable();
            $table->text('observacoes')->nullable();
            $table->decimal('preco_custo_referencia', 12, 2)->default(0);
            $table->decimal('preco_venda_referencia', 12, 2)->default(0);
            $table->decimal('preco_base', 12, 2)->default(0);
            $table->decimal('percentual_encargos', 12, 2)->default(0);
            $table->decimal('valor_encargos', 12, 2)->default(0);
            $table->decimal('percentual_margem', 12, 2)->default(0);
            $table->decimal('valor_margem', 12, 2)->default(0);
            $table->decimal('valor_recomendado', 12, 2)->default(0);
            $table->string('modo_precificacao', 30)->nullable();
            $table->dateTime('descartado_em');
            $table->timestamps();

            $table->index(['orcamento_id', 'nivel_aprovado'], 'idx_orcamento_itens_descartados_orcamento');
            $table->foreign('orcamento_id')->references('id')->on('orcamentos')->cascadeOnDelete();
            $table->foreign('aprovacao_id')->references('id')->on('orcamento_aprovacoes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orcamento_itens_descartados');
    }
};
