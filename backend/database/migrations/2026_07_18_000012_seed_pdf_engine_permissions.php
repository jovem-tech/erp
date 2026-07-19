<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permissões do motor central de templates PDF: novos slugs `publicar` e
 * `restaurar` no catálogo de permissões, concedidos ao Administrador no
 * módulo `conhecimento` (o RBAC lê os slugs dinamicamente da tabela).
 */
return new class extends Migration
{
    private const NEW_PERMISSIONS = [
        'publicar' => 'Publicar',
        'restaurar' => 'Restaurar',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('modulos')
            || ! Schema::hasTable('grupos')
            || ! Schema::hasTable('permissoes')
            || ! Schema::hasTable('grupo_permissoes')
        ) {
            return;
        }

        foreach (self::NEW_PERMISSIONS as $slug => $nome) {
            DB::table('permissoes')->updateOrInsert(['slug' => $slug], ['nome' => $nome]);
        }

        $moduleId = (int) DB::table('modulos')->where('slug', 'conhecimento')->value('id');
        if ($moduleId <= 0) {
            return;
        }

        $permissionIds = DB::table('permissoes')
            ->whereIn('slug', array_keys(self::NEW_PERMISSIONS))
            ->pluck('id', 'slug')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $groups = DB::table('grupos')
            ->whereIn('nome', ['Administrador'])
            ->get(['id']);

        $rows = [];
        foreach ($groups as $group) {
            foreach ($permissionIds as $permissionId) {
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
        if (! Schema::hasTable('permissoes') || ! Schema::hasTable('grupo_permissoes')) {
            return;
        }

        $permissionIds = DB::table('permissoes')
            ->whereIn('slug', array_keys(self::NEW_PERMISSIONS))
            ->pluck('id')
            ->all();

        if ($permissionIds !== []) {
            DB::table('grupo_permissoes')->whereIn('permissao_id', $permissionIds)->delete();
            DB::table('permissoes')->whereIn('id', $permissionIds)->delete();
        }

        Cache::flush();
    }
};
