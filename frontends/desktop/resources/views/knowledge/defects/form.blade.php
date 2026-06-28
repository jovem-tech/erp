@php
    $defeito = $defeito ?? [];
    $formMethod = strtoupper((string) ($formMethod ?? 'POST'));
    $tipoId = old('tipo_id', $defeito['tipo_id'] ?? '');
    $classificacao = old('classificacao', $defeito['classificacao'] ?? 'hardware');
    $ativo = old('ativo', $defeito['ativo'] ?? true);
@endphp

<section class="desktop-form-card">
    <div class="desktop-form-intro">
        <div class="desktop-form-intro-copy">
            <h2 class="surface-title mb-1">{{ $formTitle ?? 'Cadastro de defeito' }}</h2>
            <p class="surface-subtitle mb-0">
                {{ $formSubtitle ?? 'Mantenha o catálogo de defeitos comuns organizado por tipo de equipamento e classificação.' }}
            </p>
        </div>
    </div>

    <form
        method="post"
        action="{{ $formAction }}"
        class="desktop-form-stack"
        id="defectForm"
    >
        @csrf
        @if ($formMethod !== 'POST')
            @method($formMethod)
        @endif

        <div class="desktop-form-section">
            <div class="desktop-form-section-title">
                <i class="bi bi-tags"></i>
                <span>CLASSIFICAÇÃO</span>
            </div>

            <div class="desktop-grid desktop-grid-three">
                <div>
                    <label for="defectNome">Nome *</label>
                    <input type="text" id="defectNome" name="nome" class="form-control @error('nome') is-invalid @enderror" value="{{ old('nome', $defeito['nome'] ?? '') }}" maxlength="150" required>
                    @error('nome')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div>
                    <label for="defectTipo">Tipo de equipamento *</label>
                    <select id="defectTipo" name="tipo_id" class="form-select @error('tipo_id') is-invalid @enderror" required>
                        <option value="">Selecione um tipo</option>
                        @foreach ($equipmentTypes as $type)
                            <option value="{{ $type['id'] }}" @selected((string) $tipoId === (string) $type['id'])>
                                {{ $type['nome'] }}
                            </option>
                        @endforeach
                    </select>
                    @error('tipo_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div>
                    <label for="defectClassificacao">Classificação *</label>
                    <select id="defectClassificacao" name="classificacao" class="form-select @error('classificacao') is-invalid @enderror" required>
                        <option value="hardware" @selected($classificacao === 'hardware')>Hardware</option>
                        <option value="software" @selected($classificacao === 'software')>Software</option>
                    </select>
                    @error('classificacao')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        <div class="desktop-form-section">
            <div class="desktop-form-section-title">
                <i class="bi bi-journal-text"></i>
                <span>DESCRIÇÃO</span>
            </div>

            <div>
                <label for="defectDescricao">Descrição</label>
                <textarea id="defectDescricao" name="descricao" class="form-control @error('descricao') is-invalid @enderror" rows="4" placeholder="Detalhes sobre este defeito comum">{{ old('descricao', $defeito['descricao'] ?? '') }}</textarea>
                @error('descricao')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="form-check form-switch mt-3">
                <input type="hidden" name="ativo" value="0">
                <input class="form-check-input" type="checkbox" name="ativo" id="defectAtivo" value="1" @checked((bool) $ativo)>
                <label class="form-check-label" for="defectAtivo">Defeito ativo no sistema</label>
            </div>
        </div>

        <div class="desktop-form-actions">
            <a href="{{ $cancelUrl ?? route('knowledge.defects.index') }}" class="btn btn-outline-light">Cancelar</a>
            <button type="submit" class="btn btn-primary">{{ $submitLabel ?? 'Salvar defeito' }}</button>
        </div>
    </form>
</section>
