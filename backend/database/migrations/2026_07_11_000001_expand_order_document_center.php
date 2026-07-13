<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('os_documentos')) {
            Schema::table('os_documentos', function (Blueprint $table): void {
                if (! Schema::hasColumn('os_documentos', 'template_codigo')) {
                    $table->string('template_codigo', 80)->nullable()->after('hash_sha1');
                }

                if (! Schema::hasColumn('os_documentos', 'hash_sha256')) {
                    $table->string('hash_sha256', 64)->nullable()->after('hash_sha1');
                }

                if (! Schema::hasColumn('os_documentos', 'idempotency_key')) {
                    $table->string('idempotency_key', 120)->nullable()->after('hash_sha256');
                }

                if (! Schema::hasColumn('os_documentos', 'metadados_json')) {
                    $table->longText('metadados_json')->nullable()->after('idempotency_key');
                }

                if (! Schema::hasColumn('os_documentos', 'arquivado_em')) {
                    $table->dateTime('arquivado_em')->nullable()->after('updated_at');
                }

                if (! Schema::hasColumn('os_documentos', 'arquivado_por')) {
                    $table->integer('arquivado_por')->nullable()->after('arquivado_em');
                }
            });
        }

        if (Schema::hasTable('os_documentos') && ! Schema::hasTable('os_documento_arquivos')) {
            Schema::create('os_documento_arquivos', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('documento_id');
                $table->string('formato', 20);
                $table->string('arquivo', 255);
                $table->string('mime', 120)->nullable();
                $table->unsignedBigInteger('tamanho_bytes')->nullable();
                $table->string('hash_sha256', 64)->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->unique(['documento_id', 'formato'], 'ux_os_doc_arquivos_documento_formato');
                $table->foreign('documento_id')->references('id')->on('os_documentos')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('os') && ! Schema::hasTable('os_documento_envios')) {
            Schema::create('os_documento_envios', function (Blueprint $table): void {
                $table->id();
                $table->integer('os_id');
                $table->unsignedBigInteger('documento_id')->nullable();
                $table->string('canal', 20);
                $table->string('destino_mascarado', 255)->nullable();
                $table->longText('destino_criptografado')->nullable();
                $table->string('template_codigo', 80)->nullable();
                $table->longText('mensagem_final')->nullable();
                $table->string('status', 40)->default('pendente');
                $table->string('provedor', 80)->nullable();
                $table->string('referencia_externa', 120)->nullable();
                $table->text('erro_sanitizado')->nullable();
                $table->integer('enviado_por')->nullable();
                $table->longText('metadados_json')->nullable();
                $table->dateTime('enviado_em')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->foreign('os_id')->references('id')->on('os')->cascadeOnDelete();
                $table->foreign('documento_id')->references('id')->on('os_documentos')->nullOnDelete();
                $table->foreign('enviado_por')->references('id')->on('usuarios')->nullOnDelete();
            });
        }

        if (Schema::hasTable('os') && ! Schema::hasTable('os_documento_links')) {
            Schema::create('os_documento_links', function (Blueprint $table): void {
                $table->id();
                $table->integer('os_id');
                $table->string('token_hash', 64)->unique();
                $table->string('formato_padrao', 20)->nullable();
                $table->integer('criado_por')->nullable();
                $table->integer('revogado_por')->nullable();
                $table->dateTime('expira_em')->nullable();
                $table->dateTime('revogado_em')->nullable();
                $table->unsignedInteger('acessos_count')->default(0);
                $table->dateTime('ultimo_acesso_em')->nullable();
                $table->string('ultimo_acesso_ip_hash', 64)->nullable();
                $table->longText('metadados_json')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->foreign('os_id')->references('id')->on('os')->cascadeOnDelete();
                $table->foreign('criado_por')->references('id')->on('usuarios')->nullOnDelete();
                $table->foreign('revogado_por')->references('id')->on('usuarios')->nullOnDelete();
            });
        }

        if (Schema::hasTable('os_documento_links') && ! Schema::hasTable('os_documento_link_itens')) {
            Schema::create('os_documento_link_itens', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('link_id');
                $table->unsignedBigInteger('documento_id');
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->unique(['link_id', 'documento_id'], 'ux_os_doc_link_documento');
                $table->foreign('link_id')->references('id')->on('os_documento_links')->cascadeOnDelete();
                $table->foreign('documento_id')->references('id')->on('os_documentos')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('os_documento_link_itens');
        Schema::dropIfExists('os_documento_links');
        Schema::dropIfExists('os_documento_envios');
        Schema::dropIfExists('os_documento_arquivos');

        if (Schema::hasTable('os_documentos')) {
            Schema::table('os_documentos', function (Blueprint $table): void {
                foreach (['template_codigo', 'hash_sha256', 'idempotency_key', 'metadados_json', 'arquivado_em', 'arquivado_por'] as $column) {
                    if (Schema::hasColumn('os_documentos', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
