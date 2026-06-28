<?php

namespace App\Http\Controllers;

use App\Services\GroupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GroupController extends DesktopController
{
    public function __construct(
        private readonly GroupService $groupService
    ) {
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $groups = $this->groupService->all();

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $groups = array_values(array_filter($groups, function (array $group) use ($needle): bool {
                $haystack = mb_strtolower(
                    trim((string) ($group['nome'] ?? '')) . ' ' . trim((string) ($group['descricao'] ?? ''))
                );

                return str_contains($haystack, $needle);
            }));
        }

        return view('groups.index', [
            'pageTitle' => 'Níveis de Acesso',
            'groups' => $groups,
            'search' => $search,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nome' => ['required', 'string', 'max:80'],
            'descricao' => ['nullable', 'string', 'max:200'],
        ], [], [
            'nome' => 'nome',
            'descricao' => 'descrição',
        ]);

        $this->groupService->create($validated);

        return redirect()
            ->route('groups.index')
            ->with('success', 'Grupo criado com sucesso.');
    }

    public function update(Request $request, int $group): RedirectResponse
    {
        $validated = $request->validate([
            'nome' => ['required', 'string', 'max:80'],
            'descricao' => ['nullable', 'string', 'max:200'],
        ], [], [
            'nome' => 'nome',
            'descricao' => 'descrição',
        ]);

        $this->groupService->update($group, $validated);

        return redirect()
            ->route('groups.index')
            ->with('success', 'Grupo atualizado com sucesso.');
    }

    public function destroy(int $group): RedirectResponse
    {
        $this->groupService->destroy($group);

        return redirect()
            ->route('groups.index')
            ->with('success', 'Grupo removido com sucesso.');
    }

    public function permissions(int $group): View
    {
        $result = $this->groupService->permissions($group);

        return view('groups.permissions', [
            'pageTitle' => 'Permissões do Grupo',
            'group' => $result['group'],
            'groupPermissions' => $result['permissions'],
            'modules' => $this->groupService->modulesCatalog(),
            'permissions' => $this->groupService->permissionsCatalog(),
        ]);
    }

    public function updatePermissions(Request $request, int $group): RedirectResponse
    {
        $validated = $request->validate([
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['nullable', 'array'],
            'permissions.*.*' => ['string'],
        ]);

        $this->groupService->updatePermissions($group, $validated['permissions'] ?? []);

        return redirect()
            ->route('groups.permissions.edit', $group)
            ->with('success', 'Permissões atualizadas com sucesso.');
    }
}
