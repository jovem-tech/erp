<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipe_membros', function (Blueprint $table): void {
            $table->id();
            $table->string('nome', 100);
            $table->string('email', 100)->nullable();
            $table->string('telefone', 20)->nullable();
            $table->string('cargo', 100)->nullable();
            $table->integer('usuario_id')->nullable()->unique();
            $table->foreign('usuario_id')->references('id')->on('usuarios')->nullOnDelete();
            $table->boolean('atua_tecnico')->default(false);
            $table->boolean('atua_vendas')->default(false);
            $table->boolean('atua_administrativo')->default(false);
            $table->boolean('ativo')->default(true);
            $table->text('observacoes')->nullable();
            $table->timestamps();

            $table->index(['ativo', 'atua_tecnico']);
            $table->index(['ativo', 'atua_vendas']);
            $table->index(['ativo', 'atua_administrativo']);
        });

        if (! Schema::hasTable('usuarios')) {
            return;
        }

        $now = now();

        $users = DB::table('usuarios')
            ->select(['id', 'nome', 'email', 'telefone', 'perfil', 'ativo'])
            ->orderBy('id')
            ->get();

        foreach ($users as $user) {
            $perfil = trim(mb_strtolower((string) ($user->perfil ?? '')));
            $isTechnician = $perfil === 'tecnico';
            $isAdministrative = $perfil === 'admin' || $perfil === 'atendente';

            DB::table('equipe_membros')->insert([
                'nome' => trim((string) ($user->nome ?? 'Usu?rio #' . $user->id)),
                'email' => ($email = trim((string) ($user->email ?? ''))) !== '' ? mb_strtolower($email) : null,
                'telefone' => ($telefone = trim((string) ($user->telefone ?? ''))) !== '' ? $telefone : null,
                'cargo' => $perfil !== '' ? mb_convert_case($perfil, MB_CASE_TITLE, 'UTF-8') : null,
                'usuario_id' => (int) $user->id,
                'atua_tecnico' => $isTechnician,
                'atua_vendas' => false,
                'atua_administrativo' => $isAdministrative,
                'ativo' => (bool) ($user->ativo ?? true),
                'observacoes' => 'Registro inicial migrado do cadastro de usu?rios.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('equipe_membros');
    }
};
