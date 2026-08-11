<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de chaves Pix da empresa.
 *
 * As chaves são cadastradas em Financeiro > Configurações > Formas de
 * Pagamento, na linha da forma "Pix", e alimentam as condições comerciais do
 * orçamento: ao marcar Pix como forma aceita, as chaves ativas aparecem para o
 * cliente no documento, sem digitação manual.
 *
 * A tabela é independente de `financeiro_formas_pagamento` de propósito: a
 * forma "pix" é de sistema (código imutável) e as chaves são dado da empresa,
 * não do catálogo de formas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('financeiro_chaves_pix')) {
            return;
        }

        Schema::create('financeiro_chaves_pix', function (Blueprint $table): void {
            $table->id();
            // cpf, cnpj, email, telefone, aleatoria
            $table->string('tipo', 20)->default('aleatoria');
            $table->string('chave', 200)->unique();
            $table->string('titular', 160)->nullable();
            $table->string('instituicao', 80)->nullable();
            // Chave preferencial: aparece primeiro e é destacada no documento.
            $table->boolean('principal')->default(false);
            $table->boolean('ativo')->default(true);
            $table->integer('ordem_exibicao')->default(0);
            $table->timestamps();

            $table->index(['ativo', 'ordem_exibicao'], 'idx_financeiro_chaves_pix_ativo_ordem');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financeiro_chaves_pix');
    }
};
