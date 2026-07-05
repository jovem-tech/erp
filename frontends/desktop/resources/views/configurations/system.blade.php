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
            'documentacao' => [
                'label' => 'Documentação',
                'icon' => 'bi-journal-text',
                'description' => 'Documentação oficial do sistema: fundação, arquitetura, deploy e histórico.',
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
                @php
                    $companySettings = $company['settings'] ?? [];
                    $companyHasLogo = (bool) ($company['logo']['exists'] ?? false);
                @endphp
                <form method="POST" action="{{ route('configurations.company.update') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="desktop-form-card mb-0">
                        <div class="surface-card-header">
                            <div>
                                <h3 class="surface-title mb-1">Dados da Empresa</h3>
                                <p class="surface-subtitle mb-0">Cadastro fiscal e institucional usado em documentos, rodapé e comunicações oficiais.</p>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-check2 me-1"></i>Salvar dados da empresa
                            </button>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="form-label" for="empresa_razao_social">Razão social</label>
                                <input type="text" class="form-control" id="empresa_razao_social" name="empresa_razao_social" maxlength="255" value="{{ old('empresa_razao_social', $companySettings['empresa_razao_social'] ?? '') }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="empresa_nome_fantasia">Nome fantasia</label>
                                <input type="text" class="form-control" id="empresa_nome_fantasia" name="empresa_nome_fantasia" maxlength="255" value="{{ old('empresa_nome_fantasia', $companySettings['empresa_nome_fantasia'] ?? '') }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="empresa_cnpj">CNPJ</label>
                                <input type="text" class="form-control" id="empresa_cnpj" name="empresa_cnpj" maxlength="32" value="{{ old('empresa_cnpj', $companySettings['empresa_cnpj'] ?? '') }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="empresa_inscricao_estadual">Inscrição estadual</label>
                                <input type="text" class="form-control" id="empresa_inscricao_estadual" name="empresa_inscricao_estadual" maxlength="32" value="{{ old('empresa_inscricao_estadual', $companySettings['empresa_inscricao_estadual'] ?? '') }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="empresa_telefone">Telefone</label>
                                <input type="text" class="form-control" id="empresa_telefone" name="empresa_telefone" maxlength="30" value="{{ old('empresa_telefone', $companySettings['empresa_telefone'] ?? '') }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="empresa_email">E-mail</label>
                                <input type="email" class="form-control" id="empresa_email" name="empresa_email" maxlength="255" value="{{ old('empresa_email', $companySettings['empresa_email'] ?? '') }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="empresa_endereco">Endereço</label>
                                <input type="text" class="form-control" id="empresa_endereco" name="empresa_endereco" maxlength="255" value="{{ old('empresa_endereco', $companySettings['empresa_endereco'] ?? '') }}">
                            </div>
                        </div>
                    </div>

                    <div class="desktop-form-card mt-3 mb-0">
                        <div class="surface-card-header">
                            <div>
                                <h3 class="surface-title mb-1">Logo da Empresa</h3>
                                <p class="surface-subtitle mb-0">Usada no menu lateral do sistema e como identidade institucional.</p>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap align-items-center gap-3 mt-1">
                            <div class="d-flex align-items-center justify-content-center border rounded" style="width:96px; height:96px; overflow:hidden; background:var(--desktop-surface);">
                                @if ($companyHasLogo)
                                    <img src="{{ route('configurations.company.logo') }}" alt="Logo atual da empresa" style="max-width:100%; max-height:100%; object-fit:contain;">
                                @else
                                    <i class="bi bi-building text-muted" style="font-size:2rem;"></i>
                                @endif
                            </div>
                            <div class="flex-grow-1" style="min-width:220px;">
                                <label class="form-label" for="empresa_logo">Arquivo da logo</label>
                                <input type="file" class="form-control" id="empresa_logo" name="empresa_logo" accept="image/png, image/jpeg, image/gif, image/svg+xml">
                                <small class="text-muted d-block mt-1">PNG, JPG, GIF ou SVG, até 4&nbsp;MB.</small>
                            </div>
                        </div>
                    </div>
                </form>
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

            <div class="config-subpanel {{ $activeTab === 'documentacao' ? 'is-active' : '' }}" data-config-subpanel="documentacao">
                @php
                    $docsTree = $documentationTree ?? [];
                    $docsDoc = $documentationDoc ?? null;
                @endphp

                @if ($docsTree === [])
                    <div class="alert alert-warning border-0 mb-0">
                        A pasta <code>documentacao/</code> não foi encontrada no servidor. Verifique se o repositório foi publicado completo.
                    </div>
                @else
                    <div class="docs-browser">
                        <aside class="docs-browser-nav surface-card p-3">
                            <h4 class="surface-title mb-2" style="font-size:.9rem;">
                                <i class="bi bi-collection me-1"></i>Índice da documentação
                            </h4>
                            @foreach ($docsTree as $group)
                                @php
                                    $groupOpen = $docsDoc !== null && (
                                        $group['key'] === 'indice'
                                            ? $docsDoc['path'] === 'README.md'
                                            : str_starts_with($docsDoc['path'], $group['key'] . '/')
                                    );
                                @endphp
                                <details class="docs-nav-group" {{ $groupOpen ? 'open' : '' }}>
                                    <summary>{{ $group['label'] }} <span class="docs-count">{{ count($group['items']) }}</span></summary>
                                    <ul class="docs-nav-list">
                                        @foreach ($group['items'] as $item)
                                            <li>
                                                <a href="{{ route('configurations.system.index', ['tab' => 'documentacao', 'doc' => $item['path']]) }}"
                                                   class="{{ $docsDoc !== null && $docsDoc['path'] === $item['path'] ? 'is-current' : '' }}">
                                                    {{ $item['title'] }}
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endforeach
                        </aside>

                        <article class="docs-browser-content surface-card p-4">
                            @if ($docsDoc === null)
                                <div class="alert alert-secondary border-0 mb-0">
                                    Selecione um documento no índice ao lado para começar a leitura.
                                </div>
                            @else
                                <div class="docs-content-meta d-flex align-items-center justify-content-between mb-3">
                                    <span class="badge text-bg-light border text-secondary">
                                        <i class="bi bi-file-earmark-text me-1"></i>{{ $docsDoc['path'] }}
                                    </span>
                                </div>
                                <div class="docs-markdown">{!! $docsDoc['html'] !!}</div>
                            @endif
                        </article>
                    </div>

                    <style>
                        .docs-browser { display: grid; grid-template-columns: 300px minmax(0, 1fr); gap: 1rem; align-items: start; }
                        @media (max-width: 992px) { .docs-browser { grid-template-columns: 1fr; } }
                        .docs-browser-nav { position: sticky; top: 84px; max-height: calc(100vh - 110px); overflow-y: auto; }
                        .docs-nav-group summary { cursor: pointer; font-weight: 600; font-size: .82rem; color: var(--desktop-heading); padding: .4rem .35rem; border-radius: 8px; }
                        .docs-nav-group summary:hover { background: var(--desktop-primary-soft); }
                        .docs-count { font-weight: 400; font-size: .72rem; color: var(--desktop-text-soft); }
                        .docs-nav-list { list-style: none; margin: 0 0 .5rem; padding: 0 0 0 .9rem; }
                        .docs-nav-list a { display: block; padding: .3rem .45rem; font-size: .8rem; color: var(--desktop-text-soft); border-radius: 7px; text-decoration: none; line-height: 1.25; }
                        .docs-nav-list a:hover { background: var(--desktop-primary-soft); color: var(--desktop-heading); }
                        .docs-nav-list a.is-current { background: var(--desktop-primary); color: #fff; }
                        .docs-markdown { font-size: .9rem; line-height: 1.65; color: var(--desktop-text); overflow-wrap: anywhere; }
                        .docs-markdown h1 { font-size: 1.45rem; margin: 0 0 1rem; color: var(--desktop-heading); }
                        .docs-markdown h2 { font-size: 1.15rem; margin: 1.6rem 0 .7rem; padding-bottom: .3rem; border-bottom: 1px solid var(--desktop-border); color: var(--desktop-heading); }
                        .docs-markdown h3 { font-size: 1rem; margin: 1.2rem 0 .5rem; color: var(--desktop-heading); }
                        .docs-markdown h4 { font-size: .9rem; margin: 1rem 0 .4rem; color: var(--desktop-heading); }
                        .docs-markdown pre { background: #0f172a; color: #e2e8f0; border-radius: 10px; padding: .85rem 1rem; overflow-x: auto; font-size: .78rem; }
                        .docs-markdown code { font-size: .8em; }
                        .docs-markdown :not(pre) > code { background: var(--desktop-primary-soft); color: var(--desktop-heading); border-radius: 5px; padding: .1rem .35rem; }
                        .docs-markdown table { width: 100%; border-collapse: collapse; margin: .8rem 0; font-size: .82rem; display: block; overflow-x: auto; }
                        .docs-markdown th, .docs-markdown td { border: 1px solid var(--desktop-border); padding: .45rem .6rem; text-align: left; }
                        .docs-markdown th { background: var(--desktop-primary-soft); color: var(--desktop-heading); }
                        .docs-markdown blockquote { border-left: 4px solid var(--desktop-primary); background: var(--desktop-primary-soft); margin: .8rem 0; padding: .6rem .9rem; border-radius: 0 8px 8px 0; }
                        .docs-markdown blockquote p:last-child { margin-bottom: 0; }
                        .docs-markdown ul, .docs-markdown ol { padding-left: 1.4rem; }
                        .docs-markdown a { color: var(--desktop-primary); }
                        .docs-markdown img { max-width: 100%; }
                        .docs-markdown hr { border: 0; border-top: 1px solid var(--desktop-border); margin: 1.4rem 0; }
                    </style>
                @endif
            </div>
        </div>
    </section>
@endsection
