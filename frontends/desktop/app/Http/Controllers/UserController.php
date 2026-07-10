<?php

namespace App\Http\Controllers;

use App\Services\GroupService;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends DesktopController
{
    public function __construct(
        private readonly UserService $userService,
        private readonly GroupService $groupService
    ) {
    }

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'active' => trim((string) $request->query('active', '')),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 15),
        ];

        $result = $this->userService->paginate(array_filter($filters, static fn ($value) => $value !== '' && $value !== 0));

        return view('users.index', [
            'pageTitle' => 'Usuários',
            'users' => $result['items'],
            'groups' => $this->groupService->all(),
            'pagination' => $result['pagination'],
            'filters' => $filters,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nome' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'grupo_id' => ['required', 'integer'],
            'ativo' => ['nullable', 'boolean'],
        ], [], [
            'nome' => 'nome',
            'email' => 'e-mail',
            'password' => 'senha',
            'telefone' => 'telefone',
            'grupo_id' => 'grupo',
        ]);

        $this->userService->create([
            ...$validated,
            'ativo' => $request->boolean('ativo', true),
        ]);

        return redirect()
            ->route('users.index')
            ->with('success', 'Usuário criado com sucesso.');
    }

    public function update(Request $request, int $user): RedirectResponse
    {
        $validated = $request->validate([
            'nome' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['nullable', 'string', 'min:8'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'grupo_id' => ['required', 'integer'],
            'ativo' => ['nullable', 'boolean'],
        ], [], [
            'nome' => 'nome',
            'email' => 'e-mail',
            'password' => 'senha',
            'password_confirmation' => 'confirmação da senha',
            'telefone' => 'telefone',
            'grupo_id' => 'grupo',
        ]);

        $payload = [
            ...$validated,
            'ativo' => $request->boolean('ativo'),
        ];

        if (($validated['password'] ?? '') === '') {
            unset($payload['password']);
            unset($payload['password_confirmation']);
        }

        $this->userService->update($user, $payload);

        return redirect()
            ->route('users.index')
            ->with('success', 'Usuário atualizado com sucesso.');
    }

    public function updateActive(Request $request, int $user): RedirectResponse
    {
        $validated = $request->validate([
            'active' => ['required', 'boolean'],
        ], [], [
            'active' => 'status',
        ]);

        $this->userService->updateActive($user, (bool) $validated['active']);

        return redirect()
            ->route('users.index')
            ->with('success', 'Status do usuário atualizado com sucesso.');
    }
}
