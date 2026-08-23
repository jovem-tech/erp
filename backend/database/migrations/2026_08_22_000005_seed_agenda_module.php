<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modulo RBAC da Agenda.
 *
 * Regra de concessao: quem ja enxerga o Dashboard passa a enxergar a Agenda
 * (visualizar/criar/editar). A agenda e a segunda tela da rotina diaria - nao
 * faz sentido nascer invisivel para quem opera o painel todo dia e depender de
 * um admin lembrar de liberar grupo por grupo.
 *
 * "ver_todos" e "excluir" ficam so nos grupos de administracao: agenda aqui e
 * pessoal por padrao (cada um ve os proprios compromissos e os sem dono), e
 * apagar um compromisso apaga o rastro de uma obrigacao.
 */
return new class extends Migration
{
    private const MODULE_SLUG = 'agenda';

    /** @var array<string, string> */
    private const PERMISSIONS = [
        'visualizar' => 'Visualizar',
        'criar' => 'Criar',
        'editar' => 'Editar',
        'excluir' => 'Excluir',
        'ver_todos' => 'Ver de todos os responsaveis',
    ];

    /** @var array<int, string> */
    private const INHERITED_FROM_DASHBOARD = ['visualizar', 'criar', 'editar'];

    public function up(): void
    {
        if (! Schema::hasTable('modulos')
            || ! Schema::hasTable('grupos')
            || ! Schema::hasTable('permissoes')
            || ! Schema::hasTable('grupo_permissoes')) {
            return;
        }

        DB::transaction(function (): void {
            DB::table('modulos')->updateOrInsert(
                ['slug' => self::MODULE_SLUG],
                [
                    'nome' => 'Agenda',
                    'icone' => 'bi-calendar-week',
                    // Logo depois do dashboard: e onde ela aparece na sidebar.
                    'ordem_menu' => 5,
                    'ativo' => 1,
                ]
            );

            foreach (self::PERMISSIONS as $slug => $name) {
                DB::table('permissoes')->updateOrInsert(['slug' => $slug], ['nome' => $name]);
            }

            $moduleId = (int) DB::table('modulos')->where('slug', self::MODULE_SLUG)->value('id');
            if ($moduleId <= 0) {
                return;
            }

            $permissionIds = DB::table('permissoes')
                ->whereIn('slug', array_keys(self::PERMISSIONS))
                ->pluck('id', 'slug')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $rows = [];

            // 1) Herda do dashboard.
            $dashboardModuleId = (int) DB::table('modulos')->where('slug', 'dashboard')->value('id');
            $viewPermissionId = (int) ($permissionIds['visualizar'] ?? 0);

            if ($dashboardModuleId > 0 && $viewPermissionId > 0) {
                $groupsWithDashboard = DB::table('grupo_permissoes')
                    ->where('modulo_id', $dashboardModuleId)
                    ->where('permissao_id', $viewPermissionId)
                    ->pluck('grupo_id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();

                foreach ($groupsWithDashboard as $groupId) {
                    foreach (self::INHERITED_FROM_DASHBOARD as $slug) {
                        $permissionId = (int) ($permissionIds[$slug] ?? 0);
                        if ($permissionId > 0) {
                            $rows[] = $this->row($groupId, $moduleId, $permissionId);
                        }
                    }
                }
            }

            // 2) Administracao total recebe o modulo inteiro.
            $adminGroupIds = DB::table('grupos')
                ->whereIn(DB::raw('LOWER(TRIM(nome))'), ['administrador', 'super administrador'])
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            foreach ($adminGroupIds as $groupId) {
                foreach ($permissionIds as $permissionId) {
                    $rows[] = $this->row($groupId, $moduleId, $permissionId);
                }
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

        $moduleId = (int) DB::table('modulos')->where('slug', self::MODULE_SLUG)->value('id');
        if ($moduleId > 0) {
            DB::table('grupo_permissoes')->where('modulo_id', $moduleId)->delete();
            DB::table('modulos')->where('id', $moduleId)->delete();
        }

        Cache::flush();
    }

    /** @return array{grupo_id: int, modulo_id: int, permissao_id: int} */
    private function row(int $groupId, int $moduleId, int $permissionId): array
    {
        return [
            'grupo_id' => $groupId,
            'modulo_id' => $moduleId,
            'permissao_id' => $permissionId,
        ];
    }
};
