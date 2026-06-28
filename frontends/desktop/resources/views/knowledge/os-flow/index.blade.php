@extends('layouts.app')

@section('content')
    @php
        $canEdit = \App\Support\DesktopSession::can('conhecimento', 'editar');
        $canCreate = \App\Support\DesktopSession::can('conhecimento', 'criar');
        $activeStatuses = collect($statuses)->filter(fn ($status) => (bool) ($status['ativo'] ?? false))->values();
        $transitionLookup = collect($transitions)
            ->filter(fn ($transition) => (bool) ($transition['ativo'] ?? false))
            ->map(fn ($transition) => $transition['status_origem_id'] . '-' . $transition['status_destino_id'])
            ->all();
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Gestão de Conhecimento</p>
            <h2 class="surface-title fs-3 mb-2">Fluxo de Trabalho OS</h2>
            <p class="surface-subtitle mb-0">
                Gerencie os status do fluxo de ordens de serviço e quais transições são permitidas entre eles.
            </p>
        </div>
    </div>

    @foreach ($statusesByGroup as $grupoMacro => $statusesOfGroup)
        <section class="surface-card mb-4">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">{{ $grupoMacro !== '' ? $grupoMacro : 'Sem grupo macro' }}</h2>
                    <p class="surface-subtitle">Status do fluxo agrupados pela fase macro "{{ $grupoMacro }}".</p>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Ordem</th>
                        <th>Final</th>
                        <th>Pausa</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($statusesOfGroup as $status)
                        @php
                            $statusId = (int) ($status['id'] ?? 0);
                            $active = (bool) ($status['ativo'] ?? false);
                        @endphp
                        <tr>
                            <td colspan="5" class="p-0">
                                <details>
                                    <summary class="d-flex align-items-center gap-2 p-2">
                                        <span class="flex-fill">{{ $status['nome'] ?? '' }}</span>
                                        <span class="text-secondary">{{ (int) ($status['ordem_fluxo'] ?? 0) }}</span>
                                        <span>
                                            @include('layouts.partials.status-pill', [
                                                'label' => !empty($status['status_final']) ? 'Sim' : 'Não',
                                                'color' => !empty($status['status_final']) ? '#4da4ff' : '#8b93a7',
                                                'small' => true,
                                            ])
                                        </span>
                                        <span>
                                            @include('layouts.partials.status-pill', [
                                                'label' => !empty($status['status_pausa']) ? 'Sim' : 'Não',
                                                'color' => !empty($status['status_pausa']) ? '#ffb84d' : '#8b93a7',
                                                'small' => true,
                                            ])
                                        </span>
                                        <span>
                                            @include('layouts.partials.status-pill', [
                                                'label' => $active ? 'Ativo' : 'Inativo',
                                                'color' => $active ? '#29c384' : '#8b93a7',
                                                'small' => true,
                                            ])
                                        </span>
                                        @if ($canEdit)
                                            <i class="bi bi-pencil ms-2"></i>
                                        @endif
                                    </summary>

                                    <div class="p-3">
                                        <form
                                            method="post"
                                            action="{{ route('knowledge.os-flow.status.update', $statusId) }}"
                                            class="desktop-form-grid"
                                        >
                                            @csrf
                                            @method('PATCH')

                                            <div>
                                                <label for="statusCodigo{{ $statusId }}">Código</label>
                                                <input
                                                    type="text"
                                                    id="statusCodigo{{ $statusId }}"
                                                    class="form-control"
                                                    value="{{ $status['codigo'] ?? '' }}"
                                                    disabled
                                                    readonly
                                                >
                                                <div class="form-text">O código é imutável após a criação do status.</div>
                                            </div>

                                            <div>
                                                <label for="statusNome{{ $statusId }}">Nome *</label>
                                                <input
                                                    type="text"
                                                    id="statusNome{{ $statusId }}"
                                                    name="nome"
                                                    class="form-control"
                                                    value="{{ $status['nome'] ?? '' }}"
                                                    maxlength="120"
                                                    required
                                                    @disabled(!$canEdit)
                                                >
                                            </div>

                                            <div>
                                                <label for="statusGrupoMacro{{ $statusId }}">Grupo macro *</label>
                                                <input
                                                    type="text"
                                                    id="statusGrupoMacro{{ $statusId }}"
                                                    name="grupo_macro"
                                                    class="form-control"
                                                    value="{{ $status['grupo_macro'] ?? '' }}"
                                                    maxlength="60"
                                                    required
                                                    @disabled(!$canEdit)
                                                >
                                            </div>

                                            <div>
                                                <label for="statusIcone{{ $statusId }}">Ícone</label>
                                                <input
                                                    type="text"
                                                    id="statusIcone{{ $statusId }}"
                                                    name="icone"
                                                    class="form-control"
                                                    value="{{ $status['icone'] ?? '' }}"
                                                    maxlength="60"
                                                    placeholder="bi-clipboard-check"
                                                    @disabled(!$canEdit)
                                                >
                                            </div>

                                            <div>
                                                <label for="statusCor{{ $statusId }}">Cor</label>
                                                <input
                                                    type="text"
                                                    id="statusCor{{ $statusId }}"
                                                    name="cor"
                                                    class="form-control"
                                                    value="{{ $status['cor'] ?? '' }}"
                                                    maxlength="30"
                                                    placeholder="#4da4ff"
                                                    @disabled(!$canEdit)
                                                >
                                            </div>

                                            <div>
                                                <label for="statusOrdemFluxo{{ $statusId }}">Ordem no fluxo</label>
                                                <input
                                                    type="number"
                                                    id="statusOrdemFluxo{{ $statusId }}"
                                                    name="ordem_fluxo"
                                                    class="form-control"
                                                    value="{{ (int) ($status['ordem_fluxo'] ?? 0) }}"
                                                    @disabled(!$canEdit)
                                                >
                                            </div>

                                            <div>
                                                <label for="statusEstadoFluxoPadrao{{ $statusId }}">Estado de fluxo padrão</label>
                                                <input
                                                    type="text"
                                                    id="statusEstadoFluxoPadrao{{ $statusId }}"
                                                    name="estado_fluxo_padrao"
                                                    class="form-control"
                                                    value="{{ $status['estado_fluxo_padrao'] ?? '' }}"
                                                    maxlength="40"
                                                    @disabled(!$canEdit)
                                                >
                                            </div>

                                            <div class="col-span-full d-flex flex-wrap gap-4">
                                                <div class="form-check form-switch">
                                                    <input type="hidden" name="status_final" value="0">
                                                    <input
                                                        class="form-check-input"
                                                        type="checkbox"
                                                        name="status_final"
                                                        id="statusFinal{{ $statusId }}"
                                                        value="1"
                                                        @checked(!empty($status['status_final']))
                                                        @disabled(!$canEdit)
                                                    >
                                                    <label class="form-check-label" for="statusFinal{{ $statusId }}">Status final</label>
                                                </div>

                                                <div class="form-check form-switch">
                                                    <input type="hidden" name="status_pausa" value="0">
                                                    <input
                                                        class="form-check-input"
                                                        type="checkbox"
                                                        name="status_pausa"
                                                        id="statusPausa{{ $statusId }}"
                                                        value="1"
                                                        @checked(!empty($status['status_pausa']))
                                                        @disabled(!$canEdit)
                                                    >
                                                    <label class="form-check-label" for="statusPausa{{ $statusId }}">Status de pausa</label>
                                                </div>

                                                <div class="form-check form-switch">
                                                    <input type="hidden" name="gera_evento_crm" value="0">
                                                    <input
                                                        class="form-check-input"
                                                        type="checkbox"
                                                        name="gera_evento_crm"
                                                        id="statusGeraEventoCrm{{ $statusId }}"
                                                        value="1"
                                                        @checked(!empty($status['gera_evento_crm']))
                                                        @disabled(!$canEdit)
                                                    >
                                                    <label class="form-check-label" for="statusGeraEventoCrm{{ $statusId }}">Gera evento CRM</label>
                                                </div>

                                                <div class="form-check form-switch">
                                                    <input type="hidden" name="ativo" value="0">
                                                    <input
                                                        class="form-check-input"
                                                        type="checkbox"
                                                        name="ativo"
                                                        id="statusAtivo{{ $statusId }}"
                                                        value="1"
                                                        @checked($active)
                                                        @disabled(!$canEdit)
                                                    >
                                                    <label class="form-check-label" for="statusAtivo{{ $statusId }}">Status ativo</label>
                                                </div>
                                            </div>

                                            @if ($canEdit)
                                                <div class="col-span-full d-flex justify-content-end">
                                                    <button type="submit" class="btn btn-primary">
                                                        <i class="bi bi-save me-2"></i>
                                                        Salvar status
                                                    </button>
                                                </div>
                                            @endif
                                        </form>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach

    @if ($canCreate)
        <section class="surface-card mb-4">
            <details>
                <summary class="d-flex align-items-center gap-2">
                    <i class="bi bi-plus-lg"></i>
                    <span>Novo status</span>
                </summary>

                <div class="p-3">
                    <form
                        method="post"
                        action="{{ route('knowledge.os-flow.status.store') }}"
                        class="desktop-form-grid"
                    >
                        @csrf

                        <div>
                            <label for="newStatusCodigo">Código *</label>
                            <input
                                type="text"
                                id="newStatusCodigo"
                                name="codigo"
                                class="form-control"
                                maxlength="80"
                                placeholder="ex.: aguardando_peca"
                                required
                            >
                            <div class="form-text">O código não poderá ser alterado após a criação.</div>
                        </div>

                        <div>
                            <label for="newStatusNome">Nome *</label>
                            <input type="text" id="newStatusNome" name="nome" class="form-control" maxlength="120" required>
                        </div>

                        <div>
                            <label for="newStatusGrupoMacro">Grupo macro *</label>
                            <input type="text" id="newStatusGrupoMacro" name="grupo_macro" class="form-control" maxlength="60" required>
                        </div>

                        <div>
                            <label for="newStatusIcone">Ícone</label>
                            <input type="text" id="newStatusIcone" name="icone" class="form-control" maxlength="60" placeholder="bi-clipboard-check">
                        </div>

                        <div>
                            <label for="newStatusCor">Cor</label>
                            <input type="text" id="newStatusCor" name="cor" class="form-control" maxlength="30" placeholder="#4da4ff">
                        </div>

                        <div>
                            <label for="newStatusOrdemFluxo">Ordem no fluxo</label>
                            <input type="number" id="newStatusOrdemFluxo" name="ordem_fluxo" class="form-control">
                        </div>

                        <div>
                            <label for="newStatusEstadoFluxoPadrao">Estado de fluxo padrão</label>
                            <input type="text" id="newStatusEstadoFluxoPadrao" name="estado_fluxo_padrao" class="form-control" maxlength="40">
                        </div>

                        <div class="col-span-full d-flex flex-wrap gap-4">
                            <div class="form-check form-switch">
                                <input type="hidden" name="status_final" value="0">
                                <input class="form-check-input" type="checkbox" name="status_final" id="newStatusFinal" value="1">
                                <label class="form-check-label" for="newStatusFinal">Status final</label>
                            </div>

                            <div class="form-check form-switch">
                                <input type="hidden" name="status_pausa" value="0">
                                <input class="form-check-input" type="checkbox" name="status_pausa" id="newStatusPausa" value="1">
                                <label class="form-check-label" for="newStatusPausa">Status de pausa</label>
                            </div>

                            <div class="form-check form-switch">
                                <input type="hidden" name="gera_evento_crm" value="0">
                                <input class="form-check-input" type="checkbox" name="gera_evento_crm" id="newStatusGeraEventoCrm" value="1" checked>
                                <label class="form-check-label" for="newStatusGeraEventoCrm">Gera evento CRM</label>
                            </div>

                            <div class="form-check form-switch">
                                <input type="hidden" name="ativo" value="0">
                                <input class="form-check-input" type="checkbox" name="ativo" id="newStatusAtivo" value="1" checked>
                                <label class="form-check-label" for="newStatusAtivo">Status ativo</label>
                            </div>
                        </div>

                        <div class="col-span-full d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus-lg me-2"></i>
                                Criar status
                            </button>
                        </div>
                    </form>
                </div>
            </details>
        </section>
    @endif

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Matriz de transições</h2>
                <p class="surface-subtitle">Marque para quais status cada situação pode avançar.</p>
            </div>
        </div>

        <form method="post" action="{{ route('knowledge.os-flow.transitions.update') }}">
            @csrf
            @method('PATCH')

            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                    <tr>
                        <th>Origem \ Destino</th>
                        @foreach ($activeStatuses as $destino)
                            <th class="text-center">{{ $destino['nome'] }}</th>
                        @endforeach
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($activeStatuses as $origem)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $origem['nome'] }}</div>
                                <small class="text-secondary">{{ $origem['codigo'] }}</small>
                            </td>
                            @foreach ($activeStatuses as $destino)
                                @if ($origem['id'] === $destino['id'])
                                    <td class="text-center">—</td>
                                @else
                                    <td class="text-center">
                                        <input
                                            type="checkbox"
                                            class="form-check-input"
                                            name="transitions[{{ $origem['id'] }}][]"
                                            value="{{ $destino['id'] }}"
                                            @checked(in_array($origem['id'] . '-' . $destino['id'], $transitionLookup, true))
                                            @disabled(!$canEdit)
                                        >
                                    </td>
                                @endif
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @if ($canEdit)
                <div class="d-flex justify-content-end mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-2"></i>
                        Salvar matriz de transições
                    </button>
                </div>
            @endif
        </form>
    </section>
@endsection
