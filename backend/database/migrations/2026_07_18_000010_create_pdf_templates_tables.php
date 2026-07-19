<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motor central de documentos PDF — tabelas do catálogo versionado.
 *
 * `pdf_templates` é a família (1 por tipo documental registrado no
 * PdfTemplateRegistry); `pdf_template_versoes` guarda as versões
 * imutáveis do schema declarativo (rascunho -> publicado -> arquivado).
 * Não substitui `os_pdf_templates` diretamente: a tabela legada segue
 * intocada até a fase de limpeza (o conteúdo do modelo "abertura" é
 * importado como bloco texto_rico pelo seed do motor).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pdf_templates')) {
            Schema::create('pdf_templates', function (Blueprint $table): void {
                $table->id();
                $table->string('tipo_codigo', 80)->unique();
                $table->string('nome', 255);
                $table->text('descricao')->nullable();
                $table->boolean('arquivado')->default(false);
                $table->unsignedBigInteger('criado_por')->nullable();
                $table->unsignedBigInteger('atualizado_por')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('pdf_template_versoes')) {
            Schema::create('pdf_template_versoes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('template_id');
                $table->integer('versao');
                // rascunho | publicado | arquivado — no máx. 1 rascunho e 1
                // publicado por família (regra aplicada no serviço, dentro de
                // transação + lockForUpdate na linha da família).
                $table->string('status', 20)->default('rascunho');
                $table->longText('schema_json');
                $table->string('papel', 20)->default('a4');
                $table->string('orientacao', 20)->default('retrato');
                $table->string('margens_json', 255)->nullable();
                $table->string('fonte', 120)->nullable();
                $table->string('hash_schema', 64)->nullable();
                $table->dateTime('publicado_em')->nullable();
                $table->unsignedBigInteger('publicado_por')->nullable();
                $table->unsignedBigInteger('criado_por')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();

                $table->unique(['template_id', 'versao'], 'uq_pdf_template_versao');
                $table->index(['template_id', 'status'], 'idx_pdf_template_status');
                $table->foreign('template_id')->references('id')->on('pdf_templates')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_template_versoes');
        Schema::dropIfExists('pdf_templates');
    }
};
