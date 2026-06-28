@php
    $defeito = $defeito ?? [];
    $formMethod = strtoupper((string) ($formMethod ?? 'POST'));
    $tipoEquipamentoId = old('tipo_equipamento_id', $defeito['tipo_equipamento_id'] ?? '');
    $ativo = old('ativo', $defeito['ativo'] ?? true);
@endphp

<section class="desktop-form-card">
    <div class="desktop-form-intro">
        <div class="desktop-form-intro-copy">
            <h2 class="surface-title mb-1">{{ $formTitle ?? 'Cadastro de defeito relatado' }}</h2>
            <p class="surface-subtitle mb-0">
                {{ $formSubtitle ?? 'Mantenha o catálogo de relatos de defeito organizado por tipo de equipamento, categoria e subcategoria.' }}
            </p>
        </div>
    </div>

    <form
        method="post"
        action="{{ $formAction }}"
        class="desktop-form-stack"
        id="reportedDefectForm"
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
                    <label for="defeitoTipoEquipamento">Tipo de equipamento</label>
                    <select id="defeitoTipoEquipamento" name="tipo_equipamento_id" class="form-select">
                        <option value="">Sem tipo específico</option>
                        @foreach ($equipmentTypes as $type)
                            <option value="{{ $type['id'] }}" @selected((string) $tipoEquipamentoId === (string) $type['id'])>
                                {{ $type['nome'] }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="defeitoCategoria">Categoria *</label>
                    <input type="text" id="defeitoCategoria" name="categoria" class="form-control" value="{{ old('categoria', $defeito['categoria'] ?? '') }}" maxlength="80" required>
                </div>

                <div>
                    <label for="defeitoSubcategoria">Subcategoria</label>
                    <input type="text" id="defeitoSubcategoria" name="subcategoria" class="form-control" value="{{ old('subcategoria', $defeito['subcategoria'] ?? '') }}" maxlength="80">
                </div>
            </div>
        </div>

        <div class="desktop-form-section">
            <div class="desktop-form-section-title">
                <i class="bi bi-chat-square-text"></i>
                <span>CONTEÚDO</span>
            </div>

            <div class="desktop-grid desktop-grid-two">
                <div style="grid-column: span 2;">
                    <label for="defeitoTextoRelato">Relato *</label>
                    <textarea id="defeitoTextoRelato" name="texto_relato" class="form-control" rows="3" maxlength="255" required>{{ old('texto_relato', $defeito['texto_relato'] ?? '') }}</textarea>
                </div>

                <div>
                    <label for="defeitoIcone">Ícone</label>
                    <input type="text" id="defeitoIcone" name="icone" class="form-control" value="{{ old('icone', $defeito['icone'] ?? '') }}" maxlength="20" placeholder="bi-phone">
                    <div class="form-text">
                        Use um slug de ícone Bootstrap (ex.: bi-phone, bi-laptop, bi-battery-half).
                    </div>
                </div>

                <div>
                    <label for="defeitoOrdemExibicao">Ordem de exibição</label>
                    <input type="number" id="defeitoOrdemExibicao" name="ordem_exibicao" class="form-control" min="0" value="{{ old('ordem_exibicao', $defeito['ordem_exibicao'] ?? 0) }}">
                </div>
            </div>
        </div>

        <div class="desktop-form-section">
            <div class="desktop-form-section-title">
                <i class="bi bi-journal-text"></i>
                <span>OBSERVAÇÕES</span>
            </div>

            <div>
                <label for="defeitoObservacoes">Observações</label>
                <textarea id="defeitoObservacoes" name="observacoes" class="form-control" rows="4" placeholder="Observações internas sobre este relato de defeito">{{ old('observacoes', $defeito['observacoes'] ?? '') }}</textarea>
            </div>

            <div class="form-check form-switch mt-3">
                <input type="hidden" name="ativo" value="0">
                <input class="form-check-input" type="checkbox" name="ativo" id="defeitoAtivo" value="1" @checked((bool) $ativo)>
                <label class="form-check-label" for="defeitoAtivo">Defeito relatado ativo no sistema</label>
            </div>
        </div>

        <div class="desktop-form-actions">
            <a href="{{ $cancelUrl ?? route('knowledge.reported-defects.index') }}" class="btn btn-outline-light">Cancelar</a>
            <button type="submit" class="btn btn-primary">{{ $submitLabel ?? 'Salvar defeito relatado' }}</button>
        </div>
    </form>
</section>
