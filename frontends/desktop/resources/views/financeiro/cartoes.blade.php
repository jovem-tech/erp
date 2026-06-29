@extends('layouts.app')

@php
    use App\Support\DesktopSession;

    $cartoes = is_array($cartoes ?? null) ? $cartoes : [];
    $gateway = is_array($gateway ?? null) ? $gateway : [];
    $summary = is_array($cartoes['summary'] ?? null) ? $cartoes['summary'] : [];
    $operadoras = is_array($cartoes['operadoras'] ?? null) ? $cartoes['operadoras'] : [];
    $bandeiras = is_array($cartoes['bandeiras'] ?? null) ? $cartoes['bandeiras'] : [];
    $taxas = is_array($cartoes['taxas'] ?? null) ? $cartoes['taxas'] : [];
    $simuladorCatalogo = is_array($cartoes['simulador_catalogo'] ?? null) ? $cartoes['simulador_catalogo'] : [];
    $gatewayCatalog = is_array($gateway['gateway_catalog'] ?? null) ? $gateway['gateway_catalog'] : [];
    $gatewayTaxas = is_array($gateway['gateway_taxas'] ?? null) ? $gateway['gateway_taxas'] : [];
    $gatewaySummary = is_array($gateway['gateway_summary'] ?? null) ? $gateway['gateway_summary'] : [];
    $activeTab = in_array((string) ($activeTab ?? 'operadoras'), ['operadoras', 'bandeiras', 'taxas', 'simulador', 'gateway'], true)
        ? (string) $activeTab
        : 'operadoras';
    $canEdit = DesktopSession::can('financeiro', 'editar');
    $canDelete = DesktopSession::can('financeiro', 'excluir');
    $activeProvider = old('provider', array_key_first($gatewayCatalog) ?? 'asaas');
    $providerModes = is_array($gatewayCatalog[$activeProvider]['modes'] ?? null) ? $gatewayCatalog[$activeProvider]['modes'] : [];
    $firstActiveOperadora = collect($operadoras)->first(static fn (array $row): bool => (bool) ($row['ativo'] ?? false));
    $firstActiveBandeira = collect($bandeiras)->first(static fn (array $row): bool => (bool) ($row['ativo'] ?? false));
    $firstActiveTaxaOperadora = collect($operadoras)->first(static fn (array $row): bool => (bool) ($row['ativo'] ?? false));
    $firstSimuladorModalidade = old('modalidade', 'credito');
    $tabs = [
        'operadoras' => ['label' => 'Operadora de maquininha', 'icon' => 'bi-cash-coin'],
        'bandeiras' => ['label' => 'Bandeiras', 'icon' => 'bi-credit-card'],
        'taxas' => ['label' => 'Taxa por parcela', 'icon' => 'bi-percent'],
        'simulador' => ['label' => 'Simulador de faturamento líquido', 'icon' => 'bi-calculator'],
        'gateway' => ['label' => 'Taxas online', 'icon' => 'bi-globe2'],
    ];
    $formatMoney = static fn (float $value): string => 'R$ ' . number_format($value, 2, ',', '.');
@endphp

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Financeiro</p>
            <h2 class="surface-title fs-3 mb-2">Cartões e Taxas</h2>
            <p class="surface-subtitle mb-0">
                Cadastre operadoras, bandeiras, faixas de parcelas e taxas online com o mesmo fluxo operacional do legado.
                Todos os selects visíveis seguem o padrão Select2 do desktop.
            </p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('financeiro.index') }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>
                Voltar aos lançamentos
            </a>
            <a href="{{ route('financeiro.cartoes.help') }}" class="btn btn-outline-info">
                <i class="bi bi-question-circle me-2"></i>
                Ajuda
            </a>
        </div>
    </div>

    <div class="desktop-grid desktop-grid-four mb-4">
        <div class="summary-card">
            <p class="summary-card-eyebrow">Operadoras</p>
            <h3 class="summary-card-value">{{ number_format((int) ($summary['operadoras_total'] ?? 0), 0, ',', '.') }}</h3>
            <p class="summary-card-meta">
                {{ number_format((int) ($summary['operadoras_ativas'] ?? 0), 0, ',', '.') }} ativas
            </p>
        </div>
        <div class="summary-card">
            <p class="summary-card-eyebrow">Bandeiras</p>
            <h3 class="summary-card-value">{{ number_format((int) ($summary['bandeiras_total'] ?? 0), 0, ',', '.') }}</h3>
            <p class="summary-card-meta">
                {{ number_format((int) ($summary['bandeiras_ativas'] ?? 0), 0, ',', '.') }} ativas
            </p>
        </div>
        <div class="summary-card">
            <p class="summary-card-eyebrow">Taxas cadastradas</p>
            <h3 class="summary-card-value">{{ number_format((int) ($summary['taxas_total'] ?? 0), 0, ',', '.') }}</h3>
            <p class="summary-card-meta">
                {{ number_format((int) ($summary['taxas_ativas'] ?? 0), 0, ',', '.') }} ativas
            </p>
        </div>
        <div class="summary-card">
            <p class="summary-card-eyebrow">Taxas online</p>
            <h3 class="summary-card-value">{{ number_format((int) ($gatewaySummary['ativas'] ?? 0), 0, ',', '.') }}</h3>
            <p class="summary-card-meta">
                {{ number_format((int) ($gatewaySummary['total'] ?? 0), 0, ',', '.') }} registros
            </p>
        </div>
    </div>

    <section class="surface-card desktop-config-tabs-shell">
        <div class="config-subtabs flex-wrap" role="tablist" aria-label="Cartões e taxas">
            @foreach ($tabs as $tabName => $tab)
                <button
                    type="button"
                    class="config-subtab {{ $activeTab === $tabName ? 'is-active' : '' }}"
                    data-cartoes-tab="{{ $tabName }}"
                    aria-pressed="{{ $activeTab === $tabName ? 'true' : 'false' }}"
                >
                    <i class="bi {{ $tab['icon'] }} me-1"></i>{{ $tab['label'] }}
                </button>
            @endforeach
        </div>

        <div class="config-subpanel {{ $activeTab === 'operadoras' ? 'is-active' : '' }}" data-cartoes-panel="operadoras">
            <div class="surface-card-header align-items-start mt-3">
                <div>
                    <h3 class="surface-title mb-1">Operadoras de maquininha</h3>
                    <p class="surface-subtitle mb-0">Crie operadoras, ajuste o prazo padrão e mantenha o catálogo ativo para a simulação de recebimentos.</p>
                </div>

                <span class="desktop-chip">
                    <i class="bi bi-building"></i>
                    {{ number_format(count($operadoras), 0, ',', '.') }} operadoras
                </span>
            </div>

            <div class="desktop-form-card mt-3">
                <form
                    method="post"
                    action="{{ route('financeiro.cartoes.operadoras.save') }}"
                    class="desktop-filter-grid"
                    data-cartoes-form="operadora"
                >
                    @csrf
                    <input type="hidden" name="id" value="" data-cartoes-field="id">

                    <div class="field-span-2">
                        <label for="cartaoOperadoraNome">Nome</label>
                        <input
                            type="text"
                            name="nome"
                            id="cartaoOperadoraNome"
                            class="form-control"
                            placeholder="Ex.: Stone"
                            data-cartoes-field="nome"
                            value="{{ old('nome', '') }}"
                            required
                        >
                    </div>

                    <div class="field-span-2">
                        <label for="cartaoOperadoraDescricao">Descrição</label>
                        <input
                            type="text"
                            name="descricao"
                            id="cartaoOperadoraDescricao"
                            class="form-control"
                            placeholder="Contexto interno opcional"
                            data-cartoes-field="descricao"
                            value="{{ old('descricao', '') }}"
                        >
                    </div>

                    <div>
                        <label for="cartaoOperadoraOrdem">Ordem</label>
                        <input
                            type="number"
                            name="ordem_exibicao"
                            id="cartaoOperadoraOrdem"
                            class="form-control"
                            min="0"
                            step="1"
                            data-cartoes-field="ordem_exibicao"
                            value="{{ old('ordem_exibicao', 0) }}"
                        >
                    </div>

                    <div>
                        <label for="cartaoOperadoraPrazo">Prazo padrão (dias)</label>
                        <input
                            type="number"
                            name="prazo_padrao_dias"
                            id="cartaoOperadoraPrazo"
                            class="form-control"
                            min="0"
                            step="1"
                            data-cartoes-field="prazo_padrao_dias"
                            value="{{ old('prazo_padrao_dias', 30) }}"
                        >
                    </div>

                    <div class="d-flex align-items-end">
                        <div class="form-check form-switch">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                role="switch"
                                id="cartaoOperadoraAtivo"
                                name="ativo"
                                value="1"
                                data-cartoes-field="ativo"
                                @checked(old('ativo', true))
                            >
                            <label class="form-check-label" for="cartaoOperadoraAtivo">Operadora ativa</label>
                        </div>
                    </div>

                    <div class="field-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save2 me-2"></i>
                            Salvar operadora
                        </button>
                        <button type="button" class="btn btn-outline-light" data-cartoes-reset="operadora">
                            Limpar
                        </button>
                    </div>
                </form>
            </div>

            <div class="surface-table mt-4">
                <div class="surface-table-header">
                    <div>
                        <h3 class="surface-title">Operadoras cadastradas</h3>
                        <p class="surface-subtitle mb-0">Edite ou desative sem sair da tela.</p>
                    </div>
                    <span class="desktop-chip">
                        <i class="bi bi-list-check"></i>
                        {{ number_format(count($operadoras), 0, ',', '.') }} registros
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table table-stack align-middle">
                        <thead>
                        <tr>
                            <th>Operadora</th>
                            <th>Prazo</th>
                            <th>Taxas</th>
                            <th>Status</th>
                            <th class="text-end">Ações</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($operadoras as $operadora)
                            @php
                                $operadoraId = (int) ($operadora['id'] ?? 0);
                            @endphp
                            <tr data-operadora-id="{{ $operadoraId }}">
                                <td data-label="Operadora">
                                    <div class="fw-semibold">{{ $operadora['nome'] ?? '-' }}</div>
                                    <small class="text-secondary d-block">{{ $operadora['descricao'] ?? 'Sem descrição' }}</small>
                                </td>
                                <td data-label="Prazo">{{ number_format((int) ($operadora['prazo_padrao_dias'] ?? 0), 0, ',', '.') }} dias</td>
                                <td data-label="Taxas">{{ number_format((int) ($operadora['taxas_count'] ?? 0), 0, ',', '.') }}</td>
                                <td data-label="Status">
                                    @include('layouts.partials.status-pill', [
                                        'label' => (bool) ($operadora['ativo'] ?? false) ? 'Ativa' : 'Inativa',
                                        'color' => (bool) ($operadora['ativo'] ?? false) ? '#29c384' : '#8b93a7',
                                        'small' => true,
                                    ])
                                </td>
                                <td data-label="Ações" class="text-end">
                                    <div class="dropdown">
                                        <button type="button" class="btn btn-sm btn-outline-light dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                            <span>Ações</span>
                                            <i class="bi bi-chevron-down"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            @if ($canEdit)
                                                <li>
                                                    <button
                                                        type="button"
                                                        class="dropdown-item"
                                                        data-cartoes-edit="operadora"
                                                        data-cartoes-id="{{ $operadoraId }}"
                                                        data-cartoes-nome="{{ $operadora['nome'] ?? '' }}"
                                                        data-cartoes-descricao="{{ $operadora['descricao'] ?? '' }}"
                                                        data-cartoes-ordem-exibicao="{{ (int) ($operadora['ordem_exibicao'] ?? 0) }}"
                                                        data-cartoes-prazo-padrao-dias="{{ (int) ($operadora['prazo_padrao_dias'] ?? 30) }}"
                                                        data-cartoes-ativo="{{ (bool) ($operadora['ativo'] ?? false) ? '1' : '0' }}"
                                                    >
                                                        <i class="bi bi-pencil me-2"></i>Editar
                                                    </button>
                                                </li>
                                            @endif
                                            @if ($canDelete && $operadoraId > 0)
                                                <li>
                                                    <form method="post" action="{{ route('financeiro.cartoes.operadoras.delete', $operadoraId) }}" data-confirm="Desativar esta operadora?" data-confirm-title="Desativar operadora" data-confirm-button="Sim, desativar">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="dropdown-item text-danger">
                                                            <i class="bi bi-slash-circle me-2"></i>Desativar
                                                        </button>
                                                    </form>
                                                </li>
                                            @endif
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    @include('layouts.partials.empty-state', [
                                        'icon' => 'bi-cash-coin',
                                        'title' => 'Nenhuma operadora cadastrada',
                                        'message' => 'Cadastre a primeira operadora para liberar as simulações de cartao.',
                                    ])
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="config-subpanel {{ $activeTab === 'bandeiras' ? 'is-active' : '' }}" data-cartoes-panel="bandeiras">
            <div class="surface-card-header align-items-start mt-3">
                <div>
                    <h3 class="surface-title mb-1">Bandeiras</h3>
                    <p class="surface-subtitle mb-0">Organize as bandeiras usadas nas taxas por parcela.</p>
                </div>

                <span class="desktop-chip">
                    <i class="bi bi-credit-card"></i>
                    {{ number_format(count($bandeiras), 0, ',', '.') }} bandeiras
                </span>
            </div>

            <div class="desktop-form-card mt-3">
                <form
                    method="post"
                    action="{{ route('financeiro.cartoes.bandeiras.save') }}"
                    class="desktop-filter-grid"
                    data-cartoes-form="bandeira"
                >
                    @csrf
                    <input type="hidden" name="id" value="" data-cartoes-field="id">

                    <div class="field-span-2">
                        <label for="cartaoBandeiraNome">Nome</label>
                        <input
                            type="text"
                            name="nome"
                            id="cartaoBandeiraNome"
                            class="form-control"
                            placeholder="Ex.: Visa"
                            data-cartoes-field="nome"
                            value="{{ old('nome', '') }}"
                            required
                        >
                    </div>

                    <div>
                        <label for="cartaoBandeiraOrdem">Ordem</label>
                        <input
                            type="number"
                            name="ordem_exibicao"
                            id="cartaoBandeiraOrdem"
                            class="form-control"
                            min="0"
                            step="1"
                            data-cartoes-field="ordem_exibicao"
                            value="{{ old('ordem_exibicao', 0) }}"
                        >
                    </div>

                    <div class="d-flex align-items-end">
                        <div class="form-check form-switch">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                role="switch"
                                id="cartaoBandeiraAtiva"
                                name="ativo"
                                value="1"
                                data-cartoes-field="ativo"
                                @checked(old('ativo', true))
                            >
                            <label class="form-check-label" for="cartaoBandeiraAtiva">Bandeira ativa</label>
                        </div>
                    </div>

                    <div class="field-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save2 me-2"></i>
                            Salvar bandeira
                        </button>
                        <button type="button" class="btn btn-outline-light" data-cartoes-reset="bandeira">
                            Limpar
                        </button>
                    </div>
                </form>
            </div>

            <div class="surface-table mt-4">
                <div class="surface-table-header">
                    <div>
                        <h3 class="surface-title">Bandeiras cadastradas</h3>
                        <p class="surface-subtitle mb-0">Controle simples e sem acesso ao banco.</p>
                    </div>
                    <span class="desktop-chip">
                        <i class="bi bi-list-check"></i>
                        {{ number_format(count($bandeiras), 0, ',', '.') }} registros
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table table-stack align-middle">
                        <thead>
                        <tr>
                            <th>Bandeira</th>
                            <th>Ordem</th>
                            <th>Status</th>
                            <th class="text-end">Ações</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($bandeiras as $bandeira)
                            @php
                                $bandeiraId = (int) ($bandeira['id'] ?? 0);
                            @endphp
                            <tr data-bandeira-id="{{ $bandeiraId }}">
                                <td data-label="Bandeira">
                                    <div class="fw-semibold">{{ $bandeira['nome'] ?? '-' }}</div>
                                </td>
                                <td data-label="Ordem">{{ number_format((int) ($bandeira['ordem_exibicao'] ?? 0), 0, ',', '.') }}</td>
                                <td data-label="Status">
                                    @include('layouts.partials.status-pill', [
                                        'label' => (bool) ($bandeira['ativo'] ?? false) ? 'Ativa' : 'Inativa',
                                        'color' => (bool) ($bandeira['ativo'] ?? false) ? '#29c384' : '#8b93a7',
                                        'small' => true,
                                    ])
                                </td>
                                <td data-label="Ações" class="text-end">
                                    <div class="dropdown">
                                        <button type="button" class="btn btn-sm btn-outline-light dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                            <span>Ações</span>
                                            <i class="bi bi-chevron-down"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            @if ($canEdit)
                                                <li>
                                                    <button
                                                        type="button"
                                                        class="dropdown-item"
                                                        data-cartoes-edit="bandeira"
                                                        data-cartoes-id="{{ $bandeiraId }}"
                                                        data-cartoes-nome="{{ $bandeira['nome'] ?? '' }}"
                                                        data-cartoes-ordem-exibicao="{{ (int) ($bandeira['ordem_exibicao'] ?? 0) }}"
                                                        data-cartoes-ativo="{{ (bool) ($bandeira['ativo'] ?? false) ? '1' : '0' }}"
                                                    >
                                                        <i class="bi bi-pencil me-2"></i>Editar
                                                    </button>
                                                </li>
                                            @endif
                                            @if ($canDelete && $bandeiraId > 0)
                                                <li>
                                                    <form method="post" action="{{ route('financeiro.cartoes.bandeiras.delete', $bandeiraId) }}" data-confirm="Desativar esta bandeira?" data-confirm-title="Desativar bandeira" data-confirm-button="Sim, desativar">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="dropdown-item text-danger">
                                                            <i class="bi bi-slash-circle me-2"></i>Desativar
                                                        </button>
                                                    </form>
                                                </li>
                                            @endif
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4">
                                    @include('layouts.partials.empty-state', [
                                        'icon' => 'bi-credit-card',
                                        'title' => 'Nenhuma bandeira cadastrada',
                                        'message' => 'Cadastre a primeira bandeira para liberar as faixas de parcelamento.',
                                    ])
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="config-subpanel {{ $activeTab === 'taxas' ? 'is-active' : '' }}" data-cartoes-panel="taxas">
            <div class="surface-card-header align-items-start mt-3">
                <div>
                    <h3 class="surface-title mb-1">Taxa por parcela</h3>
                    <p class="surface-subtitle mb-0">Configure as faixas usadas na baixa e nas simulações do financeiro.</p>
                </div>

                <span class="desktop-chip">
                    <i class="bi bi-percent"></i>
                    {{ number_format(count($taxas), 0, ',', '.') }} faixas
                </span>
            </div>

            <div class="desktop-grid desktop-grid-two align-items-start mt-3">
                <section class="desktop-form-card">
                    <form
                        method="post"
                        action="{{ route('financeiro.cartoes.taxas.save') }}"
                        class="desktop-filter-grid"
                        data-cartoes-form="taxa"
                    >
                        @csrf
                        <input type="hidden" name="id" value="" data-cartoes-field="id">

                        <div>
                            <label for="cartaoTaxaOperadora">Operadora</label>
                            <select
                                name="operadora_id"
                                id="cartaoTaxaOperadora"
                                class="form-select"
                                data-select2-placeholder="Selecione a operadora..."
                                data-cartoes-field="operadora_id"
                                required
                            >
                                <option value=""></option>
                                @foreach ($operadoras as $operadora)
                                    <option value="{{ (int) ($operadora['id'] ?? 0) }}" @selected((string) old('operadora_id') === (string) ($operadora['id'] ?? ''))>
                                        {{ $operadora['nome'] ?? '-' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="cartaoTaxaBandeira">Bandeira</label>
                            <select
                                name="bandeira_id"
                                id="cartaoTaxaBandeira"
                                class="form-select"
                                data-select2-placeholder="Opcional"
                                data-cartoes-field="bandeira_id"
                            >
                                <option value=""></option>
                                @foreach ($bandeiras as $bandeira)
                                    <option value="{{ (int) ($bandeira['id'] ?? 0) }}" @selected((string) old('bandeira_id') === (string) ($bandeira['id'] ?? ''))>
                                        {{ $bandeira['nome'] ?? '-' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="cartaoTaxaModalidade">Modalidade</label>
                            <select
                                name="modalidade"
                                id="cartaoTaxaModalidade"
                                class="form-select"
                                data-select2-placeholder="Selecione a modalidade..."
                                data-cartoes-field="modalidade"
                                required
                            >
                                <option value=""></option>
                                <option value="credito" @selected(old('modalidade', 'credito') === 'credito')>Crédito</option>
                                <option value="debito" @selected(old('modalidade') === 'debito')>Débito</option>
                            </select>
                        </div>

                        <div>
                            <label for="cartaoTaxaParcelaInicial">Parcelas de</label>
                            <input
                                type="number"
                                name="parcelas_inicial"
                                id="cartaoTaxaParcelaInicial"
                                class="form-control"
                                min="1"
                                max="24"
                                step="1"
                                data-cartoes-field="parcelas_inicial"
                                value="{{ old('parcelas_inicial', 1) }}"
                                required
                            >
                        </div>

                        <div>
                            <label for="cartaoTaxaParcelaFinal">Até</label>
                            <input
                                type="number"
                                name="parcelas_final"
                                id="cartaoTaxaParcelaFinal"
                                class="form-control"
                                min="1"
                                max="24"
                                step="1"
                                data-cartoes-field="parcelas_final"
                                value="{{ old('parcelas_final', 1) }}"
                                required
                            >
                        </div>

                        <div>
                            <label for="cartaoTaxaPercentual">Taxa (%)</label>
                            <input
                                type="number"
                                name="taxa_percentual"
                                id="cartaoTaxaPercentual"
                                class="form-control"
                                min="0"
                                step="0.0001"
                                data-cartoes-field="taxa_percentual"
                                value="{{ old('taxa_percentual', 0) }}"
                                required
                            >
                        </div>

                        <div>
                            <label for="cartaoTaxaFixa">Taxa fixa (R$)</label>
                            <input
                                type="number"
                                name="taxa_fixa"
                                id="cartaoTaxaFixa"
                                class="form-control"
                                min="0"
                                step="0.01"
                                data-cartoes-field="taxa_fixa"
                                value="{{ old('taxa_fixa', 0) }}"
                                required
                            >
                        </div>

                        <div>
                            <label for="cartaoTaxaPrazo">Recebimento (dias)</label>
                            <input
                                type="number"
                                name="prazo_recebimento_dias"
                                id="cartaoTaxaPrazo"
                                class="form-control"
                                min="0"
                                step="1"
                                data-cartoes-field="prazo_recebimento_dias"
                                value="{{ old('prazo_recebimento_dias', 30) }}"
                                required
                            >
                        </div>

                        <div class="field-span-2">
                            <label for="cartaoTaxaObservacoes">Observações</label>
                            <textarea
                                name="observacoes"
                                id="cartaoTaxaObservacoes"
                                class="form-control"
                                rows="2"
                                placeholder="Ex.: promoção especial da operadora"
                                data-cartoes-field="observacoes"
                            >{{ old('observacoes', '') }}</textarea>
                        </div>

                        <div class="d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    role="switch"
                                    id="cartaoTaxaAtiva"
                                    name="ativo"
                                    value="1"
                                    data-cartoes-field="ativo"
                                    @checked(old('ativo', true))
                                >
                                <label class="form-check-label" for="cartaoTaxaAtiva">Taxa ativa</label>
                            </div>
                        </div>

                        <div class="field-actions field-span-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save2 me-2"></i>
                                Salvar taxa
                            </button>
                            <button type="button" class="btn btn-outline-light" data-cartoes-reset="taxa">
                                Limpar
                            </button>
                        </div>
                    </form>
                </section>

                <section class="surface-table">
                    <div class="surface-card-header align-items-start mb-3">
                        <div>
                            <h3 class="surface-title mb-1">Taxas cadastradas</h3>
                            <p class="surface-subtitle mb-0">Filtre por operadora e veja a faixa exata antes de editar.</p>
                        </div>
                        <div class="d-flex flex-wrap justify-content-end gap-2">
                            <button type="button" class="btn btn-sm btn-primary" data-cartoes-taxa-filter="all">Todas</button>
                            @foreach ($operadoras as $operadora)
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-light"
                                    data-cartoes-taxa-filter="{{ (int) ($operadora['id'] ?? 0) }}"
                                >
                                    {{ $operadora['nome'] ?? '-' }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-stack align-middle">
                            <thead>
                            <tr>
                                <th>Operadora</th>
                                <th>Bandeira</th>
                                <th>Modalidade</th>
                                <th>Faixa</th>
                                <th>Taxa</th>
                                <th>Liquidação</th>
                                <th>Status</th>
                                <th class="text-end">Ações</th>
                            </tr>
                            </thead>
                            <tbody data-cartoes-taxas-body>
                            @forelse ($taxas as $taxa)
                                @php
                                    $taxaId = (int) ($taxa['id'] ?? 0);
                                    $taxaOperadoraId = (int) ($taxa['operadora_id'] ?? 0);
                                @endphp
                                <tr data-operadora-id="{{ $taxaOperadoraId }}" data-cartoes-row="taxa">
                                    <td data-label="Operadora">{{ $taxa['operadora_nome'] ?? '-' }}</td>
                                    <td data-label="Bandeira">{{ $taxa['bandeira_nome'] ?? 'Todas' }}</td>
                                    <td data-label="Modalidade">{{ ucfirst((string) ($taxa['modalidade'] ?? '-')) }}</td>
                                    <td data-label="Faixa">
                                        {{ number_format((int) ($taxa['parcelas_inicial'] ?? 0), 0, ',', '.') }}
                                        x a
                                        {{ number_format((int) ($taxa['parcelas_final'] ?? 0), 0, ',', '.') }}
                                        x
                                    </td>
                                    <td data-label="Taxa">
                                        {{ number_format((float) ($taxa['taxa_percentual'] ?? 0), 4, ',', '.') }}%
                                        + {{ $formatMoney((float) ($taxa['taxa_fixa'] ?? 0)) }}
                                    </td>
                                    <td data-label="Liquidação">{{ number_format((int) ($taxa['prazo_recebimento_dias'] ?? 0), 0, ',', '.') }} dias</td>
                                    <td data-label="Status">
                                        @include('layouts.partials.status-pill', [
                                            'label' => (bool) ($taxa['ativo'] ?? false) ? 'Ativa' : 'Inativa',
                                            'color' => (bool) ($taxa['ativo'] ?? false) ? '#29c384' : '#8b93a7',
                                            'small' => true,
                                        ])
                                    </td>
                                    <td data-label="Ações" class="text-end">
                                        <div class="dropdown">
                                            <button type="button" class="btn btn-sm btn-outline-light dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                                <span>Ações</span>
                                                <i class="bi bi-chevron-down"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                @if ($canEdit)
                                                    <li>
                                                        <button
                                                            type="button"
                                                            class="dropdown-item"
                                                            data-cartoes-edit="taxa"
                                                            data-cartoes-id="{{ $taxaId }}"
                                                            data-cartoes-operadora-id="{{ $taxaOperadoraId }}"
                                                            data-cartoes-bandeira-id="{{ (int) ($taxa['bandeira_id'] ?? 0) }}"
                                                            data-cartoes-modalidade="{{ $taxa['modalidade'] ?? '' }}"
                                                            data-cartoes-parcelas-inicial="{{ (int) ($taxa['parcelas_inicial'] ?? 1) }}"
                                                            data-cartoes-parcelas-final="{{ (int) ($taxa['parcelas_final'] ?? 1) }}"
                                                            data-cartoes-taxa-percentual="{{ (float) ($taxa['taxa_percentual'] ?? 0) }}"
                                                            data-cartoes-taxa-fixa="{{ (float) ($taxa['taxa_fixa'] ?? 0) }}"
                                                            data-cartoes-prazo-recebimento-dias="{{ (int) ($taxa['prazo_recebimento_dias'] ?? 0) }}"
                                                            data-cartoes-observacoes="{{ $taxa['observacoes'] ?? '' }}"
                                                            data-cartoes-ativo="{{ (bool) ($taxa['ativo'] ?? false) ? '1' : '0' }}"
                                                        >
                                                            <i class="bi bi-pencil me-2"></i>Editar
                                                        </button>
                                                    </li>
                                                @endif
                                                @if ($canDelete && $taxaId > 0)
                                                    <li>
                                                        <form method="post" action="{{ route('financeiro.cartoes.taxas.delete', $taxaId) }}" data-confirm="Desativar esta taxa?" data-confirm-title="Desativar taxa" data-confirm-button="Sim, desativar">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="dropdown-item text-danger">
                                                                <i class="bi bi-slash-circle me-2"></i>Desativar
                                                            </button>
                                                        </form>
                                                    </li>
                                                @endif
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8">
                                        @include('layouts.partials.empty-state', [
                                            'icon' => 'bi-percent',
                                            'title' => 'Nenhuma taxa cadastrada',
                                            'message' => 'Cadastre a primeira faixa para liberar a simulação com taxa automática.',
                                        ])
                                    </td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>

        <div class="config-subpanel {{ $activeTab === 'simulador' ? 'is-active' : '' }}" data-cartoes-panel="simulador">
            <div class="surface-card-header align-items-start mt-3">
                <div>
                    <h3 class="surface-title mb-1">Simulador de faturamento líquido</h3>
                    <p class="surface-subtitle mb-0">Veja a taxa estimada antes de confirmar o recebimento.</p>
                </div>
            </div>

            <div class="desktop-form-card mt-3">
                <form method="post" action="{{ route('financeiro.cartoes.simulate') }}" class="desktop-filter-grid" data-financeiro-cartoes-simulator>
                    @csrf
                    <div>
                        <label for="simValorBruto">Valor bruto da venda</label>
                        <input type="number" name="valor_bruto" id="simValorBruto" class="form-control" step="0.01" min="0.01" value="{{ old('valor_bruto', '130.00') }}" required>
                    </div>
                    <div>
                        <label for="simOperadora">Operadora</label>
                        <select name="operadora_id" id="simOperadora" class="form-select" data-select2-placeholder="Selecione a operadora..." required>
                            <option value=""></option>
                            @foreach ($simuladorCatalogo['operadoras'] ?? [] as $operadora)
                                <option value="{{ (int) ($operadora['id'] ?? 0) }}" @selected((string) old('operadora_id', (string) (($firstActiveOperadora['id'] ?? '') !== '' ? $firstActiveOperadora['id'] : '')) === (string) ($operadora['id'] ?? ''))>
                                    {{ $operadora['nome'] ?? '-' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="simBandeira">Bandeira</label>
                        <select name="bandeira_id" id="simBandeira" class="form-select" data-select2-placeholder="Opcional">
                            <option value=""></option>
                            @foreach ($simuladorCatalogo['bandeiras'] ?? [] as $bandeira)
                                <option value="{{ (int) ($bandeira['id'] ?? 0) }}" @selected((string) old('bandeira_id', (string) (($firstActiveBandeira['id'] ?? '') !== '' ? $firstActiveBandeira['id'] : '')) === (string) ($bandeira['id'] ?? ''))>
                                    {{ $bandeira['nome'] ?? '-' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="simModalidade">Modalidade</label>
                        <select name="modalidade" id="simModalidade" class="form-select" data-select2-placeholder="Selecione a modalidade..." required>
                            <option value=""></option>
                            <option value="credito" @selected($firstSimuladorModalidade === 'credito')>Crédito</option>
                            <option value="debito" @selected($firstSimuladorModalidade === 'debito')>Débito</option>
                        </select>
                    </div>
                    <div>
                        <label for="simParcelas">Parcelas</label>
                        <input type="number" name="parcelas" id="simParcelas" class="form-control" min="1" max="24" step="1" value="{{ old('parcelas', 1) }}" required>
                    </div>
                    <div class="field-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-calculator me-2"></i>
                            Simular recebimento
                        </button>
                    </div>
                </form>

                <div class="desktop-grid desktop-grid-four mt-4" data-financeiro-cartoes-simulation-results>
                    <div class="summary-card">
                        <p class="summary-card-eyebrow">Taxa total</p>
                        <h3 class="summary-card-value" data-sim-field="fee">R$ 0,00</h3>
                        <p class="summary-card-meta" data-sim-meta="fee">Aguardando simulação</p>
                    </div>
                    <div class="summary-card">
                        <p class="summary-card-eyebrow">Valor líquido</p>
                        <h3 class="summary-card-value" data-sim-field="net">R$ 0,00</h3>
                        <p class="summary-card-meta" data-sim-meta="net">Aguardando simulação</p>
                    </div>
                    <div class="summary-card">
                        <p class="summary-card-eyebrow">Percentual aplicado</p>
                        <h3 class="summary-card-value" data-sim-field="percent">0,0000%</h3>
                        <p class="summary-card-meta" data-sim-meta="percent">Aguardando simulação</p>
                    </div>
                    <div class="summary-card">
                        <p class="summary-card-eyebrow">Previsão de recebimento</p>
                        <h3 class="summary-card-value" data-sim-field="due">-</h3>
                        <p class="summary-card-meta" data-sim-meta="due">Aguardando simulação</p>
                    </div>
                </div>

                <div class="surface-subtitle mt-3 mb-0" data-financeiro-cartoes-simulator-status>
                    Preencha os dados acima para estimar o valor líquido, a taxa e a liquidação.
                </div>
            </div>
        </div>

        <div class="config-subpanel {{ $activeTab === 'gateway' ? 'is-active' : '' }}" data-cartoes-panel="gateway">
            <div class="surface-card-header align-items-start mt-3">
                <div>
                    <h3 class="surface-title mb-1">Taxas online</h3>
                    <p class="surface-subtitle mb-0">Configure as tarifas embutidas em Pix, boleto, crédito e débito.</p>
                </div>

                <span class="desktop-chip">
                    <i class="bi bi-globe2"></i>
                    {{ number_format((int) ($gatewaySummary['ativas'] ?? 0), 0, ',', '.') }} ativas
                </span>
            </div>

            <div class="desktop-grid desktop-grid-two align-items-start mt-3">
                <section class="desktop-form-card">
                    <form
                        method="post"
                        action="{{ route('financeiro.cartoes.gateway.save') }}"
                        class="desktop-filter-grid"
                        data-cartoes-form="gateway"
                    >
                        @csrf
                        <input type="hidden" name="id" value="" data-cartoes-field="id">

                        <div>
                            <label for="gatewayProvider">Gateway</label>
                            <select
                                name="provider"
                                id="gatewayProvider"
                                class="form-select"
                                data-select2-placeholder="Selecione o gateway..."
                                data-cartoes-field="provider"
                                required
                            >
                                <option value=""></option>
                                @foreach ($gatewayCatalog as $providerKey => $provider)
                                    <option value="{{ $providerKey }}" @selected($activeProvider === $providerKey)>
                                        {{ $provider['label'] ?? $providerKey }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="gatewayMode">Modalidade</label>
                            <select
                                name="modalidade"
                                id="gatewayMode"
                                class="form-select"
                                data-select2-placeholder="Selecione a modalidade..."
                                data-cartoes-field="modalidade"
                                required
                            >
                                <option value=""></option>
                                @foreach ($providerModes as $mode)
                                    <option value="{{ $mode['code'] ?? '' }}" @selected((string) old('modalidade') === (string) ($mode['code'] ?? ''))>
                                        {{ $mode['label'] ?? ($mode['code'] ?? '-') }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="gatewayTaxaPercentual">Taxa (%)</label>
                            <input type="number" name="taxa_percentual" id="gatewayTaxaPercentual" class="form-control" min="0" step="0.0001" value="{{ old('taxa_percentual', 0) }}" data-cartoes-field="taxa_percentual">
                        </div>

                        <div>
                            <label for="gatewayTaxaFixa">Taxa fixa (R$)</label>
                            <input type="number" name="taxa_fixa" id="gatewayTaxaFixa" class="form-control" min="0" step="0.01" value="{{ old('taxa_fixa', 0) }}" data-cartoes-field="taxa_fixa">
                        </div>

                        <div>
                            <label for="gatewayOrdem">Ordem</label>
                            <input type="number" name="ordem_exibicao" id="gatewayOrdem" class="form-control" min="0" step="1" value="{{ old('ordem_exibicao', 0) }}" data-cartoes-field="ordem_exibicao">
                        </div>

                        <div class="d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="gatewayAtivo" name="ativo" value="1" @checked(old('ativo', true)) data-cartoes-field="ativo">
                                <label class="form-check-label" for="gatewayAtivo">Taxa ativa</label>
                            </div>
                        </div>

                        <div class="field-span-2">
                            <label for="gatewayObservacoes">Observações</label>
                            <textarea name="observacoes" id="gatewayObservacoes" class="form-control" rows="2" placeholder="Ex.: preço de campanha ou regra interna" data-cartoes-field="observacoes">{{ old('observacoes', '') }}</textarea>
                        </div>

                        <div class="field-actions field-span-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save2 me-2"></i>
                                Salvar taxa online
                            </button>
                            <button type="button" class="btn btn-outline-light" data-cartoes-reset="gateway">
                                Limpar
                            </button>
                        </div>
                    </form>
                </section>

                <section class="surface-table">
                    <div class="surface-card-header align-items-start mb-3">
                        <div>
                            <h3 class="surface-title mb-1">Taxas online cadastradas</h3>
                            <p class="surface-subtitle mb-0">Filtre pelo gateway e edite sem sair da tela.</p>
                        </div>
                        <div class="d-flex flex-wrap justify-content-end gap-2">
                            <button type="button" class="btn btn-sm btn-primary" data-cartoes-gateway-filter="all">Todas</button>
                            @foreach ($gatewayCatalog as $providerKey => $provider)
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-light"
                                    data-cartoes-gateway-filter="{{ $providerKey }}"
                                >
                                    {{ $provider['label'] ?? $providerKey }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-stack align-middle">
                            <thead>
                            <tr>
                                <th>Gateway</th>
                                <th>Modalidade</th>
                                <th>Taxa</th>
                                <th>Status</th>
                                <th>Observações</th>
                                <th class="text-end">Ações</th>
                            </tr>
                            </thead>
                            <tbody data-cartoes-gateway-body>
                            @forelse ($gatewayTaxas as $gatewayTaxa)
                                @php
                                    $gatewayTaxaId = (int) ($gatewayTaxa['id'] ?? 0);
                                    $gatewayProvider = (string) ($gatewayTaxa['provider'] ?? '');
                                @endphp
                                <tr data-provider="{{ $gatewayProvider }}" data-cartoes-row="gateway">
                                    <td data-label="Gateway">
                                        <div class="fw-semibold">{{ $gatewayTaxa['provider_label'] ?? ucfirst($gatewayProvider) }}</div>
                                    </td>
                                    <td data-label="Modalidade">
                                        <div class="fw-semibold">{{ $gatewayTaxa['modalidade_label'] ?? ($gatewayTaxa['modalidade'] ?? '-') }}</div>
                                        <small class="text-secondary d-block">{{ $gatewayTaxa['description'] ?? '' }}</small>
                                    </td>
                                    <td data-label="Taxa">
                                        {{ number_format((float) ($gatewayTaxa['taxa_percentual'] ?? 0), 4, ',', '.') }}%
                                        + {{ $formatMoney((float) ($gatewayTaxa['taxa_fixa'] ?? 0)) }}
                                    </td>
                                    <td data-label="Status">
                                        @include('layouts.partials.status-pill', [
                                            'label' => (bool) ($gatewayTaxa['ativo'] ?? false) ? 'Ativa' : 'Inativa',
                                            'color' => (bool) ($gatewayTaxa['ativo'] ?? false) ? '#29c384' : '#8b93a7',
                                            'small' => true,
                                        ])
                                    </td>
                                    <td data-label="Observações">{{ $gatewayTaxa['observacoes'] ?? '-' }}</td>
                                    <td data-label="Ações" class="text-end">
                                        <div class="dropdown">
                                            <button type="button" class="btn btn-sm btn-outline-light dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                                <span>Ações</span>
                                                <i class="bi bi-chevron-down"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                @if ($canEdit)
                                                    <li>
                                                        <button
                                                            type="button"
                                                            class="dropdown-item"
                                                            data-cartoes-edit="gateway"
                                                            data-cartoes-id="{{ $gatewayTaxaId }}"
                                                            data-cartoes-provider="{{ $gatewayProvider }}"
                                                            data-cartoes-modalidade="{{ $gatewayTaxa['modalidade'] ?? '' }}"
                                                            data-cartoes-taxa-percentual="{{ (float) ($gatewayTaxa['taxa_percentual'] ?? 0) }}"
                                                            data-cartoes-taxa-fixa="{{ (float) ($gatewayTaxa['taxa_fixa'] ?? 0) }}"
                                                            data-cartoes-ordem-exibicao="{{ (int) ($gatewayTaxa['ordem_exibicao'] ?? 0) }}"
                                                            data-cartoes-observacoes="{{ $gatewayTaxa['observacoes'] ?? '' }}"
                                                            data-cartoes-ativo="{{ (bool) ($gatewayTaxa['ativo'] ?? false) ? '1' : '0' }}"
                                                        >
                                                            <i class="bi bi-pencil me-2"></i>Editar
                                                        </button>
                                                    </li>
                                                @endif
                                                @if ($canDelete && $gatewayTaxaId > 0)
                                                    <li>
                                                        <form method="post" action="{{ route('financeiro.cartoes.gateway.delete', $gatewayTaxaId) }}" data-confirm="Desativar esta taxa online?" data-confirm-title="Desativar taxa online" data-confirm-button="Sim, desativar">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="dropdown-item text-danger">
                                                                <i class="bi bi-slash-circle me-2"></i>Desativar
                                                            </button>
                                                        </form>
                                                    </li>
                                                @endif
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6">
                                        @include('layouts.partials.empty-state', [
                                            'icon' => 'bi-globe2',
                                            'title' => 'Nenhuma taxa online cadastrada',
                                            'message' => 'Cadastre a primeira taxa para embutir custo em pagamentos online.',
                                        ])
                                    </td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </section>
@endsection

@section('scripts')
    <script>
        window.__FINANCEIRO_CARTOES = {!! \Illuminate\Support\Js::from([
            'activeTab' => $activeTab,
            'routes' => [
                'simulate' => route('financeiro.cartoes.simulate'),
            ],
            'gatewayCatalog' => $gatewayCatalog,
        ]) !!};
    </script>
    <script src="{{ asset('assets/js/financeiro-cartoes.js') }}?v={{ filemtime(public_path('assets/js/financeiro-cartoes.js')) }}"></script>
@endsection
