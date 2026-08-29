@extends('layouts.app')

@section('content')
    @php
        $part = array_merge([
            'id' => null,
            'codigo' => '',
            'codigo_fabricante' => '',
            'nome' => '',
            'categoria' => '',
            'tipo_equipamento' => '',
            'modelos_compativeis' => '',
            'fornecedor' => '',
            'localizacao' => '',
            'preco_custo' => 0,
            'preco_venda' => 0,
            'quantidade_atual' => 0,
            'estoque_minimo' => 0,
            'estoque_maximo' => 0,
            'status' => 'ativo',
            'observacoes' => '',
            'ativo' => true,
            // Operacionais do PDV + preparação fiscal (specs/027-vendas-balcao-pdv).
            'codigo_barras' => '',
            'unidade' => 'UN',
            'ncm' => '',
            'cest' => '',
            'cfop_venda' => '',
            'origem_mercadoria' => '',
            'cst_icms' => '',
            'csosn' => '',
            'unidade_tributavel' => '',
        ], is_array($part ?? null) ? $part : []);
        $isEdit = (string) ($mode ?? 'create') === 'edit';
        $categorias = data_get($formData, 'categorias', []);
        $tiposEquipamento = data_get($formData, 'tipos_equipamento', []);
        $statusOptions = data_get($formData, 'status_options', []);
    @endphp

    <section class="desktop-form-card">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">{{ $isEdit ? 'Editar peça' : 'Nova peça' }}</h2>
                <p class="surface-subtitle">Cadastro operacional com suporte a estoque mínimo, tipo de equipamento e importação em lote.</p>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('estoque.help') }}" class="btn btn-outline-info">
                    <i class="bi bi-question-circle me-2"></i>Ajuda
                </a>
                <a href="{{ route('estoque.index') }}" class="btn btn-outline-light">
                    <i class="bi bi-arrow-left me-2"></i>Voltar
                </a>
            </div>
        </div>

        <form method="post" action="{{ $isEdit ? route('estoque.update', $part['id']) : route('estoque.store') }}" class="desktop-form-grid">
            @csrf
            @if ($isEdit)
                @method('PATCH')
            @endif

            <div>
                <label for="codigo">Código</label>
                <input type="text" id="codigo" name="codigo" class="form-control @error('codigo') is-invalid @enderror" value="{{ old('codigo', $part['codigo']) }}" maxlength="120" placeholder="Será sugerido se ficar em branco">
                @error('codigo')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div>
                <label for="codigo_fabricante">Código do fabricante</label>
                <input type="text" id="codigo_fabricante" name="codigo_fabricante" class="form-control @error('codigo_fabricante') is-invalid @enderror" value="{{ old('codigo_fabricante', $part['codigo_fabricante']) }}" maxlength="120">
                @error('codigo_fabricante')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-span-full">
                <label for="nome">Nome *</label>
                <input type="text" id="nome" name="nome" class="form-control @error('nome') is-invalid @enderror" value="{{ old('nome', $part['nome']) }}" maxlength="160" required>
                @error('nome')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div>
                <label for="categoria">Categoria</label>
                <input list="estoqueCategorias" type="text" id="categoria" name="categoria" class="form-control @error('categoria') is-invalid @enderror" value="{{ old('categoria', $part['categoria']) }}" maxlength="120">
                <datalist id="estoqueCategorias">
                    @foreach ($categorias as $categoria)
                        <option value="{{ $categoria }}"></option>
                    @endforeach
                </datalist>
                @error('categoria')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div>
                <label for="tipo_equipamento">Tipo de equipamento</label>
                <input list="estoqueTiposEquipamento" type="text" id="tipo_equipamento" name="tipo_equipamento" class="form-control @error('tipo_equipamento') is-invalid @enderror" value="{{ old('tipo_equipamento', $part['tipo_equipamento']) }}" maxlength="120">
                <datalist id="estoqueTiposEquipamento">
                    @foreach ($tiposEquipamento as $tipo)
                        <option value="{{ $tipo }}"></option>
                    @endforeach
                </datalist>
                @error('tipo_equipamento')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div>
                <label for="fornecedor">Fornecedor</label>
                <input type="text" id="fornecedor" name="fornecedor" class="form-control @error('fornecedor') is-invalid @enderror" value="{{ old('fornecedor', $part['fornecedor']) }}" maxlength="120">
                @error('fornecedor')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div>
                <label for="localizacao">Localização</label>
                <input type="text" id="localizacao" name="localizacao" class="form-control @error('localizacao') is-invalid @enderror" value="{{ old('localizacao', $part['localizacao']) }}" maxlength="120">
                @error('localizacao')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-span-full">
                <label for="modelos_compativeis">Modelos compatíveis</label>
                <textarea id="modelos_compativeis" name="modelos_compativeis" class="form-control @error('modelos_compativeis') is-invalid @enderror" rows="3" placeholder="Lista de modelos separados por vírgula">{{ old('modelos_compativeis', $part['modelos_compativeis']) }}</textarea>
                @error('modelos_compativeis')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div>
                <label for="preco_custo">Custo</label>
                <input type="number" id="preco_custo" name="preco_custo" class="form-control @error('preco_custo') is-invalid @enderror" value="{{ old('preco_custo', $part['preco_custo']) }}" min="0" step="0.01">
                @error('preco_custo')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div>
                <label for="preco_venda">Venda</label>
                <input type="number" id="preco_venda" name="preco_venda" class="form-control @error('preco_venda') is-invalid @enderror" value="{{ old('preco_venda', $part['preco_venda']) }}" min="0" step="0.01">
                @error('preco_venda')<div class="invalid-feedback">{{ $message }}</div>@enderror
                {{-- Preço sugerido (specs/037). Em peça nova com o campo vazio, a
                     sugestão preenche sozinha; havendo qualquer digitação, ela vira
                     dica com botão — nunca sobrescreve o que o operador escreveu. --}}
                <div class="form-text d-none" id="precoSugestao"></div>
            </div>

            <div>
                <label for="quantidade_atual">Quantidade atual</label>
                <input type="number" id="quantidade_atual" name="quantidade_atual" class="form-control @error('quantidade_atual') is-invalid @enderror" value="{{ old('quantidade_atual', $part['quantidade_atual']) }}" min="0" step="any">
                @error('quantidade_atual')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div>
                <label for="estoque_minimo">Estoque mínimo</label>
                <input type="number" id="estoque_minimo" name="estoque_minimo" class="form-control @error('estoque_minimo') is-invalid @enderror" value="{{ old('estoque_minimo', $part['estoque_minimo']) }}" min="0" step="any">
                @error('estoque_minimo')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div>
                <label for="estoque_maximo">Estoque máximo</label>
                <input type="number" id="estoque_maximo" name="estoque_maximo" class="form-control @error('estoque_maximo') is-invalid @enderror" value="{{ old('estoque_maximo', $part['estoque_maximo']) }}" min="0" step="any">
                @error('estoque_maximo')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div>
                <label for="status">Status</label>
                <select id="status" name="status" class="form-select @error('status') is-invalid @enderror">
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option['value'] ?? '' }}" @selected(old('status', $part['status']) === ($option['value'] ?? ''))>{{ $option['label'] ?? $option['value'] ?? '' }}</option>
                    @endforeach
                </select>
                @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-span-full">
                <label for="observacoes">Observações</label>
                <textarea id="observacoes" name="observacoes" class="form-control @error('observacoes') is-invalid @enderror" rows="4" placeholder="Observações, compatibilidades e detalhes operacionais">{{ old('observacoes', $part['observacoes']) }}</textarea>
                @error('observacoes')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-span-full">
                <label for="codigo_barras">Código de barras (EAN/GTIN)</label>
                <input type="text" id="codigo_barras" name="codigo_barras" class="form-control @error('codigo_barras') is-invalid @enderror" value="{{ old('codigo_barras', $part['codigo_barras'] ?? '') }}" maxlength="20" inputmode="numeric">
                <small class="text-secondary">Preenchido, o leitor do PDV encontra o produto direto pelo código.</small>
                @error('codigo_barras')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            {{-- Bloco fiscal recolhido: os campos existem para a emissão futura
                 (specs/027-vendas-balcao-pdv) e ainda não são usados por nada.
                 Aberto por padrão, só pesaria o cadastro do dia a dia. --}}
            <div class="col-span-full">
                <details class="border rounded p-3">
                    <summary class="fw-semibold" style="cursor: pointer;">
                        Dados fiscais (opcional)
                    </summary>

                    <p class="text-secondary small mt-2 mb-3">
                        O sistema ainda não emite nota fiscal. Estes campos ficam guardados
                        para quando a emissão for implementada.
                    </p>

                    <div class="row g-3">
                        <div class="col-6 col-md-3">
                            <label for="unidade">Unidade</label>
                            <input type="text" id="unidade" name="unidade" class="form-control" value="{{ old('unidade', $part['unidade'] ?? 'UN') }}" maxlength="6" placeholder="UN">
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="ncm">NCM</label>
                            <input type="text" id="ncm" name="ncm" class="form-control" value="{{ old('ncm', $part['ncm'] ?? '') }}" maxlength="8" inputmode="numeric">
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="cest">CEST</label>
                            <input type="text" id="cest" name="cest" class="form-control" value="{{ old('cest', $part['cest'] ?? '') }}" maxlength="7" inputmode="numeric">
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="cfop_venda">CFOP de venda</label>
                            <input type="text" id="cfop_venda" name="cfop_venda" class="form-control" value="{{ old('cfop_venda', $part['cfop_venda'] ?? '') }}" maxlength="4" inputmode="numeric">
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="origem_mercadoria">Origem</label>
                            <input type="text" id="origem_mercadoria" name="origem_mercadoria" class="form-control" value="{{ old('origem_mercadoria', $part['origem_mercadoria'] ?? '') }}" maxlength="1" inputmode="numeric">
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="cst_icms">CST ICMS</label>
                            <input type="text" id="cst_icms" name="cst_icms" class="form-control" value="{{ old('cst_icms', $part['cst_icms'] ?? '') }}" maxlength="3">
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="csosn">CSOSN</label>
                            <input type="text" id="csosn" name="csosn" class="form-control" value="{{ old('csosn', $part['csosn'] ?? '') }}" maxlength="4">
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="unidade_tributavel">Unidade tributável</label>
                            <input type="text" id="unidade_tributavel" name="unidade_tributavel" class="form-control" value="{{ old('unidade_tributavel', $part['unidade_tributavel'] ?? '') }}" maxlength="6">
                        </div>
                    </div>
                </details>
            </div>

            <div class="field-actions col-span-full">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="bi bi-save me-2"></i>
                    {{ $isEdit ? 'Salvar alterações' : 'Cadastrar peça' }}
                </button>
            </div>
        </form>
    </section>
@endsection

@section('scripts')
    <script>
        window.__DESKTOP_ESTOQUE_FORM = {
            sugerirPrecoUrl: @json(route('estoque.suggest-price')),
            csrf: @json(csrf_token()),
            // Modo edição: a peça já tem preço decidido, e a sugestão nunca
            // pode sobrescrevê-lo.
            edicao: @json(($mode ?? 'create') === 'edit'),
        };
    </script>
    <script src="{{ asset('assets/js/estoque-form.js') }}?v={{ filemtime(public_path('assets/js/estoque-form.js')) }}-{{ filesize(public_path('assets/js/estoque-form.js')) }}"></script>
@endsection
