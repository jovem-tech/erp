<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreGroupRequest;
use App\Http\Requests\Api\V1\UpdateGroupPermissionsRequest;
use App\Http\Requests\Api\V1\UpdateGroupRequest;
use App\Models\Group;
use App\Services\Auth\RbacAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupController extends BaseApiController
{
    public function __construct(
        private readonly RbacAuthorizationService $rbacAuthorizationService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('grupos:visualizar');

        $groups = Group::query()
            ->withCount('users')
            ->orderBy('id')
            ->get()
            ->map(fn (Group $group): array => $this->mapGroup($group))
            ->values()
            ->all();

        return $this->success(
            ['groups' => $groups],
            request: $request
        );
    }

    public function store(StoreGroupRequest $request): JsonResponse
    {
        $this->authorize('grupos:criar');

        $group = Group::query()->create([
            'nome' => trim((string) $request->validated()['nome']),
            'descricao' => isset($request->validated()['descricao']) ? trim((string) $request->validated()['descricao']) : null,
            'sistema' => false,
            'created_at' => now(),
        ]);

        return $this->success(
            ['group' => $this->mapGroup($group)],
            201,
            request: $request
        );
    }

    public function update(UpdateGroupRequest $request, int $group): JsonResponse
    {
        $this->authorize('grupos:editar');

        $groupModel = Group::query()->find($group);
        if (! $groupModel instanceof Group) {
            return $this->error(
                'Grupo não encontrado.',
                404,
                'GROUP_NOT_FOUND',
                null,
                request: $request
            );
        }

        if ((bool) $groupModel->sistema) {
            return $this->error(
                'Grupos de sistema são imutáveis.',
                403,
                'GROUP_SYSTEM_IMMUTABLE',
                null,
                request: $request
            );
        }

        $groupModel->forceFill([
            'nome' => array_key_exists('nome', $request->validated()) ? trim((string) $request->validated()['nome']) : $groupModel->nome,
            'descricao' => array_key_exists('descricao', $request->validated()) ? trim((string) ($request->validated()['descricao'] ?? '')) ?: null : $groupModel->descricao,
        ])->save();

        $this->rbacAuthorizationService->forgetUsersByGroup((int) $groupModel->id);

        return $this->success(
            ['group' => $this->mapGroup($groupModel->fresh(['users']))],
            request: $request
        );
    }

    public function destroy(Request $request, int $group): JsonResponse
    {
        $this->authorize('grupos:excluir');

        $groupModel = Group::query()
            ->with('users')
            ->find($group);

        if (! $groupModel instanceof Group) {
            return $this->error(
                'Grupo não encontrado.',
                404,
                'GROUP_NOT_FOUND',
                null,
                request: $request
            );
        }

        if ((bool) $groupModel->sistema) {
            return $this->error(
                'Grupos de sistema são imutáveis.',
                403,
                'GROUP_SYSTEM_IMMUTABLE',
                null,
                request: $request
            );
        }

        $affectedUserIds = $groupModel->users->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $groupModel->delete();
        $this->rbacAuthorizationService->forgetUsers($affectedUserIds);

        return $this->success(
            ['deleted' => true],
            request: $request
        );
    }

    public function permissions(Request $request, int $group): JsonResponse
    {
        $this->authorize('grupos:visualizar');

        $groupModel = Group::query()->find($group);
        if (! $groupModel instanceof Group) {
            return $this->error(
                'Grupo não encontrado.',
                404,
                'GROUP_NOT_FOUND',
                null,
                request: $request
            );
        }

        return $this->success(
            [
                'group' => $this->mapGroup($groupModel),
                'permissions' => $this->rbacAuthorizationService->permissionsForGroup((int) $groupModel->id),
            ],
            request: $request
        );
    }

    public function updatePermissions(UpdateGroupPermissionsRequest $request, int $group): JsonResponse
    {
        $this->authorize('grupos:editar');

        $groupModel = Group::query()->find($group);
        if (! $groupModel instanceof Group) {
            return $this->error(
                'Grupo não encontrado.',
                404,
                'GROUP_NOT_FOUND',
                null,
                request: $request
            );
        }

        if ((bool) $groupModel->sistema) {
            return $this->error(
                'Grupos de sistema são imutáveis.',
                403,
                'GROUP_SYSTEM_IMMUTABLE',
                null,
                request: $request
            );
        }

        $permissions = $this->rbacAuthorizationService->syncGroupPermissions(
            $groupModel,
            $request->validated()['permissions']
        );

        return $this->success(
            [
                'group' => $this->mapGroup($groupModel),
                'permissions' => $permissions,
            ],
            request: $request
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mapGroup(Group $group): array
    {
        return [
            'id' => (int) $group->id,
            'nome' => (string) ($group->nome ?? ''),
            'descricao' => (string) ($group->descricao ?? ''),
            'sistema' => (bool) ($group->sistema ?? false),
            'users_count' => (int) ($group->users_count ?? $group->users()->count()),
        ];
    }
}
