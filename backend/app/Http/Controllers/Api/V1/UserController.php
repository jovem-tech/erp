<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreUserRequest;
use App\Http\Requests\Api\V1\UpdateUserActiveRequest;
use App\Http\Requests\Api\V1\UpdateUserRequest;
use App\Models\User;
use App\Services\Auth\RbacAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends BaseApiController
{
    public function __construct(
        private readonly RbacAuthorizationService $rbacAuthorizationService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('usuarios:visualizar');

        $search = trim((string) $request->query('search', $request->query('q', '')));
        $perPage = max(1, min(50, (int) $request->query('per_page', 15)));
        $active = $request->query('active');

        $query = User::query()->with('group');

        if ($search !== '') {
            $term = '%' . mb_strtolower($search) . '%';
            $query->where(static function ($builder) use ($term): void {
                $builder
                    ->whereRaw('LOWER(COALESCE(nome, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$term]);
            });
        }

        if ($active !== null && $active !== '') {
            $query->where('ativo', filter_var($active, FILTER_VALIDATE_BOOL));
        }

        $paginator = $query
            ->orderBy('nome')
            ->paginate($perPage)
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (User $user): array => $this->mapUser($user))
        );

        return $this->success(
            ['users' => $paginator->items()],
            meta: $this->paginationMeta($paginator),
            request: $request
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('usuarios:criar');

        $validated = $request->validated();

        $user = User::query()->create([
            'nome' => trim((string) $validated['nome']),
            'email' => mb_strtolower(trim((string) $validated['email'])),
            'senha' => Hash::make((string) $validated['password']),
            'telefone' => isset($validated['telefone']) ? trim((string) $validated['telefone']) : null,
            'perfil' => trim((string) $validated['perfil']),
            'grupo_id' => isset($validated['grupo_id']) ? (int) $validated['grupo_id'] : null,
            'foto' => isset($validated['foto']) ? trim((string) $validated['foto']) : null,
            'ativo' => array_key_exists('ativo', $validated) ? (bool) $validated['ativo'] : true,
        ]);

        $user->load('group');

        return $this->success(
            ['user' => $this->mapUser($user)],
            201,
            request: $request
        );
    }

    public function update(UpdateUserRequest $request, int $user): JsonResponse
    {
        $this->authorize('usuarios:editar');

        $userModel = User::query()->find($user);
        if (! $userModel instanceof User) {
            return $this->error(
                'Usuário não encontrado.',
                404,
                'USER_NOT_FOUND',
                null,
                request: $request
            );
        }

        $validated = $request->validated();
        $originalGroupId = (int) ($userModel->grupo_id ?? 0);

        $payload = [];
        foreach (['nome', 'email', 'telefone', 'perfil', 'foto'] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = trim((string) $validated[$field]) !== '' ? trim((string) $validated[$field]) : null;
            }
        }
        if (array_key_exists('email', $payload)) {
            $payload['email'] = mb_strtolower((string) $payload['email']);
        }
        if (array_key_exists('grupo_id', $validated)) {
            $payload['grupo_id'] = $validated['grupo_id'] !== null ? (int) $validated['grupo_id'] : null;
        }
        if (array_key_exists('ativo', $validated)) {
            $payload['ativo'] = (bool) $validated['ativo'];
        }
        if (! empty($validated['password'])) {
            $payload['senha'] = Hash::make((string) $validated['password']);
        }

        $userModel->forceFill($payload)->save();

        $this->rbacAuthorizationService->forgetUser((int) $userModel->id);
        if ($originalGroupId > 0 && $originalGroupId !== (int) ($userModel->grupo_id ?? 0)) {
            $this->rbacAuthorizationService->forgetUsersByGroup($originalGroupId);
        }

        $userModel->refresh()->load('group');

        return $this->success(
            ['user' => $this->mapUser($userModel)],
            request: $request
        );
    }

    public function updateActive(UpdateUserActiveRequest $request, int $user): JsonResponse
    {
        $this->authorize('usuarios:editar');

        $userModel = User::query()->find($user);
        if (! $userModel instanceof User) {
            return $this->error(
                'Usuário não encontrado.',
                404,
                'USER_NOT_FOUND',
                null,
                request: $request
            );
        }

        $userModel->forceFill([
            'ativo' => (bool) $request->validated()['active'],
        ])->save();

        $this->rbacAuthorizationService->forgetUser((int) $userModel->id);
        $userModel->refresh()->load('group');

        return $this->success(
            ['user' => $this->mapUser($userModel)],
            request: $request
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mapUser(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'nome' => (string) ($user->nome ?? ''),
            'email' => (string) ($user->email ?? ''),
            'telefone' => (string) ($user->telefone ?? ''),
            'perfil' => (string) ($user->perfil ?? ''),
            'grupo_id' => (int) ($user->grupo_id ?? 0),
            'group' => $this->rbacAuthorizationService->formatGroup($user->group),
            'foto' => (string) ($user->foto ?? ''),
            'ativo' => (bool) ($user->ativo ?? false),
            'ultimo_acesso' => $user->ultimo_acesso?->toIso8601String(),
        ];
    }
}
