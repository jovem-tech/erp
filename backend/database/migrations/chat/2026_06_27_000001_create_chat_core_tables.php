<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Banco logico separado do ERP (mesma instancia MySQL), ver
     * specs/010-inbox-whatsapp-tempo-real/plan.md ("Banco de dados: instancia separada").
     */
    protected $connection = 'chat';

    public function up(): void
    {
        if (! Schema::hasTable('contas_atendimento')) {
            Schema::create('contas_atendimento', function (Blueprint $table): void {
                $table->id();
                $table->string('nome', 120);
                $table->unsignedBigInteger('proximo_display_id')->default(1);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('canais_whatsapp')) {
            Schema::create('canais_whatsapp', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('conta_id');
                $table->string('nome', 120);
                $table->string('provider', 30)->default('evolution');
                $table->boolean('ativo')->default(true);
                $table->timestamps();

                $table->index(['conta_id'], 'idx_canais_whatsapp_conta');
            });
        }

        if (! Schema::hasTable('caixas_entrada')) {
            Schema::create('caixas_entrada', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('conta_id');
                $table->string('nome', 120);
                $table->string('channel_type', 30);
                $table->unsignedBigInteger('channel_id');
                $table->timestamps();

                $table->index(['conta_id'], 'idx_caixas_entrada_conta');
                $table->index(['channel_type', 'channel_id'], 'idx_caixas_entrada_channel');
            });
        }

        if (! Schema::hasTable('contatos')) {
            Schema::create('contatos', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('conta_id');
                $table->string('nome', 150)->nullable();
                $table->string('telefone', 20)->nullable();
                $table->string('email', 150)->nullable();
                $table->string('identifier', 100)->nullable();
                $table->unsignedBigInteger('cliente_id')->nullable();
                $table->json('custom_attributes')->nullable();
                $table->timestamps();

                $table->unique(['conta_id', 'telefone'], 'ux_contatos_conta_telefone');
                $table->unique(['conta_id', 'email'], 'ux_contatos_conta_email');
                $table->index(['cliente_id'], 'idx_contatos_cliente');
            });
        }

        if (! Schema::hasTable('contatos_caixas_entrada')) {
            Schema::create('contatos_caixas_entrada', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('conta_id');
                $table->unsignedBigInteger('contato_id');
                $table->unsignedBigInteger('caixa_entrada_id');
                $table->string('source_id', 60);
                $table->timestamps();

                $table->unique(['caixa_entrada_id', 'source_id'], 'ux_contatos_caixas_entrada_inbox_source');
                $table->index(['contato_id'], 'idx_contatos_caixas_entrada_contato');
            });
        }

        if (! Schema::hasTable('conversas')) {
            Schema::create('conversas', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('conta_id');
                $table->unsignedBigInteger('caixa_entrada_id');
                $table->unsignedBigInteger('contato_id');
                $table->unsignedBigInteger('contato_caixa_entrada_id');
                $table->unsignedBigInteger('display_id');
                $table->enum('status', ['open', 'resolved', 'pending', 'snoozed'])->default('open');
                $table->unsignedBigInteger('assignee_id')->nullable();
                $table->timestamp('last_activity_at')->nullable();
                $table->timestamp('lida_em')->nullable();
                $table->json('custom_attributes')->nullable();
                $table->timestamps();

                $table->unique(['conta_id', 'display_id'], 'ux_conversas_conta_display_id');
                $table->index(['status'], 'idx_conversas_status');
                $table->index(['contato_caixa_entrada_id'], 'idx_conversas_contato_inbox');
            });
        }

        if (! Schema::hasTable('mensagens')) {
            Schema::create('mensagens', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('conta_id');
                $table->unsignedBigInteger('conversa_id');
                $table->unsignedBigInteger('caixa_entrada_id');
                $table->enum('message_type', ['incoming', 'outgoing', 'activity'])->default('incoming');
                $table->string('sender_type', 20)->nullable();
                $table->unsignedBigInteger('sender_id')->nullable();
                $table->text('conteudo')->nullable();
                $table->json('content_attributes')->nullable();
                $table->string('source_id', 100)->nullable();
                $table->enum('status', ['pending', 'sent', 'delivered', 'read', 'failed'])->default('sent');
                $table->timestamps();

                $table->unique(['caixa_entrada_id', 'source_id'], 'ux_mensagens_inbox_source');
                $table->index(['conversa_id'], 'idx_mensagens_conversa');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mensagens');
        Schema::dropIfExists('conversas');
        Schema::dropIfExists('contatos_caixas_entrada');
        Schema::dropIfExists('contatos');
        Schema::dropIfExists('caixas_entrada');
        Schema::dropIfExists('canais_whatsapp');
        Schema::dropIfExists('contas_atendimento');
    }
};
