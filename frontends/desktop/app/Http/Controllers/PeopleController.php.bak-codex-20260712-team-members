<?php

namespace App\Http\Controllers;

use App\Support\DesktopSession;
use Illuminate\View\View;

class PeopleController extends DesktopController
{
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

    public function technicalTeam(): View
    {
        return view('people.placeholder', [
            'pageTitle' => 'Equipe técnica',
            'featureTitle' => 'Equipe técnica',
            'featureSubtitle' => 'Entrada comercial reservada para a equipe técnica vinculada ao fluxo operacional.',
            'featureMessage' => 'A navegação já está no padrão do legado. A listagem e as ações específicas deste domínio serão conectadas ao backend nas próximas etapas.',
            'primaryLabel' => 'Voltar ao dashboard',
            'primaryUrl' => route('dashboard'),
            'secondaryLabel' => 'Abrir usuários',
            'secondaryUrl' => DesktopSession::can('usuarios', 'visualizar') ? route('users.index') : null,
        ]);
    }
}
