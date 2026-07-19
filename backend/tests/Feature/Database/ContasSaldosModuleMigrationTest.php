<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class ContasSaldosModuleMigrationTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
    }

    public function test_migration_registers_module_and_preserves_existing_finance_access(): void
    {
        DB::table('modulos')->where('slug', 'contas_saldos')->delete();
        $this->grantGroupPermissions(3, [
            'financeiro' => ['visualizar'],
        ]);
        $this->grantGroupPermissions(4, [
            'financeiro' => ['visualizar', 'editar'],
        ]);

        $migration = require database_path('migrations/2026_07_18_000002_seed_contas_saldos_module.php');
        $migration->up();

        $moduleId = (int) DB::table('modulos')
            ->where('slug', 'contas_saldos')
            ->value('id');

        $this->assertGreaterThan(0, $moduleId);
        $this->assertSame(['visualizar'], $this->permissionSlugsForGroup(3, $moduleId));
        $this->assertSame(['criar', 'editar', 'visualizar'], $this->permissionSlugsForGroup(4, $moduleId));
    }

    /** @return array<int, string> */
    private function permissionSlugsForGroup(int $groupId, int $moduleId): array
    {
        return DB::table('grupo_permissoes as gp')
            ->join('permissoes as p', 'p.id', '=', 'gp.permissao_id')
            ->where('gp.grupo_id', $groupId)
            ->where('gp.modulo_id', $moduleId)
            ->orderBy('p.slug')
            ->pluck('p.slug')
            ->map(static fn ($slug): string => (string) $slug)
            ->all();
    }
}
