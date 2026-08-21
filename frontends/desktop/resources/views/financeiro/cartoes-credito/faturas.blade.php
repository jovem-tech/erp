@extends('layouts.app')

@php
    use App\Support\DesktopSession;

    $cartao = is_array($cartao ?? null) ? $cartao : [];
    $faturas = is_array($faturas ?? null) ? $faturas : [];
    $faturaAtual = is_array($faturaAtual ?? null) ? $faturaAtual : null;
    $filtros = is_array($filtros ?? null) ? $filtros : [];
    $cartaoId = (int) ($cartao['id'] ?? 0);
    $canCreateDespesa = DesktopSession::can('financeiro', 'criar');
    $canPagarFatura = DesktopSession::can('contas_saldos', 'editar');
    $money = static fn ($value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
    $date = static fn ($value): string => $value ? date('d/m/Y', strtotime((string) $value)) : '—';

    $totalAberto = array_sum(array_map(
        static fn (array $fatura): float => ($fatura['status'] ?? '') === 'aberta' ? (float) ($fatura['total'] ?? 0) : 0.0,
        $faturas
    ));
    $temVencida = collect($faturas)->contains(static fn (array $f): bool => (bool) ($f['vencida'] ?? false));

    $filtrosAtivos = array_filter($filtros, static fn ($v): bool => trim((string) $v) !== '');
    $atualAberta = $faturaAtual !== null && ($faturaAtual['status'] ?? '') === 'aberta';
    $atualVencida = (bool) ($faturaAtual['vencida'] ?? false);

    // Faturas já pagas: destino possível para uma compra que o banco cobrou mas
    // que ninguém chegou a lançar. Da mais recente para a mais antiga — o
    // esquecimento quase sempre é da última.
    $faturasPagas = collect($faturas)
        ->filter(static fn (array $f): bool => ($f['status'] ?? '') === 'paga')
        ->sortByDesc('data_vencimento')
        ->values()
        ->all();
@endphp

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Financeiro · Contas e Saldos</p>
            <h2 class="surface-title fs-3 mb-2">{{ $cartao['nome'] ?? 'Cartão' }}</h2>
            <p class="surface-subtitle mb-0">
                {{ $cartao['instituicao'] ?: 'Cartão de crédito' }}{{ !empty($cartao['final_cartao']) ? ' · final ' . $cartao['final_cartao'] : '' }}
                · fecha dia {{ (int) ($cartao['dia_fechamento'] ?? 0) }}, vence dia {{ (int) ($cartao['dia_vencimento'] ?? 0) }}
            </p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('financeiro.contas.index', ['aba' => 'cartoes-credito']) }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>Voltar para Cartões
            </a>
            @if ($canCreateDespesa && $cartaoId > 0)
                {{-- Lança já com o cartão escolhido: a despesa cai sozinha na
                     fatura aberta correspondente à data da compra. --}}
                <a href="{{ route('financeiro.create', ['tipo' => 'pagar', 'cartao_credito_id' => $cartaoId]) }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-2"></i>Nova despesa neste cartão
                </a>
            @endif

            @if ($canPagarFatura && $canCreateDespesa && $faturasPagas !== [])
                <div class="dropdown os-actions-dropdown align-self-start">
                    <button type="button"
                            class="btn btn-outline-light dropdown-toggle os-actions-toggle"
                            data-bs-toggle="dropdown"
                            aria-expanded="false">
                        Mais ações
                    </button>

                    <div class="dropdown-menu dropdown-menu-end os-actions-menu">
                        {{-- Compra esquecida numa fatura já fechada: entra
                             quitada junto com a fatura, sem precisar cancelar a
                             baixa e pagar tudo de novo. --}}
                        <button type="button"
                                class="dropdown-item"
                                data-bs-toggle="modal"
                                data-bs-target="#despesaEsquecidaModal">
                            <i class="bi bi-receipt-cutoff me-2"></i>Lançar despesa em fatura paga
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if ($temVencida)
        <div class="alert alert-warning d-flex gap-3 align-items-start mb-4">
            <i class="bi bi-exclamation-triangle fs-5"></i>
            <div>
                <strong>Há fatura vencida em aberto.</strong>
                <div>
                    Na operadora, o saldo não pago costuma ser postergado para a próxima fatura com juros do rotativo.
                    O sistema não faz esse lançamento sozinho — registre os juros como uma despesa do cartão para o valor bater com a fatura real.
                </div>
            </div>
        </div>
    @endif

    <div class="desktop-grid desktop-grid-three mb-4">
        <article class="summary-card">
            <p class="summary-card-eyebrow">Fatura atual</p>
            <h3 class="summary-card-value {{ $atualVencida ? 'text-danger' : '' }}">
                {{ $faturaAtual !== null ? $money($faturaAtual['total'] ?? 0) : $money(0) }}
            </h3>
            <p class="summary-card-meta">
                @if ($faturaAtual === null)
                    Nenhuma fatura registrada ainda
                @else
                    <span class="badge rounded-pill {{ $atualVencida ? 'text-bg-danger' : ($atualAberta ? 'text-bg-warning' : 'text-bg-success') }}">
                        {{ $atualVencida ? 'Vencida' : ($atualAberta ? 'Em aberto' : 'Paga') }}
                    </span>
                    · vence em {{ $date($faturaAtual['data_vencimento'] ?? null) }}
                @endif
            </p>
        </article>
        <article class="summary-card">
            <p class="summary-card-eyebrow">Total em aberto</p>
            <h3 class="summary-card-value">{{ $money($totalAberto) }}</h3>
            <p class="summary-card-meta">Somando todas as faturas ainda não pagas</p>
        </article>
        <article class="summary-card">
            <p class="summary-card-eyebrow">Faturas listadas</p>
            <h3 class="summary-card-value">{{ count($faturas) }}</h3>
            <p class="summary-card-meta">{{ $filtrosAtivos !== [] ? 'Com os filtros aplicados' : 'Histórico completo deste cartão' }}</p>
        </article>
    </div>

    <x-list-filters
        form-id="faturasFilterPanel"
        :show-search="false"
        :results-count="count($faturas)"
        results-label="faturas"
        :clear-url="route('financeiro.cartoes-credito.faturas', ['cartaoCredito' => $cartaoId])"
        :has-active-filters="$filtrosAtivos !== []"
        :active-filter-count="count($filtrosAtivos)"
    >
        <div>
            <label for="situacao">Situação</label>
            <select id="situacao" name="situacao" class="form-select">
                <option value="" @selected(($filtros['situacao'] ?? '') === '')>Todas</option>
                <option value="aberta" @selected(($filtros['situacao'] ?? '') === 'aberta')>Em aberto</option>
                <option value="vencida" @selected(($filtros['situacao'] ?? '') === 'vencida')>Vencidas</option>
                <option value="paga" @selected(($filtros['situacao'] ?? '') === 'paga')>Pagas</option>
            </select>
        </div>

        <div>
            <label for="mes">Mês (vencimento)</label>
            <input type="month" id="mes" name="mes" class="form-control" value="{{ $filtros['mes'] ?? '' }}">
        </div>

        <div>
            <label for="vencimento_de">Vencimento de</label>
            <input type="date" id="vencimento_de" name="vencimento_de" class="form-control" value="{{ $filtros['vencimento_de'] ?? '' }}">
        </div>

        <div>
            <label for="vencimento_ate">Vencimento até</label>
            <input type="date" id="vencimento_ate" name="vencimento_ate" class="form-control" value="{{ $filtros['vencimento_ate'] ?? '' }}">
        </div>
    </x-list-filters>

    @if ($faturas === [])
        @include('layouts.partials.empty-state', [
            'icon' => 'bi-receipt',
            'title' => $filtrosAtivos !== [] ? 'Nenhuma fatura com esses filtros' : 'Nenhuma despesa lançada neste cartão',
            'message' => $filtrosAtivos !== []
                ? 'Ajuste o período ou a situação para ver outras faturas deste cartão.'
                : 'Ao lançar uma despesa escolhendo a forma de pagamento "Cartão de crédito" e selecionando este cartão, ela aparecerá aqui na fatura correspondente.',
        ])
    @else
        <section class="surface-table">
            <div class="surface-table-header">
                <div>
                    <h2 class="surface-title">Faturas</h2>
                    <p class="surface-subtitle mb-0">Da fatura atual para as futuras. Cada uma agrupa as compras do ciclo de fechamento até o vencimento.</p>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                        <tr>
                            <th>Fechamento</th>
                            <th>Vencimento</th>
                            <th>Despesas</th>
                            <th>Fixas</th>
                            <th>Variáveis</th>
                            <th>Total</th>
                            <th>Situação</th>
                            <th>Pagamento</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($faturas as $fatura)
                            @php
                                $aberta = ($fatura['status'] ?? '') === 'aberta';
                                $vencida = (bool) ($fatura['vencida'] ?? false);
                                $ehAtual = $faturaAtual !== null
                                    && ($fatura['data_vencimento'] ?? '') === ($faturaAtual['data_vencimento'] ?? null);
                                $emAbertoFatura = (float) ($fatura['total_em_aberto'] ?? 0);
                                $podePagar = $aberta && ($ehAtual || $vencida) && $emAbertoFatura > 0;
                            @endphp
                            <tr @class(['table-active' => $ehAtual])>
                                <td data-label="Fechamento">{{ $date($fatura['data_fechamento'] ?? null) }}</td>
                                <td data-label="Vencimento">
                                    <strong>{{ $date($fatura['data_vencimento'] ?? null) }}</strong>
                                    @if ($ehAtual)
                                        <span class="badge rounded-pill text-bg-primary ms-1">Atual</span>
                                    @endif
                                </td>
                                <td data-label="Despesas">{{ (int) ($fatura['quantidade_despesas'] ?? 0) }}</td>
                                <td data-label="Fixas">{{ $money($fatura['total_fixas'] ?? 0) }}</td>
                                <td data-label="Variáveis">{{ $money($fatura['total_variaveis'] ?? 0) }}</td>
                                <td data-label="Total"><strong>{{ $money($fatura['total'] ?? 0) }}</strong></td>
                                <td data-label="Situação">
                                    <span class="badge rounded-pill {{ $vencida ? 'text-bg-danger' : ($aberta ? 'text-bg-warning' : 'text-bg-success') }}">
                                        {{ $vencida ? 'Vencida' : ($aberta ? 'Aberta' : 'Paga') }}
                                    </span>
                                </td>
                                <td data-label="Pagamento">
                                    {{-- Só a fatura quitada tem data de pagamento;
                                         na aberta a coluna fica vazia de propósito. --}}
                                    {{ $fatura['data_pagamento'] ?? null ? $date($fatura['data_pagamento']) : '—' }}
                                </td>
                                <td data-label="Ações" class="text-end">
                                    <div class="d-flex flex-wrap gap-2 justify-content-end">
                                        <a class="btn btn-sm btn-outline-light"
                                           href="{{ route('financeiro.cartoes-credito.faturas.show', ['cartaoCredito' => $cartaoId, 'dataVencimento' => $fatura['data_vencimento']]) }}">
                                            <i class="bi bi-eye me-1"></i>Ver fatura
                                        </a>
                                        {{-- Pagar só faz sentido na fatura corrente e nas
                                             vencidas: as futuras ainda estão recebendo
                                             compras e o total muda até o fechamento. --}}
                                        @if ($canPagarFatura && $podePagar)
                                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal"
                                                    data-bs-target="#pagarFaturaModal{{ preg_replace('/\D/', '', (string) $fatura['data_vencimento']) }}">
                                                <i class="bi bi-check2-circle me-1"></i>Pagar fatura
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection

@if ($canPagarFatura)
    @push('modals')
        @foreach ($faturas as $fatura)
            @php
                $aberta = ($fatura['status'] ?? '') === 'aberta';
                $vencida = (bool) ($fatura['vencida'] ?? false);
                $ehAtual = $faturaAtual !== null
                    && ($fatura['data_vencimento'] ?? '') === ($faturaAtual['data_vencimento'] ?? null);
                $emAbertoFatura = (float) ($fatura['total_em_aberto'] ?? 0);
            @endphp
            @continue(! $aberta || ! ($ehAtual || $vencida) || $emAbertoFatura <= 0)
            @include('financeiro.cartoes-credito._pagar_fatura_modal', [
                'cartaoId' => $cartaoId,
                'cartao' => $cartao,
                'dataVencimento' => $fatura['data_vencimento'],
                'valorEmAberto' => $emAbertoFatura,
                'accountDataset' => $accountDataset ?? [],
            ])
        @endforeach
    @endpush
@endif

@if ($canPagarFatura && $canCreateDespesa && $faturasPagas !== [])
    @push('modals')
        @include('financeiro.cartoes-credito._despesa_esquecida_modal', [
            'cartaoId' => $cartaoId,
            'faturasPagas' => $faturasPagas,
        ])
    @endpush

    @section('scripts')
        <script src="{{ asset('assets/js/financeiro-despesa-esquecida.js') }}?v={{ filemtime(public_path('assets/js/financeiro-despesa-esquecida.js')) }}"></script>
    @endsection
@endif
