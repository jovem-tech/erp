<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('financeiro_contas')) {
            Schema::create('financeiro_contas', function (Blueprint $table): void {
                $table->id();
                $table->string('nome', 100)->unique();
                $table->enum('tipo', ['caixa', 'banco', 'adquirente', 'reserva', 'carteira_digital', 'outra']);
                $table->string('instituicao', 100)->nullable();
                $table->date('data_inicio_controle');
                $table->boolean('considera_disponivel')->default(true);
                $table->boolean('ativo')->default(true);
                $table->string('cor', 7)->default('#3868B0');
                $table->text('observacoes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->index(['ativo', 'tipo'], 'idx_fin_contas_ativo_tipo');
                $table->index(['data_inicio_controle'], 'idx_fin_contas_inicio');
            });
        }

        if (! Schema::hasTable('financeiro_transferencias')) {
            Schema::create('financeiro_transferencias', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('conta_origem_id');
                $table->unsignedBigInteger('conta_destino_id');
                $table->date('data_transferencia');
                $table->decimal('valor', 14, 2);
                $table->string('descricao', 255);
                $table->string('documento_ref', 100)->nullable();
                $table->enum('status', ['realizada', 'cancelada'])->default('realizada');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('cancelado_por')->nullable();
                $table->timestamp('cancelado_em')->nullable();
                $table->string('motivo_cancelamento', 500)->nullable();
                $table->timestamps();

                $table->index(['conta_origem_id', 'data_transferencia'], 'idx_fin_transf_origem_data');
                $table->index(['conta_destino_id', 'data_transferencia'], 'idx_fin_transf_destino_data');
                $table->index(['status', 'data_transferencia'], 'idx_fin_transf_status_data');
                $table->foreign('conta_origem_id', 'fk_fin_transf_origem')
                    ->references('id')->on('financeiro_contas')->restrictOnDelete();
                $table->foreign('conta_destino_id', 'fk_fin_transf_destino')
                    ->references('id')->on('financeiro_contas')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('financeiro_conta_movimentos')) {
            Schema::create('financeiro_conta_movimentos', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('conta_financeira_id');
                $table->unsignedBigInteger('transferencia_id')->nullable();
                $table->enum('tipo', ['saldo_inicial', 'ajuste', 'transferencia']);
                $table->enum('natureza', ['entrada', 'saida']);
                $table->enum('status', ['realizado', 'cancelado'])->default('realizado');
                $table->date('data_movimento');
                $table->decimal('valor', 14, 2);
                $table->string('descricao', 255);
                $table->string('documento_ref', 100)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('cancelado_por')->nullable();
                $table->timestamp('cancelado_em')->nullable();
                $table->string('motivo_cancelamento', 500)->nullable();
                $table->timestamps();

                $table->index(['conta_financeira_id', 'status', 'data_movimento'], 'idx_fin_conta_mov_saldo');
                $table->index(['transferencia_id'], 'idx_fin_conta_mov_transf');
                $table->foreign('conta_financeira_id', 'fk_fin_conta_mov_conta')
                    ->references('id')->on('financeiro_contas')->restrictOnDelete();
                $table->foreign('transferencia_id', 'fk_fin_conta_mov_transf')
                    ->references('id')->on('financeiro_transferencias')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('financeiro_conta_defaults')) {
            Schema::create('financeiro_conta_defaults', function (Blueprint $table): void {
                $table->id();
                $table->string('forma_pagamento', 40)->unique();
                $table->unsignedBigInteger('conta_financeira_id');
                $table->timestamps();

                $table->index(['conta_financeira_id'], 'idx_fin_conta_default_conta');
                $table->foreign('conta_financeira_id', 'fk_fin_conta_default_conta')
                    ->references('id')->on('financeiro_contas')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('financeiro_movimentos') && ! Schema::hasColumn('financeiro_movimentos', 'conta_financeira_id')) {
            Schema::table('financeiro_movimentos', function (Blueprint $table): void {
                $table->unsignedBigInteger('conta_financeira_id')->nullable()->after('financeiro_id');
                $table->index(['conta_financeira_id', 'data_movimento'], 'idx_fin_mov_conta_data');
                $table->foreign('conta_financeira_id', 'fk_fin_mov_conta')
                    ->references('id')->on('financeiro_contas')->nullOnDelete();
            });
        }

        if (Schema::hasTable('financeiro_movimentos_cartao') && ! Schema::hasColumn('financeiro_movimentos_cartao', 'credito_confirmado_por')) {
            Schema::table('financeiro_movimentos_cartao', function (Blueprint $table): void {
                $table->unsignedBigInteger('credito_confirmado_por')->nullable()->after('data_credito_efetivo');
                $table->timestamp('credito_confirmado_em')->nullable()->after('credito_confirmado_por');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('financeiro_movimentos_cartao') && Schema::hasColumn('financeiro_movimentos_cartao', 'credito_confirmado_por')) {
            Schema::table('financeiro_movimentos_cartao', function (Blueprint $table): void {
                $table->dropColumn(['credito_confirmado_por', 'credito_confirmado_em']);
            });
        }

        if (Schema::hasTable('financeiro_movimentos') && Schema::hasColumn('financeiro_movimentos', 'conta_financeira_id')) {
            Schema::table('financeiro_movimentos', function (Blueprint $table): void {
                $table->dropForeign('fk_fin_mov_conta');
                $table->dropIndex('idx_fin_mov_conta_data');
                $table->dropColumn('conta_financeira_id');
            });
        }

        Schema::dropIfExists('financeiro_conta_defaults');
        Schema::dropIfExists('financeiro_conta_movimentos');
        Schema::dropIfExists('financeiro_transferencias');
        Schema::dropIfExists('financeiro_contas');
    }
};
