<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('financeiro_dre_grupos')) {
            Schema::create('financeiro_dre_grupos', function (Blueprint $table): void {
                $table->id();
                $table->string('nome', 80)->unique();
                $table->string('descricao', 255)->nullable();
                $table->integer('ordem_exibicao')->default(0);
                $table->boolean('ativo')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('financeiro_dre_subgrupos')) {
            Schema::create('financeiro_dre_subgrupos', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('grupo_id');
                $table->string('nome', 100);
                $table->string('descricao', 255)->nullable();
                $table->integer('ordem_exibicao')->default(0);
                $table->boolean('ativo')->default(true);
                $table->timestamps();

                $table->unique(['grupo_id', 'nome'], 'ux_financeiro_dre_subgrupos_grupo_nome');
            });
        }

        if (! Schema::hasTable('financeiro_categorias')) {
            Schema::create('financeiro_categorias', function (Blueprint $table): void {
                $table->id();
                $table->string('nome', 100);
                $table->enum('tipo', ['receber', 'pagar', 'ambos'])->default('ambos');
                $table->unsignedBigInteger('dre_grupo_id')->nullable();
                $table->unsignedBigInteger('dre_subgrupo_id')->nullable();
                $table->boolean('impacta_dre_padrao')->default(true);
                $table->boolean('impacta_fluxo_caixa_padrao')->default(true);
                $table->boolean('dre_fixo_mensal_padrao')->default(false);
                $table->integer('ordem_exibicao')->default(0);
                $table->boolean('ativo')->default(true);
                $table->timestamps();

                $table->unique(['nome', 'tipo'], 'ux_financeiro_categorias_nome_tipo');
            });
        }

        if (! Schema::hasTable('financeiro')) {
            Schema::create('financeiro', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('os_id')->nullable();
                $table->unsignedBigInteger('cliente_id')->nullable();
                $table->unsignedBigInteger('fornecedor_id')->nullable();
                $table->enum('tipo', ['receber', 'pagar']);
                $table->string('categoria', 50);
                $table->string('descricao', 255);
                $table->decimal('valor', 10, 2)->default(0);
                $table->enum('forma_pagamento', ['dinheiro', 'cartao_credito', 'cartao_debito', 'pix', 'boleto', 'transferencia'])->nullable();
                $table->enum('status', ['pendente', 'parcial', 'pago', 'cancelado'])->default('pendente');
                $table->date('data_vencimento')->nullable();
                $table->date('data_pagamento')->nullable();
                $table->date('data_competencia')->nullable();
                $table->text('observacoes')->nullable();
                $table->string('origem_tipo', 40)->nullable();
                $table->unsignedBigInteger('origem_id')->nullable();
                $table->string('grupo_dre', 60)->nullable();
                $table->string('subgrupo_dre', 80)->nullable();
                $table->boolean('impacta_dre')->default(true);
                $table->boolean('impacta_fluxo_caixa')->default(true);
                $table->boolean('dre_fixo_mensal')->default(false);
                $table->timestamps();

                $table->index(['tipo', 'status'], 'idx_financeiro_tipo_status');
                $table->index(['os_id'], 'idx_financeiro_os');
                $table->index(['cliente_id'], 'idx_financeiro_cliente');
                $table->index(['data_vencimento'], 'idx_financeiro_data_vencimento');
            });
        }

        if (! Schema::hasTable('financeiro_movimentos')) {
            Schema::create('financeiro_movimentos', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('financeiro_id');
                $table->enum('tipo_movimento', ['entrada', 'saida', 'estorno', 'transferencia'])->default('entrada');
                $table->date('data_movimento');
                $table->decimal('valor_movimento', 12, 2);
                $table->string('forma_pagamento', 40)->nullable();
                $table->string('documento_ref', 100)->nullable();
                $table->text('observacoes')->nullable();
                $table->timestamps();

                $table->index(['financeiro_id'], 'idx_financeiro_movimentos_financeiro');
            });
        }

        $this->seedCatalogos();
    }

    public function down(): void
    {
        Schema::dropIfExists('financeiro_movimentos');
        Schema::dropIfExists('financeiro');
        Schema::dropIfExists('financeiro_categorias');
        Schema::dropIfExists('financeiro_dre_subgrupos');
        Schema::dropIfExists('financeiro_dre_grupos');
    }

    private function seedCatalogos(): void
    {
        if (DB::table('financeiro_dre_grupos')->count() > 0) {
            return;
        }

        $now = now();

        $grupos = [
            'Receita Operacional' => 'Receitas principais da operação',
            'Outras Receitas' => 'Receitas não recorrentes ou avulsas',
            'Despesas Operacionais' => 'Despesas administrativas e operacionais',
            'Custo Direto (OS)' => 'Custos diretamente ligados a ordens de serviço',
            'Ajustes Gerenciais' => 'Ajustes ou movimentos gerenciais',
        ];

        $grupoOrdem = ['Receita Operacional' => 10, 'Outras Receitas' => 20, 'Despesas Operacionais' => 30, 'Custo Direto (OS)' => 40, 'Ajustes Gerenciais' => 50];
        $grupoIds = [];

        foreach ($grupos as $nome => $descricao) {
            $grupoIds[$nome] = DB::table('financeiro_dre_grupos')->insertGetId([
                'nome' => $nome,
                'descricao' => $descricao,
                'ordem_exibicao' => $grupoOrdem[$nome],
                'ativo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $subgrupos = [
            ['grupo' => 'Receita Operacional', 'nome' => 'Serviços e peças de OS', 'ordem' => 10],
            ['grupo' => 'Outras Receitas', 'nome' => 'Receita avulsa', 'ordem' => 10],
            ['grupo' => 'Despesas Operacionais', 'nome' => 'Aluguel', 'ordem' => 10],
            ['grupo' => 'Despesas Operacionais', 'nome' => 'Energia', 'ordem' => 20],
            ['grupo' => 'Despesas Operacionais', 'nome' => 'Água', 'ordem' => 30],
            ['grupo' => 'Despesas Operacionais', 'nome' => 'Internet', 'ordem' => 40],
            ['grupo' => 'Despesas Operacionais', 'nome' => 'Telefonia', 'ordem' => 50],
            ['grupo' => 'Despesas Operacionais', 'nome' => 'Pessoal', 'ordem' => 60],
            ['grupo' => 'Despesas Operacionais', 'nome' => 'Taxas e impostos', 'ordem' => 70],
            ['grupo' => 'Despesas Operacionais', 'nome' => 'Despesa operacional', 'ordem' => 80],
            ['grupo' => 'Custo Direto (OS)', 'nome' => 'Compra emergencial de peças', 'ordem' => 10],
            ['grupo' => 'Ajustes Gerenciais', 'nome' => 'Ajuste gerencial', 'ordem' => 10],
        ];

        $subgrupoIds = [];

        foreach ($subgrupos as $item) {
            $grupoId = $grupoIds[$item['grupo']];
            $subgrupoIds[$item['grupo'] . '|' . $item['nome']] = DB::table('financeiro_dre_subgrupos')->insertGetId([
                'grupo_id' => $grupoId,
                'nome' => $item['nome'],
                'ordem_exibicao' => $item['ordem'],
                'ativo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $categorias = [
            ['nome' => 'Serviço', 'tipo' => 'receber', 'grupo' => 'Receita Operacional', 'subgrupo' => 'Serviços e peças de OS', 'dre' => true, 'fluxo' => true, 'fixo' => false, 'ordem' => 10],
            ['nome' => 'Venda de peças', 'tipo' => 'receber', 'grupo' => 'Receita Operacional', 'subgrupo' => 'Serviços e peças de OS', 'dre' => true, 'fluxo' => true, 'fixo' => false, 'ordem' => 20],
            ['nome' => 'Receita avulsa', 'tipo' => 'receber', 'grupo' => 'Outras Receitas', 'subgrupo' => 'Receita avulsa', 'dre' => true, 'fluxo' => true, 'fixo' => false, 'ordem' => 30],
            ['nome' => 'Aluguel', 'tipo' => 'pagar', 'grupo' => 'Despesas Operacionais', 'subgrupo' => 'Aluguel', 'dre' => true, 'fluxo' => true, 'fixo' => true, 'ordem' => 10],
            ['nome' => 'Energia', 'tipo' => 'pagar', 'grupo' => 'Despesas Operacionais', 'subgrupo' => 'Energia', 'dre' => true, 'fluxo' => true, 'fixo' => true, 'ordem' => 20],
            ['nome' => 'Água', 'tipo' => 'pagar', 'grupo' => 'Despesas Operacionais', 'subgrupo' => 'Água', 'dre' => true, 'fluxo' => true, 'fixo' => true, 'ordem' => 30],
            ['nome' => 'Internet', 'tipo' => 'pagar', 'grupo' => 'Despesas Operacionais', 'subgrupo' => 'Internet', 'dre' => true, 'fluxo' => true, 'fixo' => true, 'ordem' => 40],
            ['nome' => 'Telefonia', 'tipo' => 'pagar', 'grupo' => 'Despesas Operacionais', 'subgrupo' => 'Telefonia', 'dre' => true, 'fluxo' => true, 'fixo' => true, 'ordem' => 50],
            ['nome' => 'Compra de peças', 'tipo' => 'pagar', 'grupo' => 'Custo Direto (OS)', 'subgrupo' => 'Compra emergencial de peças', 'dre' => false, 'fluxo' => true, 'fixo' => false, 'ordem' => 60],
            ['nome' => 'Impostos e taxas', 'tipo' => 'pagar', 'grupo' => 'Despesas Operacionais', 'subgrupo' => 'Taxas e impostos', 'dre' => true, 'fluxo' => true, 'fixo' => false, 'ordem' => 70],
            ['nome' => 'Folha e pró-labore', 'tipo' => 'pagar', 'grupo' => 'Despesas Operacionais', 'subgrupo' => 'Pessoal', 'dre' => true, 'fluxo' => true, 'fixo' => true, 'ordem' => 80],
            ['nome' => 'Despesa administrativa', 'tipo' => 'pagar', 'grupo' => 'Despesas Operacionais', 'subgrupo' => 'Despesa operacional', 'dre' => true, 'fluxo' => true, 'fixo' => false, 'ordem' => 90],
        ];

        foreach ($categorias as $item) {
            DB::table('financeiro_categorias')->insert([
                'nome' => $item['nome'],
                'tipo' => $item['tipo'],
                'dre_grupo_id' => $grupoIds[$item['grupo']],
                'dre_subgrupo_id' => $subgrupoIds[$item['grupo'] . '|' . $item['subgrupo']],
                'impacta_dre_padrao' => $item['dre'],
                'impacta_fluxo_caixa_padrao' => $item['fluxo'],
                'dre_fixo_mensal_padrao' => $item['fixo'],
                'ordem_exibicao' => $item['ordem'],
                'ativo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
