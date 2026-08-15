@php
    $lancamento = $lancamento ?? [];
    $formMethod = strtoupper((string) ($formMethod ?? 'POST'));
    $tipoLocked = $tipoLocked ?? false;
    $tipo = $tipoLocked ? 'pagar' : old('tipo', $lancamento['tipo'] ?? 'receber');
    $status = old('status', $lancamento['status'] ?? 'pendente');
    $hasMovements = (int) ($resumo['total_movimentos'] ?? 0) > 0;
    $avulso = filter_var(old('avulso', $lancamento['avulso'] ?? false), FILTER_VALIDATE_BOOL);
    $canQuickClient = $canQuickClient ?? false;
    $selectedClienteId = (int) old('cliente_id', $lancamento['cliente_id'] ?? 0);
    $selectedClienteNome = trim((string) ($lancamento['client']['nome_razao'] ?? ''));
    $selectedClienteLabel = $selectedClienteNome !== ''
        ? $selectedClienteNome
        : ($selectedClienteId > 0 ? 'Cliente #' . $selectedClienteId : '');
    $selectedOsId = (int) old('os_id', $lancamento['os_id'] ?? 0);
    $selectedOsNumero = trim((string) ($lancamento['order']['numero_os'] ?? ''));
    $selectedOsLabel = $selectedOsNumero !== ''
        ? 'OS ' . $selectedOsNumero
        : ($selectedOsId > 0 ? 'OS #' . $selectedOsId : '');
    $selectedFornecedorId = (int) old('fornecedor_id', $lancamento['fornecedor_id'] ?? 0);
    $selectedFornecedorNome = trim((string) (
        $lancamento['supplier']['nome_fantasia']
        ?? $lancamento['supplier']['razao_social']
        ?? ''
    ));
    $selectedFornecedorLabel = $selectedFornecedorNome !== ''
        ? $selectedFornecedorNome
        : ($selectedFornecedorId > 0 ? 'Fornecedor #' . $selectedFornecedorId : '');
    $currentCategoria = old('categoria', (string) ($lancamento['categoria'] ?? ''));
    $catalogNomes = array_filter(array_map(fn($c) => (string) ($c['nome'] ?? ''), $categorias ?? []));
    // OS/Cliente só fazem sentido para "a pagar" quando a despesa é compra
    // de peça vinculada a uma OS (grupo "Custo Direto (OS)") — para
    // despesas operacionais genéricas (Energia, Água, Internet, Aluguel...)
    // não há relação nenhuma com OS/cliente.
    $categoriaAtualGrupoNome = collect($categorias ?? [])->firstWhere('nome', $currentCategoria)['dre_grupo']['nome'] ?? null;
    $isPecaCategoria = $categoriaAtualGrupoNome === 'Custo Direto (OS)';
    $hideOsCliente = $tipo === 'pagar' && ! $isPecaCategoria;
    // "Despesa fixa?" vem ANTES da categoria no fluxo (não depois): a
    // escolha aqui filtra quais categorias aparecem na Categoria logo
    // abaixo, em vez de a categoria definir um padrão que o usuário só
    // ajustaria depois.
    $dreFixoMensalValue = old('dre_fixo_mensal', isset($lancamento['dre_fixo_mensal'])
        ? ((bool) $lancamento['dre_fixo_mensal'] ? '1' : '0')
        : '');
    $valorRaw = old('valor', (string) ($lancamento['valor'] ?? ''));
    $defaultDataVencimento = old('data_vencimento') ?: ($lancamento['data_vencimento'] ?: date('Y-m-d'));
    $accountDataset = is_array($accountDataset ?? null) ? $accountDataset : [];
    $financialAccounts = array_values(array_filter(
        is_array($accountDataset['contas'] ?? null) ? $accountDataset['contas'] : [],
        static fn (array $account): bool => (bool) ($account['ativo'] ?? false)
    ));
@endphp

<section class="desktop-form-card">
    <div class="desktop-form-intro">
        <div class="desktop-form-intro-copy">
            <h2 class="surface-title mb-1">{{ $formTitle ?? 'Lançamento financeiro' }}</h2>
            <p class="surface-subtitle mb-0">
                {{ $formSubtitle ?? 'Título a receber ou a pagar, com classificação de DRE resolvida automaticamente pela categoria selecionada.' }}
            </p>
        </div>

        @if ($hasMovements)
            <span class="badge rounded-pill text-bg-warning">
                Já possui baixa registrada — tipo, cancelamento e redução de valor ficam bloqueados.
            </span>
        @endif
    </div>

    <form method="post" action="{{ $formAction }}" class="desktop-form-stack" id="financeiroForm">
        @csrf
        @if ($formMethod !== 'POST')
            @method($formMethod)
        @endif

        <div class="desktop-grid desktop-grid-four">
            <div>
                <label for="financeiroTipo">Tipo *</label>
                <select id="financeiroTipo" name="tipo" class="form-select" required @disabled($hasMovements || $tipoLocked)>
                    @unless ($tipoLocked)
                        <option value="receber" @selected($tipo === 'receber')>A receber</option>
                    @endunless
                    <option value="pagar" @selected($tipo === 'pagar')>A pagar</option>
                </select>
                @if ($tipoLocked)
                    <input type="hidden" name="tipo" value="pagar">
                    <small class="text-muted d-block mt-1">Esta tela é só para despesas. Para lançar um recebimento, use Lançamentos.</small>
                @endif
            </div>

            <div id="financeiroClassificacaoWrapper" @class(['d-none' => $tipo !== 'pagar'])>
                <label for="financeiroClassificacaoFixa">Despesa fixa?</label>
                <select id="financeiroClassificacaoFixa" name="dre_fixo_mensal" class="form-select">
                    <option value="" @selected($dreFixoMensalValue === '')>Todas as categorias</option>
                    <option value="1" @selected($dreFixoMensalValue === '1')>Despesa fixa</option>
                    <option value="0" @selected($dreFixoMensalValue === '0')>Despesa variável</option>
                </select>
                <small class="text-muted d-block mt-1">Filtra a Categoria abaixo para mostrar só as fixas ou só as variáveis.</small>
            </div>

            <div>
                <label for="financeiroValorDisplay">Valor *</label>
                <input
                    type="text"
                    id="financeiroValorDisplay"
                    class="form-control @error('valor') is-invalid @enderror"
                    placeholder="R$ 0,00"
                    inputmode="numeric"
                    autocomplete="off"
                    aria-describedby="financeiroValorHidden"
                    required
                >
                <input type="hidden" id="financeiroValorHidden" name="valor" value="{{ $valorRaw }}">
                @error('valor')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="desktop-grid">
            <div>
                <label for="financeiroCategoria">Categoria *</label>
                <select
                    id="financeiroCategoria"
                    name="categoria"
                    class="form-select @error('categoria') is-invalid @enderror"
                    data-native-select="true"
                    data-select2-placeholder="Ex.: Serviço, Aluguel, Energia..."
                    required
                >
                    <option value=""></option>
                    @if ($currentCategoria !== '' && !in_array($currentCategoria, $catalogNomes, true))
                        <option value="{{ $currentCategoria }}" selected>{{ $currentCategoria }}</option>
                    @endif
                    @foreach (($categorias ?? []) as $catOpt)
                        @php
                            $catNome = (string) ($catOpt['nome'] ?? '');
                            $catFixo = (bool) ($catOpt['dre_fixo_mensal_padrao'] ?? false);
                            $matchesClassificacao = $dreFixoMensalValue === ''
                                || $catNome === $currentCategoria
                                || ($dreFixoMensalValue === '1' && $catFixo)
                                || ($dreFixoMensalValue === '0' && ! $catFixo);
                        @endphp
                        @if ($catNome !== '' && $matchesClassificacao)
                            <option
                                value="{{ $catNome }}"
                                data-fixo="{{ $catFixo ? '1' : '0' }}"
                                @selected($currentCategoria === $catNome)
                            >{{ $catNome }}</option>
                        @endif
                    @endforeach
                </select>
                @error('categoria')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                <small class="text-muted d-block mt-1">A categoria define automaticamente o grupo e subgrupo do DRE (máx. 50 caracteres).</small>
            </div>
        </div>

        @if (empty($lancamento['id']))
            <div id="financeiroRepetirWrapper" class="form-check" @class(['d-none' => $dreFixoMensalValue !== '1'])>
                <input
                    type="checkbox"
                    id="financeiroRepetirMeses"
                    name="repetir_proximos_meses"
                    class="form-check-input"
                    value="1"
                    @checked(filter_var(old('repetir_proximos_meses', false), FILTER_VALIDATE_BOOL))
                >
                <label class="form-check-label" for="financeiroRepetirMeses">Repetir esta despesa nos próximos 12 meses</label>
                <small class="text-muted d-block">
                    Cria automaticamente mais 11 lançamentos (um por mês, mesmo dia de vencimento, status pendente).
                    O valor inicial é repetido, mas cada mês pode ser editado depois — útil para contas como água/luz, que variam de valor.
                </small>
            </div>
        @endif

        <div class="desktop-grid">
            <div>
                <label for="financeiroDescricao">Descrição *</label>
                <input type="text" id="financeiroDescricao" name="descricao" class="form-control" maxlength="255" value="{{ old('descricao', $lancamento['descricao'] ?? '') }}" placeholder="Ex.: OS OS20260001, Aluguel referente a junho..." required>
            </div>
        </div>

        <div class="desktop-form-section">
            <div class="desktop-form-section-title">
                <i class="bi bi-calendar-event"></i>
                <span>DATAS E STATUS</span>
            </div>

            <div class="desktop-grid desktop-grid-three">
                <div>
                    <label for="financeiroDataVencimento">Data de vencimento *</label>
                    <input type="date" id="financeiroDataVencimento" name="data_vencimento" class="form-control" value="{{ $defaultDataVencimento }}" @if(empty($lancamento['id'])) data-set-today="1" @endif required>
                </div>

                <div>
                    <label for="financeiroStatus">Status</label>
                    <select id="financeiroStatus" name="status" class="form-select">
                        <option value="pendente" @selected($status === 'pendente')>Pendente</option>
                        <option value="parcial" @selected($status === 'parcial') @disabled(! $hasMovements)>Parcial</option>
                        <option value="pago" @selected($status === 'pago')>Pago</option>
                        <option value="cancelado" @selected($status === 'cancelado') @disabled($hasMovements)>Cancelado</option>
                    </select>
                    <small class="text-muted d-block mt-1">Selecionar "Pago" sem baixa registrada gera a baixa total automaticamente.</small>
                </div>

                <div>
                    <label for="financeiroFormaPagamento">Forma de pagamento</label>
                    @php $formaPagamento = old('forma_pagamento', $lancamento['forma_pagamento'] ?? ''); @endphp
                    {{--
                        Este campo grava na coluna-resumo do título, que é um ENUM fixo
                        no banco legado — por isso lista só as formas compatíveis com ele.
                        As formas personalizadas do cadastro aparecem na baixa/recebimento,
                        que é onde o valor realmente entra.
                    --}}
                    <select id="financeiroFormaPagamento" name="forma_pagamento" class="form-select">
                        <option value="" @selected($formaPagamento === '')>Não informado</option>
                        @foreach (($formasPagamento ?? []) as $forma)
                            @continue(! ($forma['ativo'] ?? true) || ! ($forma['resumo_enum'] ?? false))
                            <option value="{{ $forma['codigo'] }}" @selected($formaPagamento === $forma['codigo'])>{{ $forma['nome'] }}</option>
                        @endforeach
                    </select>
                    <p class="small text-secondary mt-1 mb-0">Formas personalizadas ficam disponíveis na hora da baixa.</p>
                </div>

                @if ($financialAccounts !== [] && ! $hasMovements)
                    <div id="financeiroContaWrapper" @class(['d-none' => $status !== 'pago'])>
                        <label for="financeiroConta">Conta financeira *</label>
                        <select id="financeiroConta" name="conta_financeira_id" class="form-select" @required($status === 'pago')>
                            <option value="">Selecione onde o valor entra ou sai</option>
                            @foreach ($financialAccounts as $account)
                                <option value="{{ (int) $account['id'] }}" @selected((int) old('conta_financeira_id', 0) === (int) $account['id'])>{{ $account['nome'] }}</option>
                            @endforeach
                        </select>
                        <small class="text-muted d-block mt-1">Obrigatória quando o título já for criado como pago.</small>
                    </div>
                @endif
            </div>
        </div>

        <div class="desktop-form-section" id="financeiroVinculosSection" @class(['d-none' => $dreFixoMensalValue === '1'])>
            <div class="desktop-form-section-title">
                <i class="bi bi-link-45deg"></i>
                <span>VÍNCULOS</span>
            </div>

            <div class="form-check form-switch mb-3">
                <input type="hidden" name="avulso" value="{{ $hasMovements && $avulso ? 1 : 0 }}">
                <input
                    type="checkbox"
                    id="financeiroAvulso"
                    name="avulso"
                    class="form-check-input"
                    value="1"
                    @checked($avulso)
                    @disabled($hasMovements)
                >
                <label class="form-check-label fw-semibold" for="financeiroAvulso">Lançamento avulso</label>
                <small class="text-muted d-block">
                    Permite registrar pagamentos ou recebimentos simples sem ordem de serviço. O cliente é opcional.
                </small>
            </div>

            <div class="desktop-grid desktop-grid-three">
                <div id="financeiroOsWrapper" @class(['d-none' => $hideOsCliente])>
                    <label for="financeiroOsId">OS vinculada</label>
                    <select
                        id="financeiroOsId"
                        name="os_id"
                        class="form-select @error('os_id') is-invalid @enderror"
                        data-native-select="true"
                        data-select2-placeholder="Buscar OS pelo número..."
                    >
                        <option value=""></option>
                        @if ($selectedOsId > 0)
                            <option value="{{ $selectedOsId }}" selected>{{ $selectedOsLabel }}</option>
                        @endif
                    </select>
                    @error('os_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    <small id="financeiroOsHelp" class="text-muted d-block mt-1">Busque pelo número da OS (só aparecem OS em aberto). Selecionar uma OS preenche o cliente automaticamente e desmarca o lançamento avulso.</small>
                </div>

                <div id="financeiroClienteWrapper" @class(['d-none' => $hideOsCliente])>
                    <label for="financeiroClienteId">Cliente</label>
                    <div class="d-flex gap-2 align-items-start">
                        <select
                            id="financeiroClienteId"
                            name="cliente_id"
                            class="form-select @error('cliente_id') is-invalid @enderror"
                            data-native-select="true"
                            data-select2-placeholder="Buscar cliente pelo nome..."
                        >
                            <option value=""></option>
                            @if ($selectedClienteId > 0)
                                <option value="{{ $selectedClienteId }}" selected>
                                    {{ $selectedClienteLabel !== '' ? $selectedClienteLabel : 'Cliente #' . $selectedClienteId }}
                                </option>
                            @endif
                        </select>
                        @if ($canQuickClient)
                            <button
                                type="button"
                                id="btnNovoClienteFinanceiro"
                                class="btn btn-soft flex-shrink-0"
                                title="Cadastrar novo cliente"
                                aria-label="Cadastrar novo cliente"
                            >
                                <i class="bi bi-person-plus"></i>
                            </button>
                        @endif
                    </div>
                    @error('cliente_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    <small class="text-muted d-block mt-1">Opcional no avulso. Quando informado, o recebimento aparece no histórico financeiro do cliente.</small>
                </div>

                <div id="financeiroFornecedorWrapper" @class(['d-none' => $tipo === 'receber'])>
                    <label for="financeiroFornecedorId">Fornecedor</label>
                    <select
                        id="financeiroFornecedorId"
                        name="fornecedor_id"
                        class="form-select @error('fornecedor_id') is-invalid @enderror"
                        data-native-select="true"
                        data-select2-placeholder="Buscar fornecedor pelo nome..."
                    >
                        <option value=""></option>
                        @if ($selectedFornecedorId > 0)
                            <option value="{{ $selectedFornecedorId }}" selected>{{ $selectedFornecedorLabel }}</option>
                        @endif
                    </select>
                    @error('fornecedor_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    <small class="text-muted d-block mt-1">Toda conta a pagar deve estar vinculada a um fornecedor.</small>
                </div>
            </div>
        </div>

        <div class="desktop-form-section">
            <div class="desktop-form-section-title">
                <i class="bi bi-card-text"></i>
                <span>OBSERVAÇÕES</span>
            </div>

            <textarea name="observacoes" class="form-control" rows="3">{{ old('observacoes', $lancamento['observacoes'] ?? '') }}</textarea>
        </div>

        <div class="desktop-form-actions">
            <a href="{{ $cancelUrl }}" class="btn btn-outline-light">Cancelar</a>
            <button type="submit" class="btn btn-primary">{{ $submitLabel ?? 'Salvar lançamento' }}</button>
        </div>
    </form>
</section>

<script>
    {{-- Avulso/OS/fornecedor coordination lives in financeiro-form.js (initVinculos),
         since it now needs to talk to the OS/fornecedor select2 ajax widgets. --}}
    (() => {
        const dateInput = document.getElementById('financeiroDataVencimento');

        if (dateInput && dateInput.dataset.setToday === '1') {
            const now = new Date();
            const yyyy = now.getFullYear();
            const mm = String(now.getMonth() + 1).padStart(2, '0');
            const dd = String(now.getDate()).padStart(2, '0');
            dateInput.value = `${yyyy}-${mm}-${dd}`;
        }
    })();
</script>

@if ($canQuickClient)
    @push('modals')
        @include('clients.quick-modal')
    @endpush
@endif
