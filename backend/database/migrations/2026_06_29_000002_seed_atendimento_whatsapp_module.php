<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modulos')) {
            return;
        }

        $exists = DB::table('modulos')
            ->where('slug', 'atendimento_whatsapp')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('modulos')->insert([
            'nome' => 'Atendimento WhatsApp',
            'slug' => 'atendimento_whatsapp',
            'icone' => 'bi-chat-dots',
            'ordem_menu' => 70,
            'ativo' => 1,
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('modulos')) {
            return;
        }

        DB::table('modulos')
            ->where('slug', 'atendimento_whatsapp')
            ->delete();
    }
};
