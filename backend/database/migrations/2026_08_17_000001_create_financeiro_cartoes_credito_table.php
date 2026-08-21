<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('financeiro_cartoes_credito')) {
            Schema::create('financeiro_cartoes_credito', function (Blueprint $table): void {
                $table->id();
                $table->string('nome', 100)->unique();
                $table->string('instituicao', 100)->nullable();
                $table->string('final_cartao', 4)->nullable();
                $table->unsignedTinyInteger('dia_fechamento');
                $table->unsignedTinyInteger('dia_vencimento');
                $table->string('cor', 7)->default('#3868B0');
                $table->boolean('ativo')->default(true);
                $table->text('observacoes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->index(['ativo'], 'idx_fin_cartoes_credito_ativo');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('financeiro_cartoes_credito');
    }
};
