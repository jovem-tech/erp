<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreTeamMemberRequest;
use App\Http\Requests\Api\V1\UpdateTeamMemberActiveRequest;
use App\Http\Requests\Api\V1\UpdateTeamMemberRequest;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Auth\RbacAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class TeamMemberController extends BaseApiController
{
    private const LOWERCASE_NAME_CONNECTORS = [
        'da',
        'das',
        'de',
        'di',
        'do',
        'dos',
        'du',
        'e',
    ];

    public function __construct(
        private readonly RbacAuthorizationService $rbacAuthorizationService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('funcionarios:visualizar');

        $search = trim((string) $request->query('search', $request->query('q', '')));
        $perPage = max(1, min(50, (int) $request->query('per_page', 15)));
        $active = $request->query('active');
        $role = trim((string) $request->query('role', ''));
        $assignableOrders = $request->boolean('assignable_orders');
        $includeAvailableUsers = $request->boolean('include_available_users');

        $query = TeamMember::query()->with(['user.group']);

        if ($search !== '') {
            $term = '%' . mb_strtolower($search) . '%';
            $query->where(static function ($builder) use ($term): void {
                $builder
                    ->whereRaw('LOWER(COALESCE(nome, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(telefone, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(cargo, \'\')) LIKE ?', [$term])
                    ->orWhereHas('user', static function ($userQuery) use ($term): void {
                        $userQuery
                            ->whereRaw('LOWER(COALESCE(nome, \'\')) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$term]);
                    });
            });
        }

        if ($active !== null && $active != '') {
            $query->where('ativo', filter_var($active, FILTER_VALIDATE_BOOL));
        }

        if ($role !== '') {
            $query->role($role);
        }

        if ($assignableOrders) {
            $query->assignableOrders();
        }

        $paginator = $query
            ->orderBy('nome')
            ->paginate($perPage)
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (TeamMember $member): array => $this->mapTeamMember($member))
        );

        $data = [
            'team_members' => $paginator->items(),
        ];

        if ($includeAvailableUsers) {
            $data['available_users'] = $this->availableUsers();
        }

        return $this->success(
            $data,
            meta: $this->paginationMeta($paginator),
            request: $request
        );
    }

    public function store(StoreTeamMemberRequest $request): JsonResponse
    {
        $this->authorize('funcionarios:criar');

        $validated = $request->validated();
        $this->assertAtLeastOneRole($validated, request: $request);

        $member = TeamMember::query()->create($this->normalizePayload($validated));
        $member->load(['user.group']);

        return $this->success(
            ['team_member' => $this->mapTeamMember($member)],
            201,
            request: $request
        );
    }

    public function update(UpdateTeamMemberRequest $request, int $member): JsonResponse
    {
        $this->authorize('funcionarios:editar');

        $memberModel = TeamMember::query()->with(['user.group'])->find($member);
        if (! $memberModel instanceof TeamMember) {
            return $this->error(
                'Membro da equipe n?o encontrado.',
                404,
                'TEAM_MEMBER_NOT_FOUND',
                null,
                request: $request
            );
        }

        $validated = $request->validated();
        $this->assertAtLeastOneRole($validated, $memberModel, $request);

        $memberModel->forceFill($this->normalizePayload($validated))->save();
        $memberModel->refresh()->load(['user.group']);

        return $this->success(
            ['team_member' => $this->mapTeamMember($memberModel)],
            request: $request
        );
    }

    public function updateActive(UpdateTeamMemberActiveRequest $request, int $member): JsonResponse
    {
        $this->authorize('funcionarios:editar');

        $memberModel = TeamMember::query()->with(['user.group'])->find($member);
        if (! $memberModel instanceof TeamMember) {
            return $this->error(
                'Membro da equipe n?o encontrado.',
                404,
                'TEAM_MEMBER_NOT_FOUND',
                null,
                request: $request
            );
        }

        $memberModel->forceFill([
            'ativo' => (bool) $request->validated()['active'],
        ])->save();

        $memberModel->refresh()->load(['user.group']);

        return $this->success(
            ['team_member' => $this->mapTeamMember($memberModel)],
            request: $request
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function availableUsers(): array
    {
        return User::query()
            ->with('group')
            ->where('ativo', true)
            ->orderBy('nome')
            ->get()
            ->map(fn (User $user): array => $this->mapAvailableUser($user))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapTeamMember(TeamMember $member): array
    {
        $linkedUser = $member->user;
        $linkedUserIsActive = $linkedUser instanceof User && (bool) ($linkedUser->ativo ?? false);
        $canAssignOrders = (bool) ($member->ativo ?? false)
            && (bool) ($member->atua_tecnico ?? false)
            && $linkedUserIsActive;

        return [
            'id' => (int) $member->id,
            'nome' => (string) ($member->nome ?? ''),
            'email' => (string) ($member->email ?? ''),
            'telefone' => (string) ($member->telefone ?? ''),
            'cargo' => (string) ($member->cargo ?? ''),
            'usuario_id' => $member->usuario_id !== null ? (int) $member->usuario_id : null,
            'linked_user' => $linkedUser instanceof User ? $this->mapAvailableUser($linkedUser) : null,
            'roles' => [
                'tecnico' => (bool) ($member->atua_tecnico ?? false),
                'vendas' => (bool) ($member->atua_vendas ?? false),
                'administrativo' => (bool) ($member->atua_administrativo ?? false),
            ],
            'ativo' => (bool) ($member->ativo ?? false),
            'observacoes' => (string) ($member->observacoes ?? ''),
            'can_assign_orders' => $canAssignOrders,
            'order_technician_user_id' => $canAssignOrders && $linkedUser instanceof User ? (int) $linkedUser->id : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapAvailableUser(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'nome' => (string) ($user->nome ?? ''),
            'email' => (string) ($user->email ?? ''),
            'telefone' => (string) ($user->telefone ?? ''),
            'perfil' => (string) ($user->perfil ?? ''),
            'grupo_id' => (int) ($user->grupo_id ?? 0),
            'group' => $this->rbacAuthorizationService->formatGroup($user->group),
            'ativo' => (bool) ($user->ativo ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        $normalized = [];

        foreach (['nome', 'email', 'telefone', 'cargo', 'observacoes'] as $field) {
            if (array_key_exists($field, $payload)) {
                $value = trim((string) $payload[$field]);
                $normalized[$field] = $value !== '' ? $value : null;
            }
        }

        if (array_key_exists('nome', $normalized) && $normalized['nome'] !== null) {
            $normalized['nome'] = $this->normalizePersonName((string) $normalized['nome']);
        }

        if (array_key_exists('email', $normalized) && $normalized['email'] !== null) {
            $normalized['email'] = mb_strtolower((string) $normalized['email']);
        }

        foreach (['usuario_id', 'atua_tecnico', 'atua_vendas', 'atua_administrativo', 'ativo'] as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $value = $payload[$field];

            if ($field === 'usuario_id') {
                $normalized[$field] = $value !== null && (int) $value > 0 ? (int) $value : null;
                continue;
            }

            $normalized[$field] = (bool) $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertAtLeastOneRole(array $payload, ?TeamMember $existing = null, ?Request $request = null): void
    {
        $roles = [
            'atua_tecnico' => $existing?->atua_tecnico ?? false,
            'atua_vendas' => $existing?->atua_vendas ?? false,
            'atua_administrativo' => $existing?->atua_administrativo ?? false,
        ];

        foreach (array_keys($roles) as $field) {
            if (array_key_exists($field, $payload)) {
                $roles[$field] = (bool) $payload[$field];
            }
        }

        if (in_array(true, $roles, true)) {
            return;
        }

        abort(response()->json([
            'success' => false,
            'message' => 'Informe ao menos uma atua??o para o membro da equipe.',
            'code' => 'TEAM_MEMBER_ROLE_REQUIRED',
            'errors' => [
                'roles' => ['Informe ao menos uma atua??o para o membro da equipe.'],
            ],
        ], 422));
    }

    private function normalizePersonName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '') {
            return '';
        }

        $words = explode(' ', mb_strtolower($name, 'UTF-8'));

        return implode(' ', array_map(
            static function (string $word, int $index): string {
                if ($index > 0 && in_array($word, self::LOWERCASE_NAME_CONNECTORS, true)) {
                    return $word;
                }

                return mb_convert_case($word, MB_CASE_TITLE, 'UTF-8');
            },
            $words,
            array_keys($words)
        ));
    }
}
