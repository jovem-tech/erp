@php
    $defeito = $defeito ?? [];
    $formMethod = strtoupper((string) ($formMethod ?? 'POST'));
    $tipoEquipamentoId = old('tipo_equipamento_id', $defeito['tipo_equipamento_id'] ?? '');
    $ativo = old('ativo', $defeito['ativo'] ?? true);

    $categoryMap = is_array($categoryMap ?? null) ? $categoryMap : [];
    $categoriaAtual = trim((string) old('categoria', $defeito['categoria'] ?? ''));
    $subcategoriaAtual = trim((string) old('subcategoria', $defeito['subcategoria'] ?? ''));

    if ($categoriaAtual !== '' && ! array_key_exists($categoriaAtual, $categoryMap)) {
        $categoryMap[$categoriaAtual] = [];
    }

    if ($categoriaAtual !== '' && $subcategoriaAtual !== '' && ! in_array($subcategoriaAtual, $categoryMap[$categoriaAtual], true)) {
        $categoryMap[$categoriaAtual][] = $subcategoriaAtual;
    }
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
                    <select id="defeitoCategoria" name="categoria" class="form-select" required data-native-select="true">
                        <option value="">Selecione...</option>
                        @foreach (array_keys($categoryMap) as $categoriaOption)
                            <option value="{{ $categoriaOption }}" @selected($categoriaAtual === $categoriaOption)>{{ $categoriaOption }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">Escolha uma categoria existente ou digite uma nova.</div>
                </div>

                <div>
                    <label for="defeitoSubcategoria">Subcategoria</label>
                    <select id="defeitoSubcategoria" name="subcategoria" class="form-select" data-native-select="true" data-current-subcategoria="{{ $subcategoriaAtual }}">
                        <option value="">Nenhuma / Geral</option>
                    </select>
                    <div class="form-text">Escolha uma subcategoria existente ou digite uma nova.</div>
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

@section('scripts')
    <script>
        (function () {
            const categoryMap = @json($categoryMap);
            const categoriaSelect = document.getElementById('defeitoCategoria');
            const subcategoriaSelect = document.getElementById('defeitoSubcategoria');

            if (!categoriaSelect || !subcategoriaSelect || typeof window.jQuery === 'undefined' || !window.jQuery.fn || typeof window.jQuery.fn.select2 !== 'function') {
                return;
            }

            const $ = window.jQuery;

            const select2Options = (select, placeholder) => ({
                theme: 'bootstrap-5',
                width: '100%',
                tags: true,
                allowClear: select.querySelector('option[value=""]') !== null,
                placeholder,
                language: {
                    noResults: () => 'Nenhum resultado encontrado',
                    searching: () => 'Buscando...',
                    inputTooShort: () => 'Digite para buscar ou cadastrar um novo valor',
                },
            });

            $(categoriaSelect).select2(select2Options(categoriaSelect, 'Selecione ou digite uma categoria'));
            $(subcategoriaSelect).select2(select2Options(subcategoriaSelect, 'Selecione ou digite uma subcategoria'));

            const populateSubcategoryOptions = (categoria, selectedValue) => {
                const subcategorias = Array.isArray(categoryMap[categoria]) ? categoryMap[categoria] : [];

                subcategoriaSelect.innerHTML = '';

                const emptyOption = document.createElement('option');
                emptyOption.value = '';
                emptyOption.textContent = 'Nenhuma / Geral';
                subcategoriaSelect.appendChild(emptyOption);

                subcategorias.forEach((subcategoria) => {
                    const option = document.createElement('option');
                    option.value = subcategoria;
                    option.textContent = subcategoria;
                    subcategoriaSelect.appendChild(option);
                });

                const valueToSelect = subcategorias.includes(selectedValue) ? selectedValue : '';
                $(subcategoriaSelect).val(valueToSelect).trigger('change.select2');
            };

            $(categoriaSelect).on('change', () => {
                const categoria = categoriaSelect.value;

                if (categoria !== '' && !Array.isArray(categoryMap[categoria])) {
                    categoryMap[categoria] = [];
                }

                populateSubcategoryOptions(categoria, '');
            });

            $(subcategoriaSelect).on('select2:select', (event) => {
                const categoria = categoriaSelect.value;
                const subcategoria = event.params?.data?.id || '';

                if (categoria === '' || subcategoria === '') {
                    return;
                }

                if (!Array.isArray(categoryMap[categoria])) {
                    categoryMap[categoria] = [];
                }

                if (!categoryMap[categoria].includes(subcategoria)) {
                    categoryMap[categoria].push(subcategoria);
                }
            });

            populateSubcategoryOptions(categoriaSelect.value, subcategoriaSelect.dataset.currentSubcategoria || '');
        })();
    </script>
@endsection
