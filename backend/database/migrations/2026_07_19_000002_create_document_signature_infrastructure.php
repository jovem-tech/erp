<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Recupera com segurança uma execução interrompida durante o CREATE
        // (ex.: limite de 64 caracteres do MySQL em nome de constraint).
        // A limpeza só ocorre antes da coluna sentinela e com tabelas vazias.
        if (! Schema::hasColumn('os_documentos', 'assinado_por')) {
            if (Schema::hasTable('documento_solicitacoes_assinatura')
                && DB::table('documento_solicitacoes_assinatura')->count() === 0) {
                Schema::drop('documento_solicitacoes_assinatura');
            }
            if (Schema::hasTable('usuario_assinaturas')
                && DB::table('usuario_assinaturas')->count() === 0) {
                Schema::drop('usuario_assinaturas');
            }
        }

        if (! Schema::hasTable('usuario_assinaturas')) {
            Schema::create('usuario_assinaturas', function (Blueprint $table): void {
                $table->id();
                $table->integer('usuario_id');
                $table->string('arquivo', 255);
                $table->string('hash_sha256', 64);
                $table->string('origem', 20);
                $table->unsignedInteger('largura');
                $table->unsignedInteger('altura');
                $table->boolean('ativa')->default(true);
                $table->integer('criada_por')->nullable();
                $table->string('ip_hash', 64)->nullable();
                $table->dateTime('revogada_em')->nullable();
                $table->timestamps();

                $table->index(['usuario_id', 'ativa'], 'ix_usuario_assinaturas_ativa');
                $table->foreign('usuario_id', 'fk_user_sig_user')->references('id')->on('usuarios')->cascadeOnDelete();
                $table->foreign('criada_por', 'fk_user_sig_creator')->references('id')->on('usuarios')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('documento_solicitacoes_assinatura')) {
            Schema::create('documento_solicitacoes_assinatura', function (Blueprint $table): void {
                $table->id();
                $table->integer('os_id');
                $table->string('tipo_documento', 80);
                $table->string('tipo_signatario', 20)->default('usuario');
                $table->string('papel', 40)->default('responsavel');
                $table->string('status', 20)->default('pendente');
                $table->integer('solicitada_por');
                $table->integer('usuario_responsavel_id')->nullable();
                $table->unsignedBigInteger('assinatura_solicitante_id')->nullable();
                $table->unsignedBigInteger('documento_id')->nullable();
                $table->string('token_hash', 64)->nullable()->unique();
                $table->string('snapshot_os_hash', 64);
                $table->string('assinatura_hash', 64)->nullable();
                $table->string('assinatura_arquivo', 255)->nullable();
                $table->string('signatario_nome', 160)->nullable();
                $table->string('metodo_assinatura', 30)->nullable();
                $table->string('ip_hash', 64)->nullable();
                $table->string('user_agent_hash', 64)->nullable();
                $table->string('consentimento_versao', 30)->nullable();
                $table->dateTime('expira_em')->nullable();
                $table->dateTime('assinada_em')->nullable();
                $table->dateTime('cancelada_em')->nullable();
                $table->timestamps();

                $table->index(['usuario_responsavel_id', 'status'], 'ix_doc_assinaturas_pendentes_usuario');
                $table->index(['os_id', 'status'], 'ix_doc_assinaturas_pendentes_os');
                $table->foreign('os_id', 'fk_doc_sig_order')->references('id')->on('os')->cascadeOnDelete();
                $table->foreign('solicitada_por', 'fk_doc_sig_requester')->references('id')->on('usuarios')->restrictOnDelete();
                $table->foreign('usuario_responsavel_id', 'fk_doc_sig_responsible')->references('id')->on('usuarios')->nullOnDelete();
                $table->foreign('assinatura_solicitante_id', 'fk_doc_sig_creator_sig')->references('id')->on('usuario_assinaturas')->nullOnDelete();
                $table->foreign('documento_id', 'fk_doc_sig_document')->references('id')->on('os_documentos')->nullOnDelete();
            });
        }

        if (Schema::hasTable('os_documentos')) {
            Schema::table('os_documentos', function (Blueprint $table): void {
                if (! Schema::hasColumn('os_documentos', 'assinado_por')) {
                    $table->integer('assinado_por')->nullable()->after('gerado_por');
                    $table->foreign('assinado_por')->references('id')->on('usuarios')->nullOnDelete();
                }
                if (! Schema::hasColumn('os_documentos', 'assinatura_hash')) {
                    $table->string('assinatura_hash', 64)->nullable()->after('assinado_por');
                }
                if (! Schema::hasColumn('os_documentos', 'assinado_em')) {
                    $table->dateTime('assinado_em')->nullable()->after('assinatura_hash');
                }
                if (! Schema::hasColumn('os_documentos', 'metodo_assinatura')) {
                    $table->string('metodo_assinatura', 30)->nullable()->after('assinado_em');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('os_documentos')) {
            Schema::table('os_documentos', function (Blueprint $table): void {
                if (Schema::hasColumn('os_documentos', 'assinado_por')) {
                    $table->dropForeign(['assinado_por']);
                }
                foreach (['assinado_por', 'assinatura_hash', 'assinado_em', 'metodo_assinatura'] as $column) {
                    if (Schema::hasColumn('os_documentos', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('documento_solicitacoes_assinatura');
        Schema::dropIfExists('usuario_assinaturas');
    }
};
