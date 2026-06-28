@extends('layouts.app')

@section('content')
    @php
        $defeitoId = (int) ($defeito['id'] ?? 0);
        $procedimentos = $defeito['procedimentos'] ?? [];
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Base de Defeitos</p>
            <h2 class="surface-title fs-3 mb-2">Editar defeito</h2>
            <p class="surface-subtitle mb-0">Atualize os dados do defeito e os procedimentos de reparo associados.</p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('knowledge.defects.index') }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>
                Voltar
            </a>
        </div>
    </div>

    @include('knowledge.defects.form', [
        'defeito' => $defeito ?? [],
        'equipmentTypes' => $equipmentTypes ?? [],
        'formAction' => route('knowledge.defects.update', $defeitoId),
        'formMethod' => 'PUT',
        'formTitle' => 'Edição de defeito',
        'formSubtitle' => 'Revise nome, tipo de equipamento, classificação e status antes de salvar as alterações.',
        'submitLabel' => 'Salvar alterações',
        'cancelUrl' => route('knowledge.defects.index'),
    ])

    <section class="desktop-form-card mt-4">
        <div class="surface-card-header">
            <div>
                <h3 class="surface-title">Procedimentos de reparo</h3>
                <p class="surface-subtitle">Passo a passo sugerido para o reparo deste defeito.</p>
            </div>
        </div>

        <form method="post" action="{{ route('knowledge.defects.procedures.store', $defeitoId) }}" class="desktop-form-grid">
            @csrf
            <div class="col-span-full">
                <label for="procedimentoDescricao">Novo procedimento</label>
                <div class="d-flex gap-2">
                    <input
                        type="text"
                        id="procedimentoDescricao"
                        name="descricao"
                        class="form-control @error('descricao') is-invalid @enderror"
                        value="{{ old('descricao') }}"
                        maxlength="255"
                        placeholder="Descreva o passo de reparo"
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
                <h2 class="surface-title">Passos cadastrados</h2>
                <p class="surface-subtitle">Reordene ou remova os procedimentos conforme necessário.</p>
            </div>
        </div>

        @if ($procedimentos !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>Ordem</th>
                        <th>Descrição</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($procedimentos as $index => $procedimento)
                        @php
                            $procedimento = array_merge([
                                'id' => 0,
                                'descricao' => '',
                                'ordem' => 0,
                            ], is_array($procedimento) ? $procedimento : []);

                            $procedimentoId = (int) ($procedimento['id'] ?? 0);
                        @endphp
                        <tr>
                            <td data-label="Ordem">{{ $index + 1 }}</td>
                            <td data-label="Descrição">{{ $procedimento['descricao'] !== '' ? $procedimento['descricao'] : '—' }}</td>
                            <td data-label="Ações" class="text-end">
                                <div class="d-flex gap-1 justify-content-end">
                                    <form method="post" action="{{ route('knowledge.defects.procedures.move', [$defeitoId, $procedimentoId]) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="direction" value="up">
                                        <button type="submit" class="btn btn-sm btn-outline-light" title="Mover para cima" @disabled($index === 0)>
                                            <i class="bi bi-arrow-up"></i>
                                        </button>
                                    </form>

                                    <form method="post" action="{{ route('knowledge.defects.procedures.move', [$defeitoId, $procedimentoId]) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="direction" value="down">
                                        <button type="submit" class="btn btn-sm btn-outline-light" title="Mover para baixo" @disabled($index === count($procedimentos) - 1)>
                                            <i class="bi bi-arrow-down"></i>
                                        </button>
                                    </form>

                                    <form
                                        method="post"
                                        action="{{ route('knowledge.defects.procedures.destroy', [$defeitoId, $procedimentoId]) }}"
                                        data-confirm="Remover este procedimento?"
                                        data-confirm-title="Remover procedimento"
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
                'title' => 'Nenhum procedimento cadastrado',
                'message' => 'Adicione o primeiro passo de reparo para este defeito.',
            ])
        @endif
    </section>
@endsection
