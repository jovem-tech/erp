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
                @php $currentTheme = session('desktop_theme', 'default'); @endphp
                <form method="POST" action="{{ route('configurations.appearance.update') }}">
                    @csrf
                    <input type="hidden" name="theme" id="themeInput" value="{{ $currentTheme }}">

                    <div class="desktop-form-card mb-0">
                        <div class="surface-card-header">
                            <div>
                                <h3 class="surface-title mb-1">Tema do Sistema</h3>
                                <p class="surface-subtitle mb-0">Escolha a identidade visual do painel. A mudança é aplicada imediatamente após salvar.</p>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-check2 me-1"></i>Salvar tema
                            </button>
                        </div>

                        <div class="d-flex flex-wrap gap-3 mt-1">

                            {{-- Card: Padrão (Roxo) --}}
                            <div class="theme-picker-card {{ $currentTheme === 'default' ? 'is-active' : '' }}"
                                 data-theme-value="default"
                                 onclick="pickTheme(this)"
                                 style="cursor:pointer; width:170px; border:2px solid var(--desktop-border); border-radius:16px; overflow:hidden; transition: border-color .18s, box-shadow .18s;">
                                <div style="height:88px; background:#f5f7fc; display:flex; align-items:stretch; overflow:hidden; border-radius:12px 12px 0 0;">
                                    <div style="width:44px; background:#ffffff; border-right:1px solid #e2e8f0; flex-shrink:0;"></div>
                                    <div style="flex:1; padding:10px 8px; display:flex; flex-direction:column; gap:5px;">
                                        <div style="height:9px; background:#6f5afc; border-radius:4px; width:65%;"></div>
                                        <div style="height:5px; background:#e2e8f0; border-radius:3px; width:90%;"></div>
                                        <div style="height:5px; background:#eef2ff; border-radius:3px; width:55%;"></div>
                                        <div style="height:18px; background:#6f5afc; border-radius:6px; width:45%; margin-top:2px;"></div>
                                    </div>
                                </div>
                                <div style="padding:10px 12px; background:var(--desktop-surface);">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span style="font-size:.82rem; font-weight:600; color:var(--desktop-heading);">Padrão</span>
                                        <i class="bi bi-check-circle-fill theme-check-icon" style="font-size:.9rem; color:#6f5afc; display:{{ $currentTheme === 'default' ? 'inline' : 'none' }};"></i>
                                    </div>
                                    <small style="color:var(--desktop-text-soft); font-size:.72rem;">Roxo · Tema atual</small>
                                </div>
                            </div>

                            {{-- Card: Jovem Tech (Azul) --}}
                            <div class="theme-picker-card {{ $currentTheme === 'jovem-tech' ? 'is-active' : '' }}"
                                 data-theme-value="jovem-tech"
                                 onclick="pickTheme(this)"
                                 style="cursor:pointer; width:170px; border:2px solid var(--desktop-border); border-radius:16px; overflow:hidden; transition: border-color .18s, box-shadow .18s;">
                                <div style="height:88px; background:#F4F8FF; display:flex; align-items:stretch; overflow:hidden; border-radius:12px 12px 0 0;">
                                    <div style="width:44px; background:linear-gradient(180deg,#254F8D,#1E4278); flex-shrink:0;"></div>
                                    <div style="flex:1; padding:10px 8px; display:flex; flex-direction:column; gap:5px;">
                                        <div style="height:9px; background:#3868B0; border-radius:4px; width:65%;"></div>
                                        <div style="height:5px; background:#D7E3F4; border-radius:3px; width:90%;"></div>
                                        <div style="height:5px; background:#EEF5FF; border-radius:3px; width:55%;"></div>
                                        <div style="height:18px; background:#3868B0; border-radius:6px; width:45%; margin-top:2px;"></div>
                                    </div>
                                </div>
                                <div style="padding:10px 12px; background:var(--desktop-surface);">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span style="font-size:.82rem; font-weight:600; color:var(--desktop-heading);">Jovem Tech</span>
                                        <i class="bi bi-check-circle-fill theme-check-icon" style="font-size:.9rem; color:#3868B0; display:{{ $currentTheme === 'jovem-tech' ? 'inline' : 'none' }};"></i>
                                    </div>
                                    <small style="color:var(--desktop-text-soft); font-size:.72rem;">Azul · Institucional</small>
                                </div>
                            </div>

                            {{-- Card: Escuro --}}
                            <div class="theme-picker-card {{ $currentTheme === 'dark' ? 'is-active' : '' }}"
                                 data-theme-value="dark"
                                 onclick="pickTheme(this)"
                                 style="cursor:pointer; width:170px; border:2px solid var(--desktop-border); border-radius:16px; overflow:hidden; transition: border-color .18s, box-shadow .18s;">
                                <div style="height:88px; background:#0D1117; display:flex; align-items:stretch; overflow:hidden; border-radius:12px 12px 0 0;">
                                    <div style="width:44px; background:linear-gradient(180deg,#1A1035,#0E0A22); flex-shrink:0;"></div>
                                    <div style="flex:1; padding:10px 8px; display:flex; flex-direction:column; gap:5px;">
                                        <div style="height:9px; background:#7C6EFA; border-radius:4px; width:65%;"></div>
                                        <div style="height:5px; background:#1A2035; border-radius:3px; width:90%;"></div>
                                        <div style="height:5px; background:#1E2640; border-radius:3px; width:55%;"></div>
                                        <div style="height:18px; background:#7C6EFA; border-radius:6px; width:45%; margin-top:2px;"></div>
                                    </div>
                                </div>
                                <div style="padding:10px 12px; background:var(--desktop-surface);">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span style="font-size:.82rem; font-weight:600; color:var(--desktop-heading);">Escuro</span>
                                        <i class="bi bi-check-circle-fill theme-check-icon" style="font-size:.9rem; color:#7C6EFA; display:{{ $currentTheme === 'dark' ? 'inline' : 'none' }};"></i>
                                    </div>
                                    <small style="color:var(--desktop-text-soft); font-size:.72rem;">Roxo · Modo noturno</small>
                                </div>
                            </div>

                        </div>
                    </div>
                </form>

                <style>
                    .theme-picker-card.is-active {
                        border-color: var(--desktop-primary) !important;
                        box-shadow: 0 0 0 3px var(--desktop-primary-soft);
                    }
                    .theme-picker-card:hover:not(.is-active) {
                        border-color: var(--desktop-primary);
                    }
                </style>
                <script>
                    function pickTheme(el) {
                        document.querySelectorAll('.theme-picker-card').forEach(function(c) {
                            c.classList.remove('is-active');
                            c.querySelector('.theme-check-icon').style.display = 'none';
                        });
                        el.classList.add('is-active');
                        el.querySelector('.theme-check-icon').style.display = 'inline';
                        document.getElementById('themeInput').value = el.dataset.themeValue;
                    }
                </script>
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
