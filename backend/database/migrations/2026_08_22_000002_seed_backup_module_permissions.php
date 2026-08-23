<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Todos os slugs ja existem em RbacAuthorizationService::DEFAULT_PERMISSIONS;
     * o updateOrInsert abaixo apenas garante a presenca deles.
     *
     * "baixar" e separado de "visualizar" de proposito: o pacote carrega todos
     * os segredos e todos os arquivos de clientes, entao ver a lista e levar o
     * arquivo embora sao poderes diferentes.
     *
     * @var array<string, string>
     */
    private const PERMISSIONS = [
        'visualizar' => 'Visualizar',
        'criar' => 'Criar',
        'baixar' => 'Baixar',
        'excluir' => 'Excluir',
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
            ['slug' => 'backups'],
            [
                'nome' => 'Backups',
                'icone' => 'bi-hdd-stack',
                'ordem_menu' => 79,
                'ativo' => 1,
            ]
        );

        foreach (self::PERMISSIONS as $slug => $name) {
            DB::table('permissoes')->updateOrInsert(['slug' => $slug], ['nome' => $name]);
        }

        $moduleId = (int) DB::table('modulos')->where('slug', 'backups')->value('id');
        $permissionIds = DB::table('permissoes')
            ->whereIn('slug', array_keys(self::PERMISSIONS))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        // Os dois grupos de administracao total do sistema. O padrao herdado
        // semeava apenas "Administrador", o que deixava "super administrador"
        // - o grupo supremo, com acesso a tudo - de fora de todo modulo novo.
        $adminGroupIds = DB::table('grupos')
            ->whereIn(DB::raw('LOWER(TRIM(nome))'), ['administrador', 'super administrador'])
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

        $moduleId = (int) DB::table('modulos')->where('slug', 'backups')->value('id');
        if ($moduleId > 0) {
            DB::table('grupo_permissoes')->where('modulo_id', $moduleId)->delete();
            DB::table('modulos')->where('id', $moduleId)->delete();
        }

        Cache::flush();
    }
};
