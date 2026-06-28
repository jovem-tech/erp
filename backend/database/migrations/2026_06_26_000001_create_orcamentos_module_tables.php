<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orcamentos')) {
            Schema::create('orcamentos', function (Blueprint $table): void {
                $table->id();
                $table->string('numero', 40)->unique();
                $table->integer('versao')->default(1);
                $table->string('tipo_orcamento', 30)->default('previo');
                $table->unsignedBigInteger('orcamento_revisao_de_id')->nullable();
                $table->string('status', 40)->default('rascunho');
                $table->string('origem', 40)->default('manual');
                $table->unsignedBigInteger('cliente_id')->nullable();
                $table->unsignedBigInteger('contato_id')->nullable();
                $table->string('cliente_nome_avulso', 160)->nullable();
                $table->string('telefone_contato', 30)->nullable();
                $table->string('email_contato', 120)->nullable();
                $table->unsignedBigInteger('os_id')->nullable();
                $table->unsignedBigInteger('equipamento_id')->nullable();
                $table->unsignedBigInteger('equipamento_tipo_id')->nullable();
                $table->unsignedBigInteger('equipamento_marca_id')->nullable();
                $table->unsignedBigInteger('equipamento_modelo_id')->nullable();
                $table->string('equipamento_cor', 100)->nullable();
                $table->string('equipamento_cor_hex', 7)->nullable();
                $table->string('equipamento_cor_rgb', 32)->nullable();
                $table->unsignedBigInteger('conversa_id')->nullable();
                $table->unsignedBigInteger('responsavel_id')->nullable();
                $table->unsignedBigInteger('criado_por')->nullable();
                $table->unsignedBigInteger('atualizado_por')->nullable();
                $table->string('titulo', 180)->nullable();
                $table->integer('validade_dias')->default(10);
                $table->date('validade_data')->nullable();
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->decimal('desconto', 12, 2)->default(0);
                $table->decimal('acrescimo', 12, 2)->default(0);
                $table->decimal('total', 12, 2)->default(0);
                $table->string('prazo_execucao', 120)->nullable();
                $table->text('observacoes')->nullable();
                $table->text('condicoes')->nullable();
                $table->string('token_publico', 80)->nullable()->unique();
                $table->dateTime('token_expira_em')->nullable();
                $table->dateTime('enviado_em')->nullable();
                $table->dateTime('aprovado_em')->nullable();
                $table->dateTime('rejeitado_em')->nullable();
                $table->dateTime('cancelado_em')->nullable();
                $table->text('motivo_rejeicao')->nullable();
                $table->string('convertido_tipo', 30)->nullable();
                $table->unsignedBigInteger('convertido_id')->nullable();
                $table->timestamps();

                $table->index(['status', 'validade_data'], 'idx_orcamentos_status_validade');
                $table->index(['cliente_id', 'created_at'], 'idx_orcamentos_cliente_created');
                $table->index(['os_id', 'created_at'], 'idx_orcamentos_os_created');
                $table->index(['conversa_id', 'created_at'], 'idx_orcamentos_conversa_created');
                $table->index('tipo_orcamento', 'idx_orcamentos_tipo_orcamento');
                $table->index('orcamento_revisao_de_id', 'idx_orcamentos_revisao_base');
            });
        }

        if (! Schema::hasTable('orcamento_itens')) {
            Schema::create('orcamento_itens', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('orcamento_id');
                $table->string('tipo_item', 30)->default('servico');
                $table->unsignedBigInteger('referencia_id')->nullable();
                $table->string('descricao', 255);
                $table->decimal('quantidade', 10, 2)->default(1);
                $table->decimal('valor_unitario', 12, 2)->default(0);
                $table->decimal('desconto', 12, 2)->default(0);
                $table->decimal('acrescimo', 12, 2)->default(0);
                $table->decimal('total', 12, 2)->default(0);
                $table->integer('ordem')->default(0);
                $table->text('observacoes')->nullable();
                $table->decimal('preco_custo_referencia', 12, 2)->nullable();
                $table->decimal('preco_venda_referencia', 12, 2)->nullable();
                $table->decimal('preco_base', 12, 2)->nullable();
                $table->decimal('percentual_encargos', 7, 2)->nullable();
                $table->decimal('valor_encargos', 12, 2)->nullable();
                $table->decimal('percentual_margem', 7, 2)->nullable();
                $table->decimal('valor_margem', 12, 2)->nullable();
                $table->decimal('valor_recomendado', 12, 2)->nullable();
                $table->string('modo_precificacao', 40)->nullable();
                $table->timestamps();

                $table->index(['orcamento_id', 'ordem'], 'idx_orcamento_itens_orcamento_ordem');
                $table->index(['tipo_item', 'referencia_id'], 'idx_orcamento_itens_tipo_referencia');
                $table->index('descricao', 'idx_orcamento_itens_descricao');
            });
        }

        if (! Schema::hasTable('orcamento_status_historico')) {
            Schema::create('orcamento_status_historico', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('orcamento_id');
                $table->string('status_anterior', 40)->nullable();
                $table->string('status_novo', 40);
                $table->text('observacao')->nullable();
                $table->string('origem', 30)->default('sistema');
                $table->unsignedBigInteger('alterado_por')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->index(['orcamento_id', 'created_at'], 'idx_orcamento_status_historico_orcamento_created');
                $table->index('status_novo', 'idx_orcamento_status_historico_status_novo');
                $table->index('origem', 'idx_orcamento_status_historico_origem');
            });
        }

        if (! Schema::hasTable('orcamento_envios')) {
            Schema::create('orcamento_envios', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('orcamento_id');
                $table->string('canal', 30);
                $table->string('destino', 255)->nullable();
                $table->text('mensagem')->nullable();
                $table->string('documento_path', 255)->nullable();
                $table->string('status', 30)->default('pendente');
                $table->string('provedor', 60)->nullable();
                $table->string('referencia_externa', 120)->nullable();
                $table->text('erro_detalhe')->nullable();
                $table->unsignedBigInteger('enviado_por')->nullable();
                $table->dateTime('enviado_em')->nullable();
                $table->timestamps();

                $table->index(['orcamento_id', 'status'], 'idx_orcamento_envios_orcamento_status');
                $table->index(['status', 'created_at'], 'idx_orcamento_envios_status_created');
                $table->index('canal', 'idx_orcamento_envios_canal');
            });
        }

        if (! Schema::hasTable('orcamento_aprovacoes')) {
            Schema::create('orcamento_aprovacoes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('orcamento_id');
                $table->string('token_publico', 90)->nullable();
                $table->string('acao', 40);
                $table->string('origem', 30)->nullable();
                $table->unsignedBigInteger('usuario_id')->nullable();
                $table->string('usuario_nome', 160)->nullable();
                $table->text('resposta_cliente')->nullable();
                $table->text('observacao')->nullable();
                $table->string('ip_origem', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->dateTime('created_at')->nullable();

                $table->index(['orcamento_id', 'created_at'], 'idx_orcamento_aprovacoes_orcamento_created');
                $table->index('acao', 'idx_orcamento_aprovacoes_acao');
                $table->index('origem', 'idx_orcamento_aprovacoes_origem');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'orcamento_aprovacoes',
            'orcamento_envios',
            'orcamento_status_historico',
            'orcamento_itens',
            'orcamentos',
        ] as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }
    }
};
