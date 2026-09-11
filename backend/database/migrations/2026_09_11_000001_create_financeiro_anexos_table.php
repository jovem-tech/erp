<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('financeiro') && ! Schema::hasTable('financeiro_anexos')) {
            Schema::create('financeiro_anexos', function (Blueprint $table): void {
                $table->id();
                $table->integer('financeiro_id');
                $table->string('nome_original', 255);
                $table->string('descricao', 190)->nullable();
                $table->string('arquivo', 255);
                $table->string('mime', 120)->nullable();
                $table->unsignedBigInteger('tamanho_bytes')->nullable();
                $table->string('hash_sha256', 64)->nullable();
                $table->integer('usuario_id')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();

                $table->index(['financeiro_id', 'created_at'], 'idx_financeiro_anexos_financeiro_created');
                $table->foreign('financeiro_id')->references('id')->on('financeiro')->cascadeOnDelete();
                $table->foreign('usuario_id')->references('id')->on('usuarios')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('financeiro_anexos');
    }
};
