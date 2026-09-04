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

        <form method="post" action="{{ $isEdit ? route('servicos.update', $service['id']) : route('servicos.store') }}" class="desktop-grid desktop-grid-two">
            @csrf
            @if ($isEdit)
                @method('PATCH')
            @endif

            <div class="desktop-grid-span-2">
                <p class="desktop-eyebrow">Identificação</p>
            </div>

            <div class="desktop-grid-span-2">
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

            <div class="desktop-grid-span-2">
                <label for="descricao">Descrição</label>
                <textarea id="descricao" name="descricao" class="form-control @error('descricao') is-invalid @enderror" rows="3" placeholder="Explique a atividade com clareza">{{ old('descricao', $service['descricao']) }}</textarea>
                @error('descricao')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="desktop-grid-span-2 mt-2 pt-2 border-top">
                <p class="desktop-eyebrow">Precificação e execução</p>
            </div>

            <div class="desktop-grid-span-2 desktop-grid desktop-grid-three">
                <div>
                    <label for="valor">Valor</label>
                    <input type="number" id="valor" name="valor" class="form-control @error('valor') is-invalid @enderror" value="{{ old('valor', $service['valor']) }}" min="0" step="0.01">
                    @error('valor')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    {{-- Preço sugerido (specs/037). Mesma regra do cadastro de peça:
                         preenche o campo vazio, nunca sobrescreve digitação. --}}
                    <div class="form-text d-none" id="precoSugestao"></div>
                </div>

                <div>
                    <label for="tempo_padrao_horas">Tempo padrão (horas)</label>
                    <input type="number" id="tempo_padrao_horas" name="tempo_padrao_horas" class="form-control @error('tempo_padrao_horas') is-invalid @enderror" value="{{ old('tempo_padrao_horas', $service['tempo_padrao_horas']) }}" min="0" step="0.01">
                    @error('tempo_padrao_horas')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div>
                    <label for="custo_direto_padrao">Custo de materiais por execução</label>
                    <input type="number" id="custo_direto_padrao" name="custo_direto_padrao" class="form-control @error('custo_direto_padrao') is-invalid @enderror" value="{{ old('custo_direto_padrao', $service['custo_direto_padrao']) }}" min="0" step="0.01">
                    @error('custo_direto_padrao')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            {{-- Nada dizia ao operador se mão de obra entrava no custo direto, e
                 metade dos cadastros a incluía — o que dupla-contaria contra
                 tempo × custo-hora. O rótulo é a correção (specs/037). --}}
            <div class="desktop-grid-span-2">
                <p class="form-text mb-0">
                    Só materiais e consumíveis (pasta térmica, cola, fita). <strong>Não inclua mão de obra</strong>
                    — ela é calculada a partir do tempo padrão × custo-hora.
                </p>
            </div>

            {{-- Cadeia de custo: é literalmente a saída que o motor já produz.
                 Torna `tempo_padrao_horas` um campo vivo — até aqui ele existia
                 e nenhum cálculo real o lia. --}}
            <div class="desktop-grid-span-2">
                <div class="surface-card p-3 d-none" id="cadeiaCusto"></div>
            </div>

            <div class="desktop-grid-span-2 mt-2 pt-2 border-top">
                <p class="desktop-eyebrow">Configuração</p>
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

            {{-- Dados fiscais: mesmo formato da aba fiscal da peca (027), fechado
                 por padrao. Aberto pesaria o cadastro do dia a dia sem motivo,
                 porque sao campos que se preenchem uma vez. --}}
            <div class="desktop-grid-span-2">
                <details class="border rounded p-3">
                    <summary class="fw-semibold" style="cursor: pointer;">
                        Dados fiscais (opcional)
                    </summary>

                    <p class="text-secondary small mt-2 mb-3">
                        A NFS-e deste serviço já pode ser emitida pelo sistema em
                        <a href="{{ route('fiscal.pendentes') }}">Fiscal &rsaquo; Notas pendentes</a>,
                        no modo assistido: o sistema monta a nota, você emite no portal do
                        gov.br e registra o retorno. Estes campos ainda não são usados —
                        eles preparam a integração automática, e o código de tributação
                        precisa de confirmação do contador.
                    </p>

                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label for="codigo_tributacao_nacional">Código de tributação nacional</label>
                            <input type="text" id="codigo_tributacao_nacional" name="codigo_tributacao_nacional" class="form-control @error('codigo_tributacao_nacional') is-invalid @enderror" value="{{ old('codigo_tributacao_nacional', $service['codigo_tributacao_nacional'] ?? '') }}" maxlength="20">
                            @error('codigo_tributacao_nacional')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="item_lc116">Item da LC 116</label>
                            <input type="text" id="item_lc116" name="item_lc116" class="form-control @error('item_lc116') is-invalid @enderror" value="{{ old('item_lc116', $service['item_lc116'] ?? '') }}" maxlength="10">
                            @error('item_lc116')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="aliquota_iss">Alíquota de ISS (%)</label>
                            <input type="number" id="aliquota_iss" name="aliquota_iss" class="form-control @error('aliquota_iss') is-invalid @enderror" value="{{ old('aliquota_iss', $service['aliquota_iss'] ?? '') }}" min="0" max="100" step="0.01" placeholder="deixe vazio se não souber">
                            @error('aliquota_iss')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6 col-md-2">
                            <label for="unidade">Unidade</label>
                            <input type="text" id="unidade" name="unidade" class="form-control @error('unidade') is-invalid @enderror" value="{{ old('unidade', $service['unidade'] ?? '') }}" maxlength="6" placeholder="UN">
                            @error('unidade')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </details>
            </div>

            <div class="field-actions desktop-grid-span-2 mt-2 pt-2 border-top">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="bi bi-save me-2"></i>
                    {{ $isEdit ? 'Salvar alterações' : 'Cadastrar serviço' }}
                </button>
            </div>
        </form>
    </section>
@endsection

@section('scripts')
    <script>
        window.__DESKTOP_SERVICO_FORM = {
            sugerirPrecoUrl: @json(route('servicos.suggest-price')),
            csrf: @json(csrf_token()),
            edicao: @json(($mode ?? 'create') === 'edit'),
        };
    </script>
    <script src="{{ asset('assets/js/servicos-form.js') }}?v={{ filemtime(public_path('assets/js/servicos-form.js')) }}-{{ filesize(public_path('assets/js/servicos-form.js')) }}"></script>
@endsection
