<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('documento_assinatura_notificacoes')) {
            Schema::create('documento_assinatura_notificacoes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('solicitacao_id');
                $table->string('canal', 20);
                $table->string('status', 20)->default('pendente');
                $table->string('destinatario_hash', 64)->nullable();
                $table->string('destinatario_resumo', 180)->nullable();
                $table->unsignedSmallInteger('tentativas')->default(0);
                $table->string('provider', 60)->nullable();
                $table->string('referencia', 190)->nullable();
                $table->text('erro')->nullable();
                $table->dateTime('ultima_tentativa_em')->nullable();
                $table->dateTime('enviada_em')->nullable();
                $table->timestamps();

                $table->unique(['solicitacao_id', 'canal'], 'ux_doc_sig_notice_channel');
                $table->index(['status', 'tentativas', 'updated_at'], 'ix_doc_sig_notice_retry');
                $table->foreign('solicitacao_id', 'fk_doc_sig_notice_request')
                    ->references('id')
                    ->on('documento_solicitacoes_assinatura')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('mobile_notifications')) {
            Schema::table('mobile_notifications', function (Blueprint $table): void {
                $table->index(
                    ['usuario_id', 'tipo_evento', 'lida_em', 'id'],
                    'idx_mobile_notifications_usuario_tipo_lida'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mobile_notifications')) {
            Schema::table('mobile_notifications', function (Blueprint $table): void {
                $table->dropIndex('idx_mobile_notifications_usuario_tipo_lida');
            });
        }

        Schema::dropIfExists('documento_assinatura_notificacoes');
    }
};
