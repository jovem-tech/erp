<?php

namespace App\Services\Auth;

use App\Models\Group;
use App\Models\GroupPermission;
use App\Models\Module;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class RbacAuthorizationService
{
    /**
     * @var array<int, string>
     */
    private const DEFAULT_MODULES = [
        'dashboard',
        'clientes',
        'fornecedores',
        'funcionarios',
        'usuarios',
        'grupos',
        'equipamentos',
        'os',
        'estoque',
        'financeiro',
        'relatorios',
        'configuracoes',
        'defeitos',
        'servicos',
        'vendas',
        'orcamentos',
        'crm',
        'atendimento_whatsapp',
        'precificacao',
        'anotacoes_sistema',
        'anotacoes_assistencia',
        'conhecimento',
    ];

    /**
     * @var array<int, string>
     */
    private const DEFAULT_PERMISSIONS = [
        'visualizar',
        'criar',
        'editar',
        'excluir',
        'exportar',
        'importar',
        'encerrar',
    ];

    /**
     * @return array<string, mixed>
     */
    public function resolveForUser(User $user): array
    {
        return Cache::remember(
            $this->cacheKey((int) $user->id),
            now()->addMinutes(5),
            fn (): array => $this->buildResolvedPayload($user)
        );
    }

    public function allows(User $user, string $module, string $action): bool
    {
        $payload = $this->resolveForUser($user);

        if ((bool) ($payload['fallback_admin'] ?? false)) {
            return true;
        }

        $allowedActions = $payload['permissions'][$module] ?? [];

        return in_array($action, $allowedActions, true);
    }

    /**
     * @return array<int, string>
     */
    public function abilities(): array
    {
        $modules = $this->availableModuleSlugs();
        $permissions = $this->availablePermissionSlugs();
        $abilities = [];

        foreach ($modules as $module) {
            foreach ($permissions as $permission) {
                $abilities[] = $module . ':' . $permission;
            }
        }

        sort($abilities);

        return array_values(array_unique($abilities));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function permissionsForGroup(int $groupId): array
    {
        if (! $this->rbacTablesAvailable()) {
            return [];
        }

        $permissions = [];

        $rows = GroupPermission::query()
            ->with(['module', 'permission'])
            ->where('grupo_id', $groupId)
            ->get();

        foreach ($rows as $row) {
            $moduleSlug = trim((string) ($row->module?->slug ?? ''));
            $permissionSlug = trim((string) ($row->permission?->slug ?? ''));

            if ($moduleSlug === '' || $permissionSlug === '') {
                continue;
            }

            $permissions[$moduleSlug][] = $permissionSlug;
        }

        foreach ($permissions as $moduleSlug => $actions) {
            sort($actions);
            $permissions[$moduleSlug] = array_values(array_unique($actions));
        }

        ksort($permissions);

        return $permissions;
    }

    /**
     * @param array<string, array<int, string>> $permissionsByModule
     * @return array<string, array<int, string>>
     */
    public function syncGroupPermissions(Group $group, array $permissionsByModule): array
    {
        if (! $this->rbacTablesAvailable()) {
            return [];
        }

        $moduleMap = Module::query()->pluck('id', 'slug')->all();
        $permissionMap = Permission::query()->pluck('id', 'slug')->all();
        $rows = [];

        foreach ($permissionsByModule as $moduleSlug => $actions) {
            $moduleId = (int) ($moduleMap[$moduleSlug] ?? 0);
            if ($moduleId <= 0) {
                throw ValidationException::withMessages([
                    'permissions' => ['O módulo "' . $moduleSlug . '" não é válido.'],
                ]);
            }

            foreach ($actions as $actionSlug) {
                $permissionId = (int) ($permissionMap[$actionSlug] ?? 0);
                if ($permissionId <= 0) {
                    throw ValidationException::withMessages([
                        'permissions' => ['A permissão "' . $actionSlug . '" não é válida para o módulo "' . $moduleSlug . '".'],
                    ]);
                }

                $rows[] = [
                    'grupo_id' => (int) $group->id,
                    'modulo_id' => $moduleId,
                    'permissao_id' => $permissionId,
                ];
            }
        }

        GroupPermission::query()
            ->where('grupo_id', (int) $group->id)
            ->delete();

        if ($rows !== []) {
            GroupPermission::query()->insert($rows);
        }

        $this->forgetUsersByGroup((int) $group->id);

        return $this->permissionsForGroup((int) $group->id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeModulesCatalog(): array
    {
        if (! $this->rbacTablesAvailable()) {
            return array_map(static fn (string $slug): array => [
                'id' => 0,
                'nome' => ucfirst(str_replace('_', ' ', $slug)),
                'slug' => $slug,
                'icone' => null,
                'ordem_menu' => 0,
                'ativo' => true,
            ], self::DEFAULT_MODULES);
        }

        return Module::query()
            ->where('ativo', 1)
            ->orderBy('ordem_menu')
            ->orderBy('slug')
            ->get()
            ->map(static fn (Module $module): array => [
                'id' => (int) $module->id,
                'nome' => (string) ($module->nome ?? ''),
                'slug' => (string) ($module->slug ?? ''),
                'icone' => (string) ($module->icone ?? ''),
                'ordem_menu' => (int) ($module->ordem_menu ?? 0),
                'ativo' => (bool) ($module->ativo ?? false),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function permissionsCatalog(): array
    {
        if (! $this->rbacTablesAvailable()) {
            return array_map(static fn (string $slug): array => [
                'id' => 0,
                'nome' => ucfirst($slug),
                'slug' => $slug,
            ], self::DEFAULT_PERMISSIONS);
        }

        return Permission::query()
            ->orderBy('id')
            ->get()
            ->map(static fn (Permission $permission): array => [
                'id' => (int) $permission->id,
                'nome' => (string) ($permission->nome ?? ''),
                'slug' => (string) ($permission->slug ?? ''),
            ])
            ->values()
            ->all();
    }

    public function forgetUser(int $userId): void
    {
        Cache::forget($this->cacheKey($userId));
    }

    /**
     * @param array<int, int> $userIds
     */
    public function forgetUsers(array $userIds): void
    {
        foreach (array_unique($userIds) as $userId) {
            $this->forgetUser((int) $userId);
        }
    }

    public function forgetUsersByGroup(int $groupId): void
    {
        if (! Schema::hasTable('usuarios')) {
            return;
        }

        $userIds = User::query()
            ->where('grupo_id', $groupId)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->forgetUsers($userIds);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function formatGroup(?Group $group): ?array
    {
        if (! $group instanceof Group) {
            return null;
        }

        return [
            'id' => (int) $group->id,
            'nome' => (string) ($group->nome ?? ''),
            'descricao' => (string) ($group->descricao ?? ''),
            'sistema' => (bool) ($group->sistema ?? false),
        ];
    }

    private function cacheKey(int $userId): string
    {
        return 'rbac_user_' . $userId;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResolvedPayload(User $user): array
    {
        $group = $user->group;
        $permissions = $group instanceof Group
            ? $this->permissionsForGroup((int) $group->id)
            : [];

        $fallbackAdmin = false;

        if ($permissions === [] && $this->shouldUseLegacyAdminFallback($user)) {
            $fallbackAdmin = true;

            Log::warning('[RBAC] Fallback legado perfil=admin acionado.', [
                'user_id' => (int) $user->id,
                'email' => (string) ($user->email ?? ''),
            ]);

            $allPermissions = $this->availablePermissionSlugs();
            foreach ($this->availableModuleSlugs() as $moduleSlug) {
                $permissions[$moduleSlug] = $allPermissions;
            }
        }

        $modules = array_keys($permissions);
        sort($modules);

        return [
            'group' => $this->formatGroup($group),
            'modules' => $modules,
            'permissions' => $permissions,
            'fallback_admin' => $fallbackAdmin,
        ];
    }

    private function shouldUseLegacyAdminFallback(User $user): bool
    {
        return (bool) config('services.rbac.legacy_admin_fallback', false)
            && (int) ($user->grupo_id ?? 0) <= 0
            && mb_strtolower(trim((string) ($user->perfil ?? ''))) === 'admin';
    }

    /**
     * @return array<int, string>
     */
    private function availableModuleSlugs(): array
    {
        $slugs = self::DEFAULT_MODULES;

        if ($this->rbacTablesAvailable()) {
            $databaseSlugs = Module::query()
                ->where('ativo', 1)
                ->pluck('slug')
                ->map(static fn ($slug): string => trim((string) $slug))
                ->filter()
                ->values()
                ->all();

            $slugs = array_merge($slugs, $databaseSlugs);
        }

        sort($slugs);

        return array_values(array_unique($slugs));
    }

    /**
     * @return array<int, string>
     */
    private function availablePermissionSlugs(): array
    {
        $slugs = self::DEFAULT_PERMISSIONS;

        if ($this->rbacTablesAvailable()) {
            $databaseSlugs = Permission::query()
                ->pluck('slug')
                ->map(static fn ($slug): string => trim((string) $slug))
                ->filter()
                ->values()
                ->all();

            $slugs = array_merge($slugs, $databaseSlugs);
        }

        sort($slugs);

        return array_values(array_unique($slugs));
    }

    private function rbacTablesAvailable(): bool
    {
        try {
            return Schema::hasTable('grupos')
                && Schema::hasTable('modulos')
                && Schema::hasTable('permissoes')
                && Schema::hasTable('grupo_permissoes');
        } catch (Throwable) {
            return false;
        }
    }
}
