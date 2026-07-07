<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('os_procedimentos_historico')) {
            Schema::create('os_procedimentos_historico', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('os_id');
                $table->text('descricao');
                $table->unsignedBigInteger('usuario_id')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->index(['os_id', 'created_at'], 'idx_os_procedimentos_historico_os_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('os_procedimentos_historico');
    }
};
