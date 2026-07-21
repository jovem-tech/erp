<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo gerenciável de formas de pagamento.
     *
     * Migração puramente aditiva: a coluna-resumo legada
     * `financeiro.forma_pagamento` continua sendo um ENUM restrito no banco real
     * compartilhado com o sistema antigo, e NÃO é alterada aqui. Por isso o
     * catálogo carrega a flag `resumo_enum`, que marca quais códigos aquela
     * coluna aceita; formas novas (fora do ENUM) continuam sendo gravadas
     * normalmente nas colunas de detalhe, que são varchar
     * (`financeiro_movimentos.forma_pagamento`, `financeiro_conta_defaults`).
     */
    public function up(): void
    {
        if (! Schema::hasTable('financeiro_formas_pagamento')) {
            Schema::create('financeiro_formas_pagamento', function (Blueprint $table): void {
                $table->id();
                $table->string('codigo', 40)->unique();
                $table->string('nome', 60);
                // Dispara o fluxo de operadora/bandeira/parcelas/taxas.
                $table->boolean('is_cartao')->default(false);
                // Forma de sistema: código imutável e protegida contra exclusão.
                $table->boolean('sistema')->default(false);
                // Aceita na coluna-resumo ENUM legada `financeiro.forma_pagamento`.
                $table->boolean('resumo_enum')->default(false);
                $table->integer('ordem_exibicao')->default(0);
                $table->boolean('ativo')->default(true);
                $table->timestamps();
            });
        }

        $now = now();
        $seeds = [
            ['codigo' => 'dinheiro', 'nome' => 'Dinheiro', 'is_cartao' => false, 'ordem_exibicao' => 10],
            ['codigo' => 'pix', 'nome' => 'Pix', 'is_cartao' => false, 'ordem_exibicao' => 20],
            ['codigo' => 'cartao_credito', 'nome' => 'Cartão de crédito', 'is_cartao' => true, 'ordem_exibicao' => 30],
            ['codigo' => 'cartao_debito', 'nome' => 'Cartão de débito', 'is_cartao' => true, 'ordem_exibicao' => 40],
            ['codigo' => 'boleto', 'nome' => 'Boleto', 'is_cartao' => false, 'ordem_exibicao' => 50],
            ['codigo' => 'transferencia', 'nome' => 'Transferência', 'is_cartao' => false, 'ordem_exibicao' => 60],
        ];

        foreach ($seeds as $seed) {
            // Só insere o que falta: nunca sobrescreve um rótulo que o usuário
            // já tenha renomeado no cadastro.
            $exists = DB::table('financeiro_formas_pagamento')
                ->where('codigo', $seed['codigo'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('financeiro_formas_pagamento')->insert([
                'codigo' => $seed['codigo'],
                'nome' => $seed['nome'],
                'is_cartao' => $seed['is_cartao'],
                // As 6 originais são de sistema: já existem em registros
                // antigos e são os únicos códigos aceitos pelo ENUM legado.
                'sistema' => true,
                'resumo_enum' => true,
                'ordem_exibicao' => $seed['ordem_exibicao'],
                'ativo' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('financeiro_formas_pagamento');
    }
};
