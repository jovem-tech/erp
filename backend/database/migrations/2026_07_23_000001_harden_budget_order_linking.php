<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSION_SLUG = 'converter_os';

    private const INDEX_NAME = 'idx_orcamentos_linkable_os';

    public function up(): void
    {
        $this->seedPermission();
        $this->addLookupIndex();
        Cache::flush();
    }

    public function down(): void
    {
        $this->dropLookupIndex();
        $this->removePermission();
        Cache::flush();
    }

    private function seedPermission(): void
    {
        if (! Schema::hasTable('modulos')
            || ! Schema::hasTable('permissoes')
            || ! Schema::hasTable('grupo_permissoes')
        ) {
            return;
        }

        DB::transaction(function (): void {
            DB::table('permissoes')->updateOrInsert(
                ['slug' => self::PERMISSION_SLUG],
                ['nome' => 'Converter orçamento em OS']
            );

            $modules = DB::table('modulos')
                ->whereIn('slug', ['orcamentos', 'os'])
                ->pluck('id', 'slug')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $permissions = DB::table('permissoes')
                ->whereIn('slug', ['visualizar', 'criar', self::PERMISSION_SLUG])
                ->pluck('id', 'slug')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $budgetModuleId = (int) ($modules['orcamentos'] ?? 0);
            $orderModuleId = (int) ($modules['os'] ?? 0);
            $viewPermissionId = (int) ($permissions['visualizar'] ?? 0);
            $createPermissionId = (int) ($permissions['criar'] ?? 0);
            $convertPermissionId = (int) ($permissions[self::PERMISSION_SLUG] ?? 0);

            if ($budgetModuleId <= 0
                || $orderModuleId <= 0
                || $viewPermissionId <= 0
                || $createPermissionId <= 0
                || $convertPermissionId <= 0
            ) {
                return;
            }

            $groupsWithBudgetView = DB::table('grupo_permissoes')
                ->where('modulo_id', $budgetModuleId)
                ->where('permissao_id', $viewPermissionId)
                ->pluck('grupo_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $groupsWithOrderCreate = DB::table('grupo_permissoes')
                ->where('modulo_id', $orderModuleId)
                ->where('permissao_id', $createPermissionId)
                ->pluck('grupo_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $eligibleGroups = array_values(array_intersect($groupsWithBudgetView, $groupsWithOrderCreate));
            $rows = array_map(
                static fn (int $groupId): array => [
                    'grupo_id' => $groupId,
                    'modulo_id' => $budgetModuleId,
                    'permissao_id' => $convertPermissionId,
                ],
                $eligibleGroups
            );

            if ($rows !== []) {
                DB::table('grupo_permissoes')->insertOrIgnore($rows);
            }
        });
    }

    private function removePermission(): void
    {
        if (! Schema::hasTable('modulos')
            || ! Schema::hasTable('permissoes')
            || ! Schema::hasTable('grupo_permissoes')
        ) {
            return;
        }

        DB::transaction(function (): void {
            $moduleId = (int) DB::table('modulos')->where('slug', 'orcamentos')->value('id');
            $permissionId = (int) DB::table('permissoes')->where('slug', self::PERMISSION_SLUG)->value('id');

            if ($permissionId <= 0) {
                return;
            }

            if ($moduleId > 0) {
                DB::table('grupo_permissoes')
                    ->where('modulo_id', $moduleId)
                    ->where('permissao_id', $permissionId)
                    ->delete();
            }

            $stillUsed = DB::table('grupo_permissoes')->where('permissao_id', $permissionId)->exists();
            if (! $stillUsed) {
                DB::table('permissoes')->where('id', $permissionId)->delete();
            }
        });
    }

    private function addLookupIndex(): void
    {
        if (! Schema::hasTable('orcamentos') || $this->hasIndex(self::INDEX_NAME)) {
            return;
        }

        Schema::table('orcamentos', function (Blueprint $table): void {
            $table->index(
                ['status', 'tipo_orcamento', 'os_id', 'aprovado_em', 'id'],
                self::INDEX_NAME
            );
        });
    }

    private function dropLookupIndex(): void
    {
        if (! Schema::hasTable('orcamentos') || ! $this->hasIndex(self::INDEX_NAME)) {
            return;
        }

        Schema::table('orcamentos', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX_NAME);
        });
    }

    private function hasIndex(string $indexName): bool
    {
        $connection = Schema::getConnection();
        $database = (string) $connection->getDatabaseName();
        $driver = (string) $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = $connection->select("PRAGMA index_list('orcamentos')");

            return collect($indexes)->contains(
                static fn (object $index): bool => (string) ($index->name ?? '') === $indexName
            );
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', 'orcamentos')
            ->where('index_name', $indexName)
            ->exists();
    }
};
