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
            || ! Schema::hasTable('grupo_permissoes')
        ) {
            return;
        }

        DB::table('modulos')->updateOrInsert(
            ['slug' => 'conhecimento'],
            [
                'nome' => 'Conhecimento',
                'icone' => 'bi-journal-bookmark-fill',
                'ordem_menu' => 75,
                'ativo' => 1,
            ]
        );

        $moduleId = (int) DB::table('modulos')
            ->where('slug', 'conhecimento')
            ->value('id');

        if ($moduleId <= 0) {
            return;
        }

        $permissionIds = DB::table('permissoes')
            ->whereIn('slug', ['visualizar', 'criar', 'editar', 'excluir', 'exportar', 'importar', 'encerrar'])
            ->pluck('id', 'slug')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $groups = DB::table('grupos')
            ->whereIn('nome', ['Administrador', 'Gerente', 'Gerente '])
            ->get(['id', 'nome']);

        $rows = [];
        foreach ($groups as $group) {
            $groupName = trim((string) ($group->nome ?? ''));
            $actions = $groupName === 'Administrador'
                ? ['visualizar', 'criar', 'editar', 'excluir', 'exportar', 'importar', 'encerrar']
                : ['visualizar', 'criar', 'editar', 'exportar'];

            foreach ($actions as $action) {
                $permissionId = (int) ($permissionIds[$action] ?? 0);
                if ($permissionId <= 0) {
                    continue;
                }

                $rows[] = [
                    'grupo_id' => (int) $group->id,
                    'modulo_id' => $moduleId,
                    'permissao_id' => $permissionId,
                ];
            }
        }

        if ($rows !== []) {
            DB::table('grupo_permissoes')->insertOrIgnore($rows);
        }

        Cache::flush();
    }

    public function down(): void
    {
        if (! Schema::hasTable('modulos') || ! Schema::hasTable('grupo_permissoes')) {
            return;
        }

        $moduleId = (int) DB::table('modulos')
            ->where('slug', 'conhecimento')
            ->value('id');

        if ($moduleId <= 0) {
            return;
        }

        DB::table('grupo_permissoes')
            ->where('modulo_id', $moduleId)
            ->delete();

        DB::table('modulos')
            ->where('id', $moduleId)
            ->delete();

        Cache::flush();
    }
};
