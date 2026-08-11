<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Condições comerciais estruturadas do orçamento.
 *
 * Antes, garantia, formas de pagamento aceitas, chave Pix e parcelamento sem
 * juros eram digitados à mão no campo livre `orcamentos.condicoes` — que na
 * prática ficava em branco. Agora viram dado estruturado, reaproveitando o
 * catálogo de formas de pagamento e as chaves Pix já cadastrados no sistema.
 *
 * `orcamentos.condicoes` continua existindo como texto complementar (nada é
 * perdido nos orçamentos antigos).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orcamentos')) {
            Schema::table('orcamentos', function (Blueprint $table): void {
                if (! Schema::hasColumn('orcamentos', 'garantia_dias')) {
                    // 90 / 180 / 365 / 730. Nulo = garantia não definida.
                    $table->unsignedSmallInteger('garantia_dias')->nullable()->after('prazo_execucao');
                }

                if (! Schema::hasColumn('orcamentos', 'parcelas_sem_juros')) {
                    // Negociado caso a caso; só se aplica quando alguma forma
                    // de cartão está entre as aceitas.
                    $table->unsignedTinyInteger('parcelas_sem_juros')->nullable()->after('garantia_dias');
                }
            });
        }

        if (! Schema::hasTable('orcamento_formas_pagamento')) {
            Schema::create('orcamento_formas_pagamento', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('orcamento_id');
                // Nulo quando a forma foi excluída do catálogo depois: o
                // orçamento continua exibindo o que foi realmente oferecido.
                $table->unsignedBigInteger('forma_pagamento_id')->nullable();
                $table->string('forma_codigo', 40);
                // Rótulo congelado no momento do orçamento — renomear a forma
                // no catálogo não reescreve documentos já emitidos.
                $table->string('forma_nome', 60);
                $table->boolean('is_cartao')->default(false);
                $table->integer('ordem')->default(0);
                $table->timestamps();

                $table->unique(['orcamento_id', 'forma_codigo'], 'uniq_orcamento_forma_codigo');
                $table->index(['orcamento_id', 'ordem'], 'idx_orcamento_formas_orcamento_ordem');
                $table->index('forma_codigo', 'idx_orcamento_formas_codigo');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('orcamento_formas_pagamento');

        if (! Schema::hasTable('orcamentos')) {
            return;
        }

        Schema::table('orcamentos', function (Blueprint $table): void {
            foreach (['parcelas_sem_juros', 'garantia_dias'] as $column) {
                if (Schema::hasColumn('orcamentos', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
