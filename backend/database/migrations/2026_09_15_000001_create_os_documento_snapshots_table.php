<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot por versão documental: o JSON com os dados do instante da emissão
 * + o template usado. O PDF é renderizado sob demanda a partir dele, então
 * "v1 — 13/09" continua reproduzindo o que foi emitido em 13/09 sem guardar
 * o binário. Aplicar com `php artisan migrate --path=...` (o migrate geral
 * está bloqueado pela migration do chat) e espelhar em BuildsLegacyErpSchema.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('os_documento_snapshots')) {
            return;
        }

        Schema::create('os_documento_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('documento_id');
            $table->unsignedSmallInteger('versao_formato')->default(1);
            $table->string('tipo_codigo', 80);
            $table->unsignedBigInteger('template_id')->nullable();
            $table->unsignedBigInteger('template_versao_id')->nullable();
            $table->unsignedInteger('template_versao')->nullable();
            $table->string('hash_schema', 64)->nullable();
            $table->string('hash_snapshot', 64);
            $table->string('formatos', 40)->default('a4,80mm');
            $table->unsignedInteger('tamanho_bytes')->default(0);
            $table->longText('snapshot_json');
            $table->timestamps();

            $table->unique('documento_id', 'ux_os_doc_snapshots_documento');
            $table->index('hash_snapshot', 'ix_os_doc_snapshots_hash');
            $table->foreign('documento_id')->references('id')->on('os_documentos')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('os_documento_snapshots');
    }
};
