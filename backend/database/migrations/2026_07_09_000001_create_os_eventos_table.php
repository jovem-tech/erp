<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('os_eventos')) {
            Schema::create('os_eventos', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('os_id');
                $table->string('categoria', 20);
                $table->string('tipo', 60);
                $table->string('titulo', 160);
                $table->text('descricao')->nullable();
                $table->json('dados')->nullable();
                $table->unsignedBigInteger('usuario_id')->nullable();
                $table->string('origem', 20)->default('sistema');
                // Chave de deduplicacao do backfill (os:backfill-eventos):
                // eventos ao vivo deixam ambas NULL. `tipo` participa da unique
                // porque uma linha legada pode legitimamente gerar 2 eventos
                // (ex.: orcamento_envios -> mensagem + documento).
                $table->string('legacy_tabela', 60)->nullable();
                $table->unsignedBigInteger('legacy_id')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->index(['os_id', 'created_at'], 'idx_os_eventos_os_created');
                $table->index(['os_id', 'categoria', 'created_at'], 'idx_os_eventos_os_categoria_created');
                $table->unique(['legacy_tabela', 'legacy_id', 'tipo'], 'uniq_os_eventos_legacy');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('os_eventos');
    }
};
