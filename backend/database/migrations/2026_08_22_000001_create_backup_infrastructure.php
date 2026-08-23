<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('backups')) {
            Schema::create('backups', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();

                $table->string('tipo', 30)->default('completo');
                $table->string('origem', 30)->default('painel');
                $table->string('conteudo', 30)->default('completo');
                // Falso para os dumps do cron de root: o painel le e restaura,
                // mas nao pode apagar nem aplicar retencao sobre eles.
                $table->boolean('gerenciado')->default(true);

                $table->string('status', 30)->default('pendente');
                $table->string('etapa_atual', 60)->nullable();
                $table->unsignedTinyInteger('progresso_percentual')->default(0);

                $table->string('arquivo_nome', 200)->nullable();
                $table->string('arquivo_caminho', 500)->nullable();
                $table->unsignedBigInteger('tamanho_bytes')->default(0);
                $table->char('sha256', 64)->nullable();
                $table->dateTime('arquivo_modificado_em', 6)->nullable();

                $table->unsignedInteger('formato_versao')->nullable();
                $table->string('cifra', 40)->nullable();
                $table->unsignedInteger('kdf_iteracoes')->nullable();
                $table->char('passphrase_fingerprint', 16)->nullable();

                $table->json('manifesto_json')->nullable();
                $table->json('bancos_incluidos')->nullable();
                $table->json('raizes_incluidas')->nullable();
                $table->json('avisos_json')->nullable();

                $table->unsignedBigInteger('total_arquivos')->default(0);
                $table->unsignedBigInteger('total_bytes_arquivos')->default(0);
                $table->string('versao_sistema', 30)->nullable();

                $table->dateTime('iniciado_em', 6)->nullable();
                $table->dateTime('heartbeat_em', 6)->nullable();
                $table->dateTime('concluido_em', 6)->nullable();
                $table->unsignedInteger('duracao_segundos')->nullable();

                $table->integer('solicitado_por')->nullable();
                $table->text('erro_mensagem')->nullable();

                $table->boolean('protegido')->default(false);
                $table->dateTime('retido_ate', 6)->nullable();

                $table->timestamps(6);

                // Torna a varredura idempotente: o mesmo arquivo nunca vira
                // duas linhas, por mais vezes que backup:varrer rode.
                $table->unique('arquivo_caminho', 'uq_backups_caminho');
                $table->index(['status', 'created_at'], 'ix_backups_status_data');
                $table->index(['tipo', 'created_at'], 'ix_backups_tipo_data');
                $table->index(['origem', 'created_at'], 'ix_backups_origem_data');
            });
        }

        if (! Schema::hasTable('backup_destinos_envios')) {
            Schema::create('backup_destinos_envios', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('backup_id')->constrained('backups')->cascadeOnDelete();
                $table->string('destino', 30);
                $table->string('alvo', 500)->nullable();
                $table->string('status', 30)->default('pendente');
                $table->unsignedBigInteger('bytes_enviados')->default(0);
                $table->boolean('sha256_verificado')->default(false);
                $table->string('referencia_externa', 255)->nullable();
                $table->unsignedInteger('tentativas')->default(0);
                $table->text('erro_mensagem')->nullable();
                $table->dateTime('iniciado_em', 6)->nullable();
                $table->dateTime('concluido_em', 6)->nullable();
                $table->timestamps(6);

                $table->unique(['backup_id', 'destino'], 'uq_bde_backup_destino');
            });
        }

        if (! Schema::hasTable('backup_restauracoes')) {
            Schema::create('backup_restauracoes', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('backup_id')->constrained('backups')->cascadeOnDelete();
                $table->string('modo', 20)->default('simulacao');
                $table->json('escopo_json')->nullable();
                $table->string('status', 30)->default('pendente');
                // O backup de seguranca tirado imediatamente antes de escrever.
                $table->unsignedBigInteger('backup_seguranca_id')->nullable();
                $table->integer('solicitado_por')->nullable();
                $table->integer('autorizado_por')->nullable();
                $table->dateTime('iniciado_em', 6)->nullable();
                $table->dateTime('concluido_em', 6)->nullable();
                $table->json('relatorio_json')->nullable();
                $table->text('erro_mensagem')->nullable();
                $table->timestamps(6);

                $table->index(['status', 'created_at'], 'ix_brest_status_data');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_restauracoes');
        Schema::dropIfExists('backup_destinos_envios');
        Schema::dropIfExists('backups');
    }
};
