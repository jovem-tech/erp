@extends('layouts.app')

@section('content')
    @php
        $activeTab = (string) request()->query('tab', 'aparencia');
        $tabs = [
            'aparencia' => [
                'label' => 'Aparência',
                'icon' => 'bi-palette',
                'description' => 'Tema, densidade e identidade visual do desktop.',
            ],
            'empresa' => [
                'label' => 'Dados da Empresa',
                'icon' => 'bi-building',
                'description' => 'Razão social, documentos, contatos e endereço institucional.',
            ],
            'sessao' => [
                'label' => 'Sessão e Segurança',
                'icon' => 'bi-shield-lock',
                'description' => 'Políticas de acesso, sessão e endurecimento da navegação.',
            ],
        ];
    @endphp

    <section class="desktop-page-stack">
        <div class="desktop-page-hero">
            <div>
                <h2>Configurações do Sistema</h2>
                <p>Este bloco concentra aparência, dados institucionais e controles de sessão e segurança. As integrações ficam em uma página própria.</p>
            </div>
        </div>

        <div class="surface-card desktop-config-tabs-shell">
            <ul class="nav nav-pills flex-wrap gap-2 desktop-config-tabs" role="tablist" aria-label="Configurações do sistema">
                @foreach ($tabs as $key => $tab)
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link {{ $activeTab === $key ? 'active d-flex align-items-center' : 'd-flex align-items-center' }}"
                            type="button"
                            data-config-subtab="{{ $key }}"
                            aria-pressed="{{ $activeTab === $key ? 'true' : 'false' }}"
                        >
                            <i class="bi {{ $tab['icon'] }} me-1"></i>{{ $tab['label'] }}
                        </button>
                    </li>
                @endforeach
            </ul>

            <div class="config-subpanel {{ $activeTab === 'aparencia' ? 'is-active' : '' }}" data-config-subpanel="aparencia">
                <div class="desktop-grid desktop-grid-two">
                    <div class="desktop-form-card">
                        <div class="surface-card-header">
                            <div>
                                <h3 class="surface-title mb-1">Aparência</h3>
                                <p class="surface-subtitle mb-0">Espaço reservado para temas, densidade visual e identidade do painel.</p>
                            </div>
                        </div>

                        <div class="alert alert-info border-0 mb-0">
                            <strong>Próxima etapa:</strong> esta área vai concentrar as preferências visuais do desktop sem misturar com integrações ou finanças.
                        </div>
                    </div>

                    <div class="desktop-form-card">
                        <h4 class="surface-title mb-2">O que vai ficar aqui</h4>
                        <div class="d-flex flex-wrap gap-2">
                            <span class="badge text-bg-light border text-secondary">Tema</span>
                            <span class="badge text-bg-light border text-secondary">Densidade</span>
                            <span class="badge text-bg-light border text-secondary">Logo</span>
                            <span class="badge text-bg-light border text-secondary">Branding</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="config-subpanel {{ $activeTab === 'empresa' ? 'is-active' : '' }}" data-config-subpanel="empresa">
                <div class="desktop-grid desktop-grid-two">
                    <div class="desktop-form-card">
                        <div class="surface-card-header">
                            <div>
                                <h3 class="surface-title mb-1">Dados da Empresa</h3>
                                <p class="surface-subtitle mb-0">Cadastro fiscal e institucional da operação.</p>
                            </div>
                        </div>

                        <div class="alert alert-warning border-0 mb-0">
                            <strong>Em consolidação:</strong> este bloco vai reunir os dados usados em documentos, rodapé e comunicações oficiais.
                        </div>
                    </div>

                    <div class="desktop-form-card">
                        <h4 class="surface-title mb-2">Campos esperados</h4>
                        <ul class="mb-0 ps-3 text-muted">
                            <li>Razão social e nome fantasia</li>
                            <li>CNPJ, inscrição e endereço</li>
                            <li>Telefone, e-mail e logo institucional</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="config-subpanel {{ $activeTab === 'sessao' ? 'is-active' : '' }}" data-config-subpanel="sessao">
                <div class="desktop-grid desktop-grid-two">
                    <div class="desktop-form-card">
                        <div class="surface-card-header">
                            <div>
                                <h3 class="surface-title mb-1">Sessão e Segurança</h3>
                                <p class="surface-subtitle mb-0">Central para políticas de sessão, bloqueio e endurecimento do acesso.</p>
                            </div>
                        </div>

                        <div class="alert alert-success border-0 mb-0">
                            <strong>Objetivo:</strong> manter a segurança do sistema sem espalhar controles em páginas diferentes.
                        </div>
                    </div>

                    <div class="desktop-form-card">
                        <h4 class="surface-title mb-2">Pontos previstos</h4>
                        <div class="d-flex flex-wrap gap-2">
                            <span class="badge text-bg-light border text-secondary">Tempo de sessão</span>
                            <span class="badge text-bg-light border text-secondary">Reautenticação</span>
                            <span class="badge text-bg-light border text-secondary">Políticas de senha</span>
                            <span class="badge text-bg-light border text-secondary">Bloqueios</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
