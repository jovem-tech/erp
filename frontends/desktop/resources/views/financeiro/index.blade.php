@extends('layouts.app')

@section('content')
    @php
        $hasActiveFilters = trim((string) ($filters['tipo'] ?? '')) !== ''
            || trim((string) ($filters['status'] ?? '')) !== '';
        $activeFilterCount = count(array_filter([
            trim((string) ($filters['tipo'] ?? '')) !== '',
            trim((string) ($filters['status'] ?? '')) !== '',
        ]));
    @endphp

    <x-list-filters
        form-id="financeiroFilterPanel"
        :show-search="false"
        :results-count="$pagination['total'] ?? 0"
        results-label="lançamentos"
        :clear-url="route('financeiro.index')"
        :has-active-filters="$hasActiveFilters"
        :active-filter-count="$activeFilterCount"
    >
        <x-slot:actions>
            @if ((int) ($filters['cliente_id'] ?? 0) > 0)
                <span class="desktop-chip">
                    <i class="bi bi-person"></i>
                    Cliente #{{ (int) $filters['cliente_id'] }}
                </span>
            @endif

            <div class="dropdown os-actions-dropdown">
                <button type="button"
                    class="btn btn-outline-info dropdown-toggle os-actions-toggle"
                    data-bs-toggle="dropdown"
                    aria-expanded="false">
                    <i class="bi bi-file-earmark-bar-graph me-2"></i>
                    Relatórios
                </button>

                <div class="dropdown-menu dropdown-menu-end os-actions-menu">
                    <a href="{{ route('financeiro.relatorios.fluxo-caixa') }}" class="dropdown-item">
                        <i class="bi bi-calendar3-week me-2"></i>Fluxo de Caixa
                    </a>

                    <a href="{{ route('financeiro.relatorios.dre') }}" class="dropdown-item">
                        <i class="bi bi-graph-up-arrow me-2"></i>DRE por Competência
                    </a>

                    <a href="{{ route('financeiro.relatorios.dre-caixa') }}" class="dropdown-item">
                        <i class="bi bi-wallet2 me-2"></i>DRE de Caixa
                    </a>

                    <a href="{{ route('financeiro.relatorios.margem') }}" class="dropdown-item">
                        <i class="bi bi-graph-up me-2"></i>Margem por OS
                    </a>
                </div>
            </div>

            <div class="dropdown os-actions-dropdown">
                <button type="button"
                    class="btn btn-outline-light dropdown-toggle os-actions-toggle"
                    data-bs-toggle="dropdown"
                    aria-expanded="false">
                    Mais ações
                </button>

                <div class="dropdown-menu dropdown-menu-end os-actions-menu">
                    <a href="{{ route('financeiro.cartoes.index') }}" class="dropdown-item">
                        <i class="bi bi-credit-card-2-front me-2"></i>Cartões e Taxas
                    </a>

                    <a href="{{ route('financeiro.configuracoes') }}" class="dropdown-item">
                        <i class="bi bi-bar-chart-line me-2"></i>Configurações Financeiras
                    </a>

                    @if (\App\Support\DesktopSession::can('precificacao', 'visualizar'))
                        <a href="{{ route('financeiro.precificacao.index') }}" class="dropdown-item">
                            <i class="bi bi-calculator me-2"></i>Precificação
                        </a>
                    @endif
                </div>
            </div>

            @if (\App\Support\DesktopSession::can('financeiro', 'criar'))
                <a href="{{ route('financeiro.create') }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-2"></i>
                    Novo lançamento
                </a>
            @endif
        </x-slot:actions>

        @if ((int) ($filters['cliente_id'] ?? 0) > 0)
            <input type="hidden" name="cliente_id" value="{{ (int) $filters['cliente_id'] }}">
        @endif

        <div>
            <label for="tipo">Tipo</label>
            <select id="tipo" name="tipo" class="form-select">
                <option value="" @selected(($filters['tipo'] ?? '') === '')>Todos</option>
                <option value="receber" @selected(($filters['tipo'] ?? '') === 'receber')>A receber</option>
                <option value="pagar" @selected(($filters['tipo'] ?? '') === 'pagar')>A pagar</option>
            </select>
        </div>

        <div>
            <label for="status">Status</label>
            <select id="status" name="status" class="form-select">
                <option value="" @selected(($filters['status'] ?? '') === '')>Todos</option>
                @foreach ($statusOptions as $option)
                    <option value="{{ $option['value'] ?? '' }}" @selected(($filters['status'] ?? '') === ($option['value'] ?? ''))>
                        {{ $option['label'] ?? $option['value'] }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="per_page">Itens por página</label>
            <select id="per_page" name="per_page" class="form-select">
                @foreach ([15, 30, 50] as $size)
                    <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 15) === $size)>{{ $size }}</option>
                @endforeach
            </select>
        </div>
    </x-list-filters>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Títulos financeiros</h2>
                <p class="surface-subtitle">
                    {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} lançamentos retornados pela API central.
                </p>
            </div>

            <span class="desktop-chip">
                <i class="bi bi-cash-coin"></i>
                {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} registros
            </span>
        </div>

        @if ($lancamentos !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tipo</th>
                        <th>Categoria</th>
                        <th>Valor</th>
                        <th>Vencimento</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($lancamentos as $lancamento)
                        @php
                            $id = (int) ($lancamento['id'] ?? 0);
                            $tipo = (string) ($lancamento['tipo'] ?? '');
                            $status = (string) ($lancamento['status'] ?? 'pendente');
                            $statusColors = [
                                'pendente' => '#f59e0b',
                                'parcial' => '#3b82f6',
                                'pago' => '#29c384',
                                'cancelado' => '#8b93a7',
                            ];
                            $canPay = in_array($status, ['pendente', 'parcial'], true);
                            $valorAberto = round((float) ($lancamento['valor_aberto'] ?? $lancamento['valor'] ?? 0), 2);
                            $osIsEncerrada = (bool) ($lancamento['os_is_encerrada'] ?? false);
                        @endphp
                        <tr>
                            <td data-label="ID">{{ $id > 0 ? $id : '-' }}</td>
                            <td data-label="Tipo">
                                <span class="badge {{ $tipo === 'receber' ? 'text-bg-success' : 'text-bg-secondary' }}">
                                    {{ $tipo === 'receber' ? 'A receber' : 'A pagar' }}
                                </span>
                            </td>
                            <td data-label="Categoria">
                                <div class="fw-semibold">{{ $lancamento['categoria'] ?? 'Sem categoria' }}</div>
                                @php $origemTrilha = array_filter((array) ($lancamento['origem_trilha'] ?? [])); @endphp
                                @if ($origemTrilha !== [])
                                    <small class="text-secondary d-block">{{ implode(' | ', $origemTrilha) }}</small>
                                @endif
                            </td>
                            <td data-label="Valor">R$ {{ number_format((float) ($lancamento['valor'] ?? 0), 2, ',', '.') }}</td>
                            <td data-label="Vencimento">{{ !empty($lancamento['data_vencimento']) ? \Illuminate\Support\Carbon::parse($lancamento['data_vencimento'])->format('d/m/Y') : '-' }}</td>
                            <td data-label="Status">
                                @include('layouts.partials.status-pill', [
                                    'label' => ucfirst($status),
                                    'color' => $statusColors[$status] ?? '#8b93a7',
                                    'small' => true,
                                ])
                            </td>
                            <td data-label="Ações" class="text-end">
                                <x-list-actions>
                                    <li>
                                        <a href="{{ route('financeiro.show', $id) }}" class="dropdown-item">
                                            <i class="bi bi-eye me-2"></i>
                                            Detalhes
                                        </a>
                                    </li>

                                    @if (\App\Support\DesktopSession::can('financeiro', 'editar'))
                                        <li>
                                            <a href="{{ route('financeiro.edit', $id) }}" class="dropdown-item">
                                                <i class="bi bi-pencil me-2"></i>
                                                Editar
                                            </a>
                                        </li>
                                    @endif

                                    @if ($canPay && \App\Support\DesktopSession::can('financeiro', 'editar'))
                                        <li>
                                            <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#payModal{{ $id }}">
                                                <i class="bi bi-cash-stack me-2"></i>
                                                Registrar baixa
                                            </button>
                                        </li>
                                    @endif

                                    @if ($status !== 'cancelado' && \App\Support\DesktopSession::can('financeiro', 'editar'))
                                        @php
                                            $hasMovements = in_array($status, ['parcial', 'pago'], true);
                                            $cancelConfirmMessage = $hasMovements
                                                ? 'Este lançamento já possui baixa registrada. Cancelar vai estornar (remover) os valores já lançados no fluxo de caixa e no DRE. Esta ação não pode ser desfeita. Deseja continuar?'
                                                : 'Deseja cancelar este lançamento? Ele deixará de contar no fluxo de caixa e no DRE, mas o registro é mantido.';
                                        @endphp
                                        <li>
                                            <form
                                                id="financeiroCancelForm{{ $id }}"
                                                method="post"
                                                action="{{ route('financeiro.cancel', $id) }}"
                                                @unless($osIsEncerrada)
                                                    data-confirm="{{ $cancelConfirmMessage }}"
                                                    data-confirm-title="Cancelar lançamento"
                                                    data-confirm-button="Sim, cancelar"
                                                @endunless
                                            >
                                                @csrf
                                                @if ($osIsEncerrada)
                                                    <input type="hidden" name="motivo" value="" data-financeiro-cancel-motivo>
                                                    <input type="hidden" name="admin_email" value="" data-financeiro-cancel-admin-email>
                                                    <input type="hidden" name="admin_password" value="" data-financeiro-cancel-admin-password>
                                                @endif
                                                <button
                                                    type="{{ $osIsEncerrada ? 'button' : 'submit' }}"
                                                    class="dropdown-item text-warning"
                                                    @if ($osIsEncerrada)
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#financeiroCancelReasonModal"
                                                        data-target-form="#financeiroCancelForm{{ $id }}"
                                                    @endif
                                                >
                                                    <i class="bi bi-x-circle me-2"></i>
                                                    Cancelar
                                                </button>
                                            </form>
                                        </li>
                                    @endif

                                    @if (\App\Support\DesktopSession::can('financeiro', 'excluir'))
                                        <li>
                                            @if ($osIsEncerrada)
                                                <span class="dropdown-item disabled">
                                                    <i class="bi bi-lock me-2"></i>
                                                    Excluir (OS encerrada — use Cancelar)
                                                </span>
                                            @else
                                                <form id="financeiroDeleteForm{{ $id }}" method="post" action="{{ route('financeiro.destroy', $id) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <input type="hidden" name="admin_email" value="" data-financeiro-delete-admin-email>
                                                    <input type="hidden" name="admin_password" value="" data-financeiro-delete-admin-password>
                                                    <button
                                                        type="button"
                                                        class="dropdown-item text-danger"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#financeiroDeleteAdminModal"
                                                        data-target-form="#financeiroDeleteForm{{ $id }}"
                                                    >
                                                        <i class="bi bi-trash me-2"></i>
                                                        Excluir
                                                    </button>
                                                </form>
                                            @endif
                                        </li>
                                    @endif
                                </x-list-actions>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            {{--
                Os modais de baixa ficam FORA da tabela de propósito: um <div>
                dentro de <tbody> é HTML inválido e o navegador aplica "foster
                parenting" (move o conteúdo para antes da <table>), o que
                quebra a estrutura do <form> e esvazia seus campos — o botão
                "Confirmar baixa" chegava a submeter o formulário sem nenhum
                dado. Por isso os modais são renderizados num loop separado,
                fora de <table>/<tbody>.
            --}}
            @foreach ($lancamentos as $lancamento)
                @php
                    $id = (int) ($lancamento['id'] ?? 0);
                    $status = (string) ($lancamento['status'] ?? 'pendente');
                    $canPay = in_array($status, ['pendente', 'parcial'], true);
                    $valorAberto = round((float) ($lancamento['valor_aberto'] ?? $lancamento['valor'] ?? 0), 2);
                @endphp
                @if ($canPay)
                    <div class="modal fade" id="payModal{{ $id }}" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <form method="post" action="{{ route('financeiro.pay', $id) }}" data-financeiro-pay-form data-valor-aberto="{{ number_format($valorAberto, 2, '.', '') }}">
                                    @csrf
                                    <div class="modal-header">
                                        <h5 class="modal-title">Registrar baixa — Lançamento #{{ $id }}</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-3">
                                            <label class="form-label">Valor da baixa</label>
                                            <div class="d-flex flex-wrap gap-2 mb-2">
                                                <button type="button" class="btn btn-outline-primary btn-sm" data-action="valor-total">
                                                    <i class="bi bi-cash-coin me-1"></i>Valor total (R$ {{ number_format($valorAberto, 2, ',', '.') }})
                                                </button>
                                                <button type="button" class="btn btn-outline-light btn-sm" data-action="valor-parcial">
                                                    <i class="bi bi-pie-chart me-1"></i>Valor parcial
                                                </button>
                                            </div>
                                            <input type="number" name="valor_movimento" class="form-control" step="0.01" min="0.01" max="{{ number_format($valorAberto, 2, '.', '') }}" data-field="valor_movimento" required>
                                            <small class="text-secondary d-block mt-1">
                                                Saldo em aberto: R$ {{ number_format($valorAberto, 2, ',', '.') }}. Um valor parcial mantém o lançamento como "Parcial", com o valor pago e o saldo pendente calculados automaticamente.
                                            </small>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Data do movimento</label>
                                            <input type="date" name="data_movimento" class="form-control" value="{{ now()->toDateString() }}">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Forma de pagamento</label>
                                            <select name="forma_pagamento" class="form-select" data-field="forma_pagamento">
                                                <option value="">Não informado</option>
                                                <option value="dinheiro">Dinheiro</option>
                                                <option value="cartao_credito">Cartão de crédito</option>
                                                <option value="cartao_debito">Cartão de débito</option>
                                                <option value="pix">Pix</option>
                                                <option value="boleto">Boleto</option>
                                                <option value="transferencia">Transferência</option>
                                            </select>
                                        </div>
                                        <div class="d-none mb-3 pt-2 border-top" data-card-fields>
                                            <div class="desktop-grid desktop-grid-two">
                                                <div>
                                                    <label class="form-label">Operadora</label>
                                                    <select class="form-select" name="operadora_id" data-field="operadora_id">
                                                        <option value="">Selecione</option>
                                                        @foreach ($cartaoDataset['operadoras'] ?? [] as $operadora)
                                                            <option value="{{ $operadora['id'] }}">{{ $operadora['nome'] }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="form-label">Bandeira (opcional)</label>
                                                    <select class="form-select" name="bandeira_id" data-field="bandeira_id">
                                                        <option value="">Genérica (qualquer bandeira)</option>
                                                        @foreach ($cartaoDataset['bandeiras'] ?? [] as $bandeira)
                                                            <option value="{{ $bandeira['id'] }}">{{ $bandeira['nome'] }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="form-label">Modalidade</label>
                                                    <select class="form-select" name="modalidade" data-field="modalidade">
                                                        <option value="credito">Crédito</option>
                                                        <option value="debito">Débito</option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="form-label">Parcelas</label>
                                                    <input type="number" min="1" max="99" step="1" class="form-control" name="parcelas" value="1" data-field="parcelas">
                                                </div>
                                            </div>
                                            <p class="small text-secondary mt-2 mb-0" data-card-preview>Selecione operadora, modalidade e parcelas para estimar a taxa.</p>
                                        </div>
                                        <div>
                                            <label class="form-label">Observações</label>
                                            <textarea name="observacoes" class="form-control" rows="2"></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                                        <button type="submit" class="btn btn-primary">Confirmar baixa</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                @endif
            @endforeach

            @include('layouts.partials.pagination', ['pagination' => $pagination, 'filters' => $filters])
        @else
            @include('layouts.partials.empty-state', [
                'icon' => 'bi-cash-coin',
                'title' => 'Nenhum lançamento encontrado',
                'message' => 'Ajuste os filtros ou cadastre o primeiro lançamento financeiro para começar o acompanhamento.',
            ])
        @endif
    </section>

    @include('financeiro._cancel_reason_modal')
    @include('financeiro._delete_admin_modal')
@endsection

@section('scripts')
    <script>
        window.__DESKTOP_FINANCEIRO_INDEX = {!! json_encode([
            'cartao' => $cartaoDataset ?? ['operadoras' => [], 'bandeiras' => [], 'taxas' => []],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
    </script>
    <script src="{{ asset('assets/js/financeiro-pay.js') }}?v={{ filemtime(public_path('assets/js/financeiro-pay.js')) }}"></script>
    <script src="{{ asset('assets/js/financeiro-cancel-reason-modal.js') }}?v={{ filemtime(public_path('assets/js/financeiro-cancel-reason-modal.js')) }}"></script>
    <script src="{{ asset('assets/js/financeiro-delete-admin-modal.js') }}?v={{ filemtime(public_path('assets/js/financeiro-delete-admin-modal.js')) }}"></script>
@endsection
