<?php

namespace App\Http\Controllers;

use App\Services\TeamMemberService;
use App\Support\DesktopSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PeopleController extends DesktopController
{
    public function __construct(
        private readonly TeamMemberService $teamMemberService
    ) {
    }

    public function suppliers(): View
    {
        return view('people.placeholder', [
            'pageTitle' => 'Fornecedores',
            'featureTitle' => 'Fornecedores',
            'featureSubtitle' => 'Entrada comercial reservada para o cadastro e acompanhamento de fornecedores do ERP.',
            'featureMessage' => 'O menu já está organizado no padrão do legado e a tela será expandida nas próximas fases com listagem, cadastro e vínculo operacional.',
            'primaryLabel' => 'Voltar ao dashboard',
            'primaryUrl' => route('dashboard'),
            'secondaryLabel' => 'Abrir clientes',
            'secondaryUrl' => DesktopSession::can('clientes', 'visualizar') ? route('clients.index') : null,
        ]);
    }

    public function technicalTeam(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'role' => trim((string) $request->query('role', '')),
            'active' => trim((string) $request->query('active', '')),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 15),
            'include_available_users' => 1,
        ];

        $result = $this->teamMemberService->paginate(array_filter($filters, static fn ($value) => $value !== '' && $value !== 0));

        return view('people.technical-team', [
            'pageTitle' => 'Equipe da assistência',
            'teamMembers' => $result['items'],
            'availableUsers' => $result['available_users'],
            'pagination' => $result['pagination'],
            'filters' => $filters,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nome' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'cargo' => ['nullable', 'string', 'max:100'],
            'usuario_id' => ['nullable', 'integer'],
            'atua_tecnico' => ['nullable', 'boolean'],
            'atua_vendas' => ['nullable', 'boolean'],
            'atua_administrativo' => ['nullable', 'boolean'],
            'observacoes' => ['nullable', 'string', 'max:1000'],
            'ativo' => ['nullable', 'boolean'],
        ], [], [
            'nome' => 'nome',
            'email' => 'e-mail',
            'telefone' => 'telefone',
            'cargo' => 'cargo',
            'usuario_id' => 'usuário vinculado',
            'observacoes' => 'observações',
        ]);

        $payload = [
            ...$validated,
            'usuario_id' => isset($validated['usuario_id']) && (int) $validated['usuario_id'] > 0 ? (int) $validated['usuario_id'] : null,
            'atua_tecnico' => $request->boolean('atua_tecnico'),
            'atua_vendas' => $request->boolean('atua_vendas'),
            'atua_administrativo' => $request->boolean('atua_administrativo'),
            'ativo' => $request->boolean('ativo', true),
        ];

        $this->teamMemberService->create($payload);

        return redirect()
            ->route('technicians.index')
            ->with('success', 'Membro da equipe salvo com sucesso.');
    }

    public function update(Request $request, int $member): RedirectResponse
    {
        $validated = $request->validate([
            'nome' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'cargo' => ['nullable', 'string', 'max:100'],
            'usuario_id' => ['nullable', 'integer'],
            'atua_tecnico' => ['nullable', 'boolean'],
            'atua_vendas' => ['nullable', 'boolean'],
            'atua_administrativo' => ['nullable', 'boolean'],
            'observacoes' => ['nullable', 'string', 'max:1000'],
            'ativo' => ['nullable', 'boolean'],
        ], [], [
            'nome' => 'nome',
            'email' => 'e-mail',
            'telefone' => 'telefone',
            'cargo' => 'cargo',
            'usuario_id' => 'usuário vinculado',
            'observacoes' => 'observações',
        ]);

        $payload = [
            ...$validated,
            'usuario_id' => isset($validated['usuario_id']) && (int) $validated['usuario_id'] > 0 ? (int) $validated['usuario_id'] : null,
            'atua_tecnico' => $request->boolean('atua_tecnico'),
            'atua_vendas' => $request->boolean('atua_vendas'),
            'atua_administrativo' => $request->boolean('atua_administrativo'),
            'ativo' => $request->boolean('ativo'),
        ];

        $this->teamMemberService->update($member, $payload);

        return redirect()
            ->route('technicians.index')
            ->with('success', 'Membro da equipe atualizado com sucesso.');
    }

    public function updateTechnicalTeamActive(Request $request, int $member): RedirectResponse
    {
        $validated = $request->validate([
            'active' => ['required', 'boolean'],
        ], [], [
            'active' => 'status',
        ]);

        $this->teamMemberService->updateActive($member, (bool) $validated['active']);

        return redirect()
            ->route('technicians.index')
            ->with('success', 'Status do membro da equipe atualizado com sucesso.');
    }
}
