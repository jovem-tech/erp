<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modulos')
            || ! Schema::hasTable('grupos')
            || ! Schema::hasTable('permissoes')
            || ! Schema::hasTable('grupo_permissoes')) {
            return;
        }

        DB::table('permissoes')->updateOrInsert(
            ['slug' => 'excluir'],
            ['nome' => 'Excluir']
        );

        $moduleId = (int) DB::table('modulos')->where('slug', 'arquivos')->value('id');
        $permissionId = (int) DB::table('permissoes')->where('slug', 'excluir')->value('id');
        $adminGroupIds = DB::table('grupos')
            ->whereRaw('TRIM(nome) = ?', ['Administrador'])
            ->pluck('id');

        foreach ($adminGroupIds as $groupId) {
            if ($moduleId > 0 && $permissionId > 0) {
                DB::table('grupo_permissoes')->insertOrIgnore([
                    'grupo_id' => (int) $groupId,
                    'modulo_id' => $moduleId,
                    'permissao_id' => $permissionId,
                ]);
            }
        }

        Cache::flush();
    }

    public function down(): void
    {
        if (! Schema::hasTable('grupo_permissoes') || ! Schema::hasTable('modulos')) {
            return;
        }

        $moduleId = (int) DB::table('modulos')->where('slug', 'arquivos')->value('id');
        $permissionId = (int) DB::table('permissoes')->where('slug', 'excluir')->value('id');
        if ($moduleId > 0 && $permissionId > 0) {
            DB::table('grupo_permissoes')
                ->where('modulo_id', $moduleId)
                ->where('permissao_id', $permissionId)
                ->delete();
        }

        Cache::flush();
    }
};
