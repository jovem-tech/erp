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
            || ! Schema::hasTable('permissoes')
            || ! Schema::hasTable('grupo_permissoes')
        ) {
            return;
        }

        DB::transaction(function (): void {
            DB::table('modulos')->updateOrInsert(
                ['slug' => 'contas_saldos'],
                [
                    'nome' => 'Contas e Saldos',
                    'icone' => 'bi-wallet2',
                    'ordem_menu' => 47,
                    'ativo' => 1,
                ]
            );

            $accountsModuleId = (int) DB::table('modulos')
                ->where('slug', 'contas_saldos')
                ->value('id');
            $financeModuleId = (int) DB::table('modulos')
                ->where('slug', 'financeiro')
                ->value('id');

            if ($accountsModuleId <= 0 || $financeModuleId <= 0) {
                return;
            }

            $permissionIds = DB::table('permissoes')
                ->whereIn('slug', ['visualizar', 'criar', 'editar'])
                ->pluck('id', 'slug')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $viewPermissionId = (int) ($permissionIds['visualizar'] ?? 0);
            $createPermissionId = (int) ($permissionIds['criar'] ?? 0);
            $editPermissionId = (int) ($permissionIds['editar'] ?? 0);

            if ($viewPermissionId <= 0 || $createPermissionId <= 0 || $editPermissionId <= 0) {
                return;
            }

            $groupsWithView = DB::table('grupo_permissoes')
                ->where('modulo_id', $financeModuleId)
                ->where('permissao_id', $viewPermissionId)
                ->pluck('grupo_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $groupsWithEdit = DB::table('grupo_permissoes')
                ->where('modulo_id', $financeModuleId)
                ->where('permissao_id', $editPermissionId)
                ->pluck('grupo_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $rows = [];
            foreach ($groupsWithView as $groupId) {
                $rows[] = $this->permissionRow($groupId, $accountsModuleId, $viewPermissionId);
            }

            foreach ($groupsWithEdit as $groupId) {
                $rows[] = $this->permissionRow($groupId, $accountsModuleId, $createPermissionId);
                $rows[] = $this->permissionRow($groupId, $accountsModuleId, $editPermissionId);
            }

            if ($rows !== []) {
                DB::table('grupo_permissoes')->insertOrIgnore($rows);
            }
        });

        Cache::flush();
    }

    public function down(): void
    {
        if (! Schema::hasTable('modulos') || ! Schema::hasTable('grupo_permissoes')) {
            return;
        }

        DB::transaction(function (): void {
            $moduleId = (int) DB::table('modulos')
                ->where('slug', 'contas_saldos')
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
        });

        Cache::flush();
    }

    /** @return array{grupo_id: int, modulo_id: int, permissao_id: int} */
    private function permissionRow(int $groupId, int $moduleId, int $permissionId): array
    {
        return [
            'grupo_id' => $groupId,
            'modulo_id' => $moduleId,
            'permissao_id' => $permissionId,
        ];
    }
};
