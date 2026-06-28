<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estas tabelas ja existem no banco de producao compartilhado (origem
     * legada). Os guardas hasTable() tornam esta migration um no-op la, mas
     * garantem que um ambiente novo (instalacao limpa, CI, testes) tenha o
     * mesmo schema sem depender de um dump externo.
     */
    public function up(): void
    {
        if (! Schema::hasTable('financeiro_cartao_operadoras')) {
            Schema::create('financeiro_cartao_operadoras', function (Blueprint $table): void {
                $table->id();
                $table->string('nome', 100);
                $table->string('descricao', 255)->nullable();
                $table->integer('ordem_exibicao')->default(0);
                $table->integer('prazo_padrao_dias')->default(30);
                $table->boolean('ativo')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('financeiro_cartao_bandeiras')) {
            Schema::create('financeiro_cartao_bandeiras', function (Blueprint $table): void {
                $table->id();
                $table->string('nome', 80);
                $table->integer('ordem_exibicao')->default(0);
                $table->boolean('ativo')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('financeiro_cartao_taxas')) {
            Schema::create('financeiro_cartao_taxas', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('operadora_id');
                $table->unsignedBigInteger('bandeira_id')->nullable();
                $table->string('modalidade', 20)->default('credito');
                $table->integer('parcelas_inicial')->default(1);
                $table->integer('parcelas_final')->default(1);
                $table->decimal('taxa_percentual', 8, 4)->default(0);
                $table->decimal('taxa_fixa', 10, 2)->default(0);
                $table->integer('prazo_recebimento_dias')->default(30);
                $table->string('observacoes', 255)->nullable();
                $table->boolean('ativo')->default(true);
                $table->timestamps();

                $table->foreign('operadora_id')->references('id')->on('financeiro_cartao_operadoras')->cascadeOnDelete();
                $table->foreign('bandeira_id')->references('id')->on('financeiro_cartao_bandeiras')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('financeiro_movimentos_cartao')) {
            Schema::create('financeiro_movimentos_cartao', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('movimento_id');
                $table->unsignedBigInteger('operadora_id')->nullable();
                $table->unsignedBigInteger('bandeira_id')->nullable();
                $table->unsignedBigInteger('taxa_id')->nullable();
                $table->string('modalidade', 20)->default('credito');
                $table->integer('parcelas')->default(1);
                $table->decimal('valor_bruto', 12, 2)->default(0);
                $table->decimal('taxa_percentual', 8, 4)->default(0);
                $table->decimal('taxa_fixa', 10, 2)->default(0);
                $table->decimal('valor_taxa', 12, 2)->default(0);
                $table->decimal('valor_liquido', 12, 2)->default(0);
                $table->integer('prazo_recebimento_dias')->default(0);
                $table->date('data_competencia')->nullable();
                $table->date('data_prevista_repasse')->nullable();
                $table->date('data_prevista_recebimento')->nullable();
                $table->date('data_credito_efetivo')->nullable();
                $table->string('observacoes', 255)->nullable();
                $table->timestamps();

                $table->foreign('movimento_id')->references('id')->on('financeiro_movimentos')->cascadeOnDelete();
                $table->foreign('operadora_id')->references('id')->on('financeiro_cartao_operadoras')->nullOnDelete();
                $table->foreign('bandeira_id')->references('id')->on('financeiro_cartao_bandeiras')->nullOnDelete();
                $table->foreign('taxa_id')->references('id')->on('financeiro_cartao_taxas')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('os_cobranca_agendamentos')) {
            Schema::create('os_cobranca_agendamentos', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('os_id');
                $table->unsignedBigInteger('financeiro_id')->nullable();
                $table->unsignedBigInteger('cliente_id')->nullable();
                $table->string('canal', 20)->default('whatsapp');
                $table->integer('prazo_dias')->default(1);
                $table->dateTime('enviar_em');
                $table->string('status', 20)->default('pendente');
                $table->dateTime('ultima_tentativa_em')->nullable();
                $table->dateTime('enviado_em')->nullable();
                $table->text('mensagem_enviada')->nullable();
                $table->longText('retorno_payload')->nullable();
                $table->timestamps();

                $table->index('os_id');
                $table->index('financeiro_id');
                $table->index('cliente_id');
                $table->index('status');
                $table->index('enviar_em');
            });
        }

        if (! Schema::hasTable('crm_followups')) {
            Schema::create('crm_followups', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('cliente_id')->nullable();
                $table->unsignedBigInteger('os_id')->nullable();
                $table->string('titulo', 180);
                $table->text('descricao')->nullable();
                $table->dateTime('data_prevista');
                $table->string('status', 30)->default('pendente');
                $table->unsignedBigInteger('usuario_responsavel')->nullable();
                $table->string('origem_evento', 80)->nullable();
                $table->dateTime('concluido_em')->nullable();
                $table->timestamps();

                $table->index('cliente_id');
                $table->index('os_id');
                $table->index('origem_evento', 'idx_crm_followups_origem_evento');
                $table->index(['status', 'data_prevista']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_followups');
        Schema::dropIfExists('os_cobranca_agendamentos');
        Schema::dropIfExists('financeiro_movimentos_cartao');
        Schema::dropIfExists('financeiro_cartao_taxas');
        Schema::dropIfExists('financeiro_cartao_bandeiras');
        Schema::dropIfExists('financeiro_cartao_operadoras');
    }
};
