<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('documento_solicitacoes_assinatura')) {
            return;
        }

        Schema::table('documento_solicitacoes_assinatura', function (Blueprint $table): void {
            if (! Schema::hasColumn('documento_solicitacoes_assinatura', 'revisada_por')) {
                $table->integer('revisada_por')->nullable()->after('snapshot_os_hash');
                $table->foreign('revisada_por', 'fk_doc_sig_reviewer')
                    ->references('id')
                    ->on('usuarios')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('documento_solicitacoes_assinatura', 'revisao_snapshot_hash')) {
                $table->string('revisao_snapshot_hash', 64)->nullable()->after('revisada_por');
            }
            if (! Schema::hasColumn('documento_solicitacoes_assinatura', 'revisao_ip_hash')) {
                $table->string('revisao_ip_hash', 64)->nullable()->after('revisao_snapshot_hash');
            }
            if (! Schema::hasColumn('documento_solicitacoes_assinatura', 'revisao_user_agent_hash')) {
                $table->string('revisao_user_agent_hash', 64)->nullable()->after('revisao_ip_hash');
            }
            if (! Schema::hasColumn('documento_solicitacoes_assinatura', 'revisada_em')) {
                $table->dateTime('revisada_em')->nullable()->after('revisao_user_agent_hash');
                $table->index(['revisada_por', 'revisada_em'], 'ix_doc_sig_review_actor_date');
            }
            if (! Schema::hasColumn('documento_solicitacoes_assinatura', 'revisao_confirmada_em')) {
                $table->dateTime('revisao_confirmada_em')->nullable()->after('revisada_em');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('documento_solicitacoes_assinatura')) {
            return;
        }

        Schema::table('documento_solicitacoes_assinatura', function (Blueprint $table): void {
            if (Schema::hasColumn('documento_solicitacoes_assinatura', 'revisada_por')) {
                $table->dropForeign('fk_doc_sig_reviewer');
            }
            if (Schema::hasColumn('documento_solicitacoes_assinatura', 'revisada_em')) {
                $table->dropIndex('ix_doc_sig_review_actor_date');
            }
            foreach ([
                'revisada_por',
                'revisao_snapshot_hash',
                'revisao_ip_hash',
                'revisao_user_agent_hash',
                'revisada_em',
                'revisao_confirmada_em',
            ] as $column) {
                if (Schema::hasColumn('documento_solicitacoes_assinatura', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
