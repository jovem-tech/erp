<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'listar' => 'Listar',
        'metadados' => 'Ver metadados',
        'baixar' => 'Baixar',
        'excluir' => 'Excluir',
        'quarentenar' => 'Quarentenar',
        'restaurar' => 'Restaurar',
        'administrar' => 'Administrar',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('modulos')
            || ! Schema::hasTable('grupos')
            || ! Schema::hasTable('permissoes')
            || ! Schema::hasTable('grupo_permissoes')) {
            return;
        }

        DB::table('modulos')->updateOrInsert(
            ['slug' => 'arquivos'],
            [
                'nome' => 'Arquivos',
                'icone' => 'bi-folder2-open',
                'ordem_menu' => 78,
                'ativo' => 1,
            ]
        );

        foreach (self::PERMISSIONS as $slug => $name) {
            DB::table('permissoes')->updateOrInsert(['slug' => $slug], ['nome' => $name]);
        }

        $moduleId = (int) DB::table('modulos')->where('slug', 'arquivos')->value('id');
        $permissionIds = DB::table('permissoes')
            ->whereIn('slug', array_keys(self::PERMISSIONS))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $adminGroupIds = DB::table('grupos')
            ->whereRaw('TRIM(nome) = ?', ['Administrador'])
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $rows = [];
        foreach ($adminGroupIds as $groupId) {
            foreach ($permissionIds as $permissionId) {
                $rows[] = [
                    'grupo_id' => $groupId,
                    'modulo_id' => $moduleId,
                    'permissao_id' => $permissionId,
                ];
            }
        }

        if ($moduleId > 0 && $rows !== []) {
            DB::table('grupo_permissoes')->insertOrIgnore($rows);
        }

        Cache::flush();
    }

    public function down(): void
    {
        if (! Schema::hasTable('modulos') || ! Schema::hasTable('grupo_permissoes')) {
            return;
        }

        $moduleId = (int) DB::table('modulos')->where('slug', 'arquivos')->value('id');
        if ($moduleId > 0) {
            DB::table('grupo_permissoes')->where('modulo_id', $moduleId)->delete();
            DB::table('modulos')->where('id', $moduleId)->delete();
        }

        Cache::flush();
    }
};
