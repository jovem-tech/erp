<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela unica da Agenda: compromissos manuais e obrigacoes geradas por outros
 * modulos vivem na mesma linha do tempo.
 *
 * O par (origem_tipo, origem_id) e a chave da reconciliacao com o modulo de
 * origem - e por isso e UNIQUE: uma conta a pagar nunca pode virar dois
 * compromissos, mesmo que o reconciliador rode duas vezes em paralelo. Itens
 * manuais tem os dois campos nulos (MySQL nao considera NULL em unique).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agenda_compromissos')) {
            Schema::create('agenda_compromissos', function (Blueprint $table): void {
                $table->id();

                $table->string('titulo', 180);
                $table->text('descricao')->nullable();

                // 'manual' ou a chave de uma AgendaSource (conta_pagar,
                // retorno_pos_servico, prazo_os, cobranca_os, ...). String em
                // vez de ENUM de proposito: modulo novo nao deve exigir ALTER.
                $table->string('tipo', 40)->default('manual');
                $table->string('origem_tipo', 60)->nullable();
                $table->unsignedBigInteger('origem_id')->nullable();

                $table->dateTime('inicio_em');
                $table->dateTime('fim_em')->nullable();
                $table->boolean('dia_inteiro')->default(false);

                $table->string('status', 20)->default('pendente');
                $table->string('prioridade', 10)->default('normal');

                $table->unsignedBigInteger('responsavel_id')->nullable();
                $table->unsignedBigInteger('cliente_id')->nullable();
                $table->unsignedBigInteger('os_id')->nullable();

                // Minutos antes do inicio. Vira reminders.overrides no Google -
                // e o que faz o celular tocar, objetivo declarado do modulo.
                $table->unsignedInteger('lembrete_minutos')->nullable();

                $table->string('google_event_id', 200)->nullable();
                $table->string('google_etag', 120)->nullable();
                // Hash do conteudo que empurramos por ultimo. Sem ele o sync
                // bidirecional entra em loop: nosso push gera um "updated" no
                // Google, o pull seguinte le esse update como se fosse do
                // usuario e reescreve, o que gera outro push, e assim por diante.
                $table->char('google_sync_hash', 40)->nullable();
                $table->string('google_sync_estado', 20)->default('pendente');
                $table->text('google_sync_erro')->nullable();
                $table->dateTime('google_sincronizado_em')->nullable();

                $table->dateTime('concluido_em')->nullable();
                $table->unsignedBigInteger('concluido_por')->nullable();
                $table->unsignedBigInteger('criado_por')->nullable();

                $table->timestamps();

                $table->unique(['origem_tipo', 'origem_id'], 'uq_agenda_origem');
                $table->unique('google_event_id', 'uq_agenda_google_event');
                $table->index(['status', 'inicio_em'], 'idx_agenda_status_inicio');
                $table->index(['responsavel_id', 'inicio_em'], 'idx_agenda_responsavel_inicio');
                $table->index('cliente_id', 'idx_agenda_cliente');
                $table->index('os_id', 'idx_agenda_os');
                // Fila do push: o job varre por este par.
                $table->index(['google_sync_estado', 'updated_at'], 'idx_agenda_sync_estado');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agenda_compromissos');
    }
};
