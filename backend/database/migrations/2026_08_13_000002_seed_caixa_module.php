<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo RBAC "Caixa" — specs/028-caixa-sessoes.
 *
 * DELIBERADAMENTE não cria a conta de caixa físico. Enquanto NENHUMA conta
 * financeira ativa existe, FinanceiroContaService::resolveAccountId() devolve
 * null e o sistema inteiro opera sem rastreio de conta; assim que a primeira
 * conta aparece, ele passa a EXIGIR conta em toda baixa — de OS, de venda, de
 * lançamento avulso. Criar a conta aqui ligaria esse modo silenciosamente, numa
 * migration, para instalações que sequer vão usar caixa.
 *
 * A conta é criada na primeira abertura explícita de caixa
 * (CaixaSessionService::resolveOrCreateCashAccount), que é uma decisão visível
 * do usuário — e é lá que o padrão de dinheiro também é configurado.
 */
return new class extends Migration
{
    private const MODULE_SLUG = 'caixa';

    public function up(): void
    {
        $this->seedRbacModule();

        Cache::flush();
    }

    public function down(): void
    {
        if (Schema::hasTable('modulos') && Schema::hasTable('grupo_permissoes')) {
            DB::transaction(function (): void {
                $moduleId = (int) DB::table('modulos')->where('slug', self::MODULE_SLUG)->value('id');

                if ($moduleId <= 0) {
                    return;
                }

                DB::table('grupo_permissoes')->where('modulo_id', $moduleId)->delete();
                DB::table('modulos')->where('id', $moduleId)->delete();
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
                    'nome' => 'Caixa',
                    'icone' => 'bi-cash-stack',
                    'ordem_menu' => 16,
                    'ativo' => 1,
                ]
            );

            $cashModuleId = (int) DB::table('modulos')->where('slug', self::MODULE_SLUG)->value('id');
            $salesModuleId = (int) DB::table('modulos')->where('slug', 'vendas')->value('id');
            $financeModuleId = (int) DB::table('modulos')->where('slug', 'financeiro')->value('id');

            if ($cashModuleId <= 0 || $salesModuleId <= 0) {
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

            // Quem vende opera o caixa: abrir, sangrar e fechar.
            foreach ($this->groupsWith($salesModuleId, $view) as $groupId) {
                $rows[] = $this->permissionRow($groupId, $cashModuleId, $view);
            }

            foreach ($this->groupsWith($salesModuleId, $create) as $groupId) {
                $rows[] = $this->permissionRow($groupId, $cashModuleId, $create);
                $rows[] = $this->permissionRow($groupId, $cashModuleId, $edit);
            }

            // Reabrir sessão fechada mexe em conferência já assinada.
            if ($financeModuleId > 0) {
                foreach ($this->groupsWith($financeModuleId, $edit) as $groupId) {
                    $rows[] = $this->permissionRow($groupId, $cashModuleId, $delete);
                }
            }

            if ($rows !== []) {
                DB::table('grupo_permissoes')->insertOrIgnore($rows);
            }
        });
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
