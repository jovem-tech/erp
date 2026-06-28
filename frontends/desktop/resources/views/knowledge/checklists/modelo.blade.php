@extends('layouts.app')

@section('content')
    @php
        $exists = (bool) ($exists ?? false);
        $modelo = $modelo ?? [];
        $modeloId = (int) ($modelo['id'] ?? 0);
        $itens = $modelo['itens'] ?? [];
        $tipoEquipamentoNome = $tipo_equipamento['nome'] ?? '';
        $indexRoute = 'knowledge.checklists.' . str_replace('_', '-', $tipo);
        $ativo = old('ativo', $modelo['ativo'] ?? true);
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">{{ $pageTitle }}</p>
            <h2 class="surface-title fs-3 mb-2">{{ $tipoEquipamentoNome !== '' ? $tipoEquipamentoNome : 'Tipo de equipamento' }}</h2>
            <p class="surface-subtitle mb-0">Configure o modelo de checklist e os itens associados a este tipo de equipamento.</p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route($indexRoute) }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>
                Voltar
            </a>
        </div>
    </div>

    @if (! $exists)
        @include('layouts.partials.empty-state', [
            'icon' => 'bi-list-check',
            'title' => 'Nenhum modelo configurado',
            'message' => 'Ainda não existe um modelo de checklist para este tipo de equipamento.',
        ])

        <div class="d-flex justify-content-center mt-3">
            <form method="post" action="{{ route('knowledge.checklists.modelo.store', ['tipo' => $tipo, 'tipoEquipamento' => $tipoEquipamento]) }}">
                @csrf
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-2"></i>
                    Criar modelo para este tipo de equipamento
                </button>
            </form>
        </div>
    @else
        <section class="desktop-form-card">
            <div class="surface-card-header">
                <div>
                    <h3 class="surface-title">Dados do modelo</h3>
                    <p class="surface-subtitle">Nome, descrição e status deste modelo de checklist.</p>
                </div>
            </div>

            <form
                method="post"
                action="{{ route('knowledge.checklists.modelo.update', ['tipo' => $tipo, 'modelo' => $modeloId]) }}"
                class="desktop-form-grid"
            >
                @csrf
                @method('PATCH')

                <div>
                    <label for="modeloNome">Nome *</label>
                    <input
                        type="text"
                        id="modeloNome"
                        name="nome"
                        class="form-control @error('nome') is-invalid @enderror"
                        value="{{ old('nome', $modelo['nome'] ?? '') }}"
                        maxlength="160"
                        required
                    >
                    @error('nome')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div>
                    <label for="modeloOrdem">Ordem</label>
                    <input
                        type="number"
                        id="modeloOrdem"
                        name="ordem"
                        class="form-control @error('ordem') is-invalid @enderror"
                        value="{{ old('ordem', $modelo['ordem'] ?? 0) }}"
                    >
                    @error('ordem')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-span-full">
                    <label for="modeloDescricao">Descrição</label>
                    <textarea
                        id="modeloDescricao"
                        name="descricao"
                        class="form-control @error('descricao') is-invalid @enderror"
                        rows="3"
                        placeholder="Detalhes sobre este modelo de checklist"
                    >{{ old('descricao', $modelo['descricao'] ?? '') }}</textarea>
                    @error('descricao')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-span-full form-check form-switch">
                    <input type="hidden" name="ativo" value="0">
                    <input class="form-check-input" type="checkbox" name="ativo" id="modeloAtivo" value="1" @checked((bool) $ativo)>
                    <label class="form-check-label" for="modeloAtivo">Modelo ativo</label>
                </div>

                <div class="col-span-full d-flex justify-content-end gap-2">
                    <button type="submit" class="btn btn-primary">Salvar alterações</button>
                </div>
            </form>
        </section>

        <section class="desktop-form-card mt-4">
            <div class="surface-card-header">
                <div>
                    <h3 class="surface-title">Itens do checklist</h3>
                    <p class="surface-subtitle">Passo a passo que deve ser conferido neste checklist.</p>
                </div>
            </div>

            <form method="post" action="{{ route('knowledge.checklists.items.store', ['tipo' => $tipo, 'modelo' => $modeloId]) }}" class="desktop-form-grid">
                @csrf
                <div class="col-span-full">
                    <label for="itemDescricao">Novo item</label>
                    <div class="d-flex gap-2">
                        <input
                            type="text"
                            id="itemDescricao"
                            name="descricao"
                            class="form-control @error('descricao') is-invalid @enderror"
                            value="{{ old('descricao') }}"
                            maxlength="255"
                            placeholder="Descreva o item do checklist"
                        >
                        <button type="submit" class="btn btn-primary text-nowrap">
                            <i class="bi bi-plus-lg me-2"></i>
                            Adicionar
                        </button>
                    </div>
                    @error('descricao')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            </form>
        </section>

        <section class="surface-table mt-4">
            <div class="surface-table-header">
                <div>
                    <h2 class="surface-title">Itens cadastrados</h2>
                    <p class="surface-subtitle">Reordene, ative/inative ou remova os itens conforme necessário.</p>
                </div>
            </div>

            @if ($itens !== [])
                <div class="table-responsive">
                    <table class="table table-stack align-middle">
                        <thead>
                        <tr>
                            <th>Ordem</th>
                            <th>Descrição</th>
                            <th>Status</th>
                            <th class="text-end">Ações</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($itens as $index => $item)
                            @php
                                $item = array_merge([
                                    'id' => 0,
                                    'descricao' => '',
                                    'ordem' => 0,
                                    'ativo' => true,
                                ], is_array($item) ? $item : []);

                                $itemId = (int) ($item['id'] ?? 0);
                                $itemAtivo = (bool) ($item['ativo'] ?? true);
                            @endphp
                            <tr>
                                <td data-label="Ordem">{{ $index + 1 }}</td>
                                <td data-label="Descrição">{{ $item['descricao'] !== '' ? $item['descricao'] : '—' }}</td>
                                <td data-label="Status">
                                    <form method="post" action="{{ route('knowledge.checklists.items.toggle-active', ['tipo' => $tipo, 'modelo' => $modeloId, 'item' => $itemId]) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn-sm btn-link p-0 text-decoration-none">
                                            @include('layouts.partials.status-pill', [
                                                'label' => $itemAtivo ? 'Ativo' : 'Inativo',
                                                'color' => $itemAtivo ? '#29c384' : '#8b93a7',
                                                'small' => true,
                                            ])
                                        </button>
                                    </form>
                                </td>
                                <td data-label="Ações" class="text-end">
                                    <div class="d-flex gap-1 justify-content-end">
                                        <form method="post" action="{{ route('knowledge.checklists.items.move', ['tipo' => $tipo, 'modelo' => $modeloId, 'item' => $itemId]) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="direction" value="up">
                                            <button type="submit" class="btn btn-sm btn-outline-light" title="Mover para cima" @disabled($index === 0)>
                                                <i class="bi bi-arrow-up"></i>
                                            </button>
                                        </form>

                                        <form method="post" action="{{ route('knowledge.checklists.items.move', ['tipo' => $tipo, 'modelo' => $modeloId, 'item' => $itemId]) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="direction" value="down">
                                            <button type="submit" class="btn btn-sm btn-outline-light" title="Mover para baixo" @disabled($index === count($itens) - 1)>
                                                <i class="bi bi-arrow-down"></i>
                                            </button>
                                        </form>

                                        <form
                                            method="post"
                                            action="{{ route('knowledge.checklists.items.destroy', ['tipo' => $tipo, 'modelo' => $modeloId, 'item' => $itemId]) }}"
                                            data-confirm="Remover este item?"
                                            data-confirm-title="Remover item"
                                            data-confirm-button="Sim, remover"
                                        >
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Remover">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                @include('layouts.partials.empty-state', [
                    'icon' => 'bi-list-check',
                    'title' => 'Nenhum item cadastrado',
                    'message' => 'Adicione o primeiro item para este checklist.',
                ])
            @endif
        </section>
    @endif
@endsection
