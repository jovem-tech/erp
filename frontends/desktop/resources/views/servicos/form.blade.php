@extends('layouts.app')

@section('content')
    @php
        $service = array_merge([
            'id' => null,
            'nome' => '',
            'descricao' => '',
            'tipo_equipamento' => '',
            'valor' => 0,
            'tempo_padrao_horas' => 0,
            'custo_direto_padrao' => 0,
            'status' => 'ativo',
        ], is_array($service ?? null) ? $service : []);
        $statusOptions = data_get($formData, 'status_options', []);
        $tiposEquipamento = data_get($formData, 'tipos_equipamento', []);
        $isEdit = (string) ($mode ?? 'create') === 'edit';
    @endphp

    <section class="desktop-form-card">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">{{ $isEdit ? 'Editar serviço' : 'Novo serviço' }}</h2>
                <p class="surface-subtitle">Cadastro operacional com a mesma linguagem do legado, mas consumindo a API central do novo ERP.</p>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('servicos.help') }}" class="btn btn-outline-info">
                    <i class="bi bi-question-circle me-2"></i>Ajuda
                </a>
                <a href="{{ route('servicos.index') }}" class="btn btn-outline-light">
                    <i class="bi bi-arrow-left me-2"></i>Voltar
                </a>
            </div>
        </div>

        <form method="post" action="{{ $isEdit ? route('servicos.update', $service['id']) : route('servicos.store') }}" class="desktop-form-grid">
            @csrf
            @if ($isEdit)
                @method('PATCH')
            @endif

            <div>
                <label for="nome">Nome *</label>
                <input type="text" id="nome" name="nome" class="form-control @error('nome') is-invalid @enderror" value="{{ old('nome', $service['nome']) }}" maxlength="120" required>
                @error('nome')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div>
                <label for="tipo_equipamento">Tipo de equipamento</label>
                <input list="servicoTiposEquipamento" type="text" id="tipo_equipamento" name="tipo_equipamento" class="form-control @error('tipo_equipamento') is-invalid @enderror" value="{{ old('tipo_equipamento', $service['tipo_equipamento']) }}" maxlength="120" placeholder="Notebook, Desktop, Smartphone...">
                <datalist id="servicoTiposEquipamento">
                    @foreach ($tiposEquipamento as $tipo)
                        <option value="{{ $tipo }}"></option>
                    @endforeach
                </datalist>
                @error('tipo_equipamento')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-span-full">
                <label for="descricao">Descrição</label>
                <textarea id="descricao" name="descricao" class="form-control @error('descricao') is-invalid @enderror" rows="4" placeholder="Explique a atividade com clareza">{{ old('descricao', $service['descricao']) }}</textarea>
                @error('descricao')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div>
                <label for="valor">Valor</label>
                <input type="number" id="valor" name="valor" class="form-control @error('valor') is-invalid @enderror" value="{{ old('valor', $service['valor']) }}" min="0" step="0.01">
                @error('valor')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div>
                <label for="tempo_padrao_horas">Tempo padrão (horas)</label>
                <input type="number" id="tempo_padrao_horas" name="tempo_padrao_horas" class="form-control @error('tempo_padrao_horas') is-invalid @enderror" value="{{ old('tempo_padrao_horas', $service['tempo_padrao_horas']) }}" min="0" step="0.01">
                @error('tempo_padrao_horas')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div>
                <label for="custo_direto_padrao">Custo direto padrão</label>
                <input type="number" id="custo_direto_padrao" name="custo_direto_padrao" class="form-control @error('custo_direto_padrao') is-invalid @enderror" value="{{ old('custo_direto_padrao', $service['custo_direto_padrao']) }}" min="0" step="0.01">
                @error('custo_direto_padrao')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div>
                <label for="status">Status</label>
                <select id="status" name="status" class="form-select @error('status') is-invalid @enderror">
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option['value'] ?? '' }}" @selected(old('status', $service['status']) === ($option['value'] ?? ''))>{{ $option['label'] ?? $option['value'] ?? '' }}</option>
                    @endforeach
                </select>
                @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="field-actions col-span-full">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="bi bi-save me-2"></i>
                    {{ $isEdit ? 'Salvar alterações' : 'Cadastrar serviço' }}
                </button>
            </div>
        </form>
    </section>
@endsection
