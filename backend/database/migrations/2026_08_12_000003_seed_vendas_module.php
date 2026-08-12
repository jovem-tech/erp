<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo RBAC "Vendas" + categoria/DRE de balcão — specs/027-vendas-balcao-pdv/spec.md.
 *
 * O slug `vendas` já existe em RbacAuthorizationService::DEFAULT_MODULES e há
 * uma linha órfã em `modulos` (id=15, ordem_menu=95) que nunca teve código.
 * O updateOrInsert por slug ADOTA essa linha em vez de duplicar, e reposiciona
 * o módulo logo após "Ordens de Serviço" — ele é operacional, não acessório.
 */
return new class extends Migration
{
    private const MODULE_SLUG = 'vendas';

    private const CATEGORIA = 'Venda de balcão';

    public function up(): void
    {
        $this->seedRbacModule();
        $this->seedFinanceCategory();

        // O RBAC cacheia as permissões por usuário por 5 minutos.
        Cache::flush();
    }

    public function down(): void
    {
        if (Schema::hasTable('financeiro_categorias')) {
            DB::table('financeiro_categorias')->where('nome', self::CATEGORIA)->delete();
        }

        if (Schema::hasTable('financeiro_dre_subgrupos')) {
            DB::table('financeiro_dre_subgrupos')->where('nome', self::CATEGORIA)->delete();
        }

        if (Schema::hasTable('modulos') && Schema::hasTable('grupo_permissoes')) {
            DB::transaction(function (): void {
                $moduleId = (int) DB::table('modulos')->where('slug', self::MODULE_SLUG)->value('id');

                if ($moduleId <= 0) {
                    return;
                }

                DB::table('grupo_permissoes')->where('modulo_id', $moduleId)->delete();

                // A linha do módulo é preservada: ela já existia antes desta migration.
                DB::table('modulos')->where('id', $moduleId)->update(['ordem_menu' => 95]);
            });
        }

        Cache::flush();
    }

    private function seedRbacModule(): void
    {
        if (! Schema::hasTable('modulos')
            || ! Schema::hasTable('permissoes')
            || ! Schema::hasTable('grupo_permissoes')
        ) {
            return;
        }

        DB::transaction(function (): void {
            DB::table('modulos')->updateOrInsert(
                ['slug' => self::MODULE_SLUG],
                [
                    'nome' => 'Vendas',
                    'icone' => 'bi-cart-check',
                    'ordem_menu' => 15,
                    'ativo' => 1,
                ]
            );

            $salesModuleId = (int) DB::table('modulos')->where('slug', self::MODULE_SLUG)->value('id');
            $ordersModuleId = (int) DB::table('modulos')->where('slug', 'os')->value('id');
            $financeModuleId = (int) DB::table('modulos')->where('slug', 'financeiro')->value('id');

            if ($salesModuleId <= 0 || $ordersModuleId <= 0) {
                return;
            }

            $permissionIds = DB::table('permissoes')
                ->whereIn('slug', ['visualizar', 'criar', 'editar', 'excluir'])
                ->pluck('id', 'slug')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $view = (int) ($permissionIds['visualizar'] ?? 0);
            $create = (int) ($permissionIds['criar'] ?? 0);
            $edit = (int) ($permissionIds['editar'] ?? 0);
            $delete = (int) ($permissionIds['excluir'] ?? 0);

            if ($view <= 0 || $create <= 0 || $edit <= 0 || $delete <= 0) {
                return;
            }

            $rows = [];

            // Quem já atende no balcão (OS) passa a poder vender.
            foreach ($this->groupsWith($ordersModuleId, $view) as $groupId) {
                $rows[] = $this->permissionRow($groupId, $salesModuleId, $view);
            }

            foreach ($this->groupsWith($ordersModuleId, $create) as $groupId) {
                $rows[] = $this->permissionRow($groupId, $salesModuleId, $create);
            }

            foreach ($this->groupsWith($ordersModuleId, $edit) as $groupId) {
                $rows[] = $this->permissionRow($groupId, $salesModuleId, $edit);
            }

            // Cancelar venda estorna estoque e dinheiro: herda de quem edita o financeiro.
            if ($financeModuleId > 0) {
                foreach ($this->groupsWith($financeModuleId, $edit) as $groupId) {
                    $rows[] = $this->permissionRow($groupId, $salesModuleId, $delete);
                }
            }

            if ($rows !== []) {
                DB::table('grupo_permissoes')->insertOrIgnore($rows);
            }
        });
    }

    private function seedFinanceCategory(): void
    {
        if (! Schema::hasTable('financeiro_dre_grupos')
            || ! Schema::hasTable('financeiro_dre_subgrupos')
            || ! Schema::hasTable('financeiro_categorias')
        ) {
            return;
        }

        $revenueGroupId = (int) DB::table('financeiro_dre_grupos')
            ->where('nome', 'Receita Operacional')
            ->value('id');

        if ($revenueGroupId <= 0) {
            return;
        }

        $now = now();

        // Subgrupo próprio: a categoria existente "Venda de peças" aponta para
        // "Serviços e peças de OS", que é semanticamente errado para balcão.
        $subgroupId = (int) DB::table('financeiro_dre_subgrupos')
            ->where('grupo_id', $revenueGroupId)
            ->where('nome', self::CATEGORIA)
            ->value('id');

        if ($subgroupId <= 0) {
            $subgroupId = (int) DB::table('financeiro_dre_subgrupos')->insertGetId([
                'grupo_id' => $revenueGroupId,
                'nome' => self::CATEGORIA,
                'ordem_exibicao' => 20,
                'ativo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $categoryExists = DB::table('financeiro_categorias')
            ->where('nome', self::CATEGORIA)
            ->exists();

        if ($categoryExists) {
            return;
        }

        DB::table('financeiro_categorias')->insert([
            'nome' => self::CATEGORIA,
            'tipo' => 'receber',
            'dre_grupo_id' => $revenueGroupId,
            'dre_subgrupo_id' => $subgroupId,
            'impacta_dre_padrao' => true,
            'impacta_fluxo_caixa_padrao' => true,
            'dre_fixo_mensal_padrao' => false,
            'ordem_exibicao' => 25,
            'ativo' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function groupsWith(int $moduleId, int $permissionId): array
    {
        return DB::table('grupo_permissoes')
            ->where('modulo_id', $moduleId)
            ->where('permissao_id', $permissionId)
            ->pluck('grupo_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return array{grupo_id: int, modulo_id: int, permissao_id: int}
     */
    private function permissionRow(int $groupId, int $moduleId, int $permissionId): array
    {
        return [
            'grupo_id' => $groupId,
            'modulo_id' => $moduleId,
            'permissao_id' => $permissionId,
        ];
    }
};
