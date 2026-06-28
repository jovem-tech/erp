@extends('layouts.app')

@section('content')
    @php
        $modules = is_array($profile['modules'] ?? null) ? $profile['modules'] : [];
        $permissions = is_array($profile['permissions'] ?? null) ? $profile['permissions'] : [];
    @endphp

    <section class="desktop-grid desktop-grid-two mb-4">
        <article class="surface-card">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">Meu Perfil</h2>
                    <p class="surface-subtitle">Resumo de leitura do usuário autenticado no desktop.</p>
                </div>
                <a href="{{ route('profile.edit') }}" class="btn btn-outline-light btn-sm">Configurar</a>
            </div>

            <div class="desktop-profile-summary">
                <div class="desktop-profile-summary-avatar">
                    {{ mb_strtoupper(mb_substr((string) ($profile['nome'] ?? 'U'), 0, 1)) }}
                </div>

                <div>
                    <h3 class="surface-title fs-5 mb-1">{{ $profile['nome'] ?? 'Usuário' }}</h3>
                    <p class="surface-subtitle mb-1">{{ $profile['email'] ?? '' }}</p>
                    <span class="desktop-chip">{{ $profile['group']['nome'] ?? ($profile['perfil'] ?? 'Acesso administrativo') }}</span>
                </div>
            </div>

            <div class="detail-list mt-4">
                <div class="detail-item">
                    <strong>Último acesso</strong>
                    <p>{{ $profile['ultimo_acesso'] ?? 'Não informado' }}</p>
                </div>

                <div class="detail-item">
                    <strong>Usuário ativo</strong>
                    <p>{{ !empty($profile['ativo']) ? 'Sim' : 'Não' }}</p>
                </div>

                <div class="detail-item">
                    <strong>Módulos liberados</strong>
                    <p>{{ $modules !== [] ? implode(', ', array_map(static fn ($module) => (string) $module, $modules)) : 'Nenhum módulo liberado' }}</p>
                </div>
            </div>
        </article>

        <article class="surface-card">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">Permissões efetivas</h2>
                    <p class="surface-subtitle">A navbar e as rotas do desktop usam estes mesmos dados.</p>
                </div>
            </div>

            <div class="desktop-permissions-stack">
                @forelse ($permissions as $module => $actions)
                    <div class="desktop-permission-card">
                        <strong>{{ ucfirst((string) $module) }}</strong>
                        <div class="desktop-permission-actions">
                            @foreach ((array) $actions as $action)
                                <span class="status-pill status-pill-sm">{{ $action }}</span>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="surface-subtitle mb-0">Nenhuma permissão disponível no momento.</p>
                @endforelse
            </div>
        </article>
    </section>
@endsection
