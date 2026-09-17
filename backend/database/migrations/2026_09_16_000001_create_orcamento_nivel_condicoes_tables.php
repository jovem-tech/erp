<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Condições comerciais por nível de manutenção.
 *
 * Até aqui o que diferenciava um nível do outro era só a lista de itens. Agora
 * garantia, parcelamento sem juros, formas de pagamento aceitas, entrega em
 * domicílio e uma lista livre de diferenciais também podem variar por nível —
 * os campos do orçamento continuam sendo o PADRÃO, e cada nível pode
 * sobrescrever qualquer um deles de forma independente (nulo = herda).
 *
 * `entrega_domicilio` é novo: não existia em lugar nenhum, então nasce como
 * coluna base em `orcamentos` (mesma família de garantia_dias) antes de ganhar
 * a dimensão "por nível". Na aprovação, o efetivo do nível escolhido é copiado
 * para as colunas base (ver BudgetCommercialTermsService::collapseApprovedLevel)
 * — a OS e as revisões continuam lendo `orcamentos.*` direto, como sempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orcamentos') && ! Schema::hasColumn('orcamentos', 'entrega_domicilio')) {
            Schema::table('orcamentos', function (Blueprint $table): void {
                $table->boolean('entrega_domicilio')->default(false)->after('parcelas_sem_juros');
            });
        }

        if (! Schema::hasTable('orcamento_nivel_condicoes')) {
            Schema::create('orcamento_nivel_condicoes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('orcamento_id');
                $table->unsignedTinyInteger('nivel');
                // Nulo = herda o campo correspondente do orçamento. Cada campo
                // é independente: dá para customizar só a garantia de um
                // nível e deixar parcelamento/formas herdados.
                $table->unsignedSmallInteger('garantia_dias')->nullable();
                $table->unsignedTinyInteger('parcelas_sem_juros')->nullable();
                // Booleano NULÁVEL de propósito: null = herda; false é um
                // override explícito (um nível pode DESLIGAR a entrega que o
                // padrão liga).
                $table->boolean('entrega_domicilio')->nullable();
                // Diferenciais livres da opção (ex.: "instalação expressa").
                // Não há padrão global para herdar: nulo/vazio = nenhum.
                $table->json('beneficios')->nullable();
                $table->timestamps();

                $table->unique(['orcamento_id', 'nivel'], 'uniq_orcamento_nivel_condicoes');
            });
        }

        if (! Schema::hasTable('orcamento_nivel_formas_pagamento')) {
            // Espelho de orcamento_formas_pagamento com `nivel`: qualquer
            // linha para (orcamento, nivel) substitui a lista base inteira
            // naquele nível; zero linhas = herda a lista base.
            Schema::create('orcamento_nivel_formas_pagamento', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('orcamento_id');
                $table->unsignedTinyInteger('nivel');
                $table->unsignedBigInteger('forma_pagamento_id')->nullable();
                $table->string('forma_codigo', 40);
                $table->string('forma_nome', 60);
                $table->boolean('is_cartao')->default(false);
                $table->integer('ordem')->default(0);
                $table->timestamps();

                $table->unique(['orcamento_id', 'nivel', 'forma_codigo'], 'uniq_orcamento_nivel_forma_codigo');
                $table->index(['orcamento_id', 'nivel', 'ordem'], 'idx_orcamento_nivel_formas_ordem');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('orcamento_nivel_formas_pagamento');
        Schema::dropIfExists('orcamento_nivel_condicoes');

        if (Schema::hasTable('orcamentos') && Schema::hasColumn('orcamentos', 'entrega_domicilio')) {
            Schema::table('orcamentos', function (Blueprint $table): void {
                $table->dropColumn('entrega_domicilio');
            });
        }
    }
};
