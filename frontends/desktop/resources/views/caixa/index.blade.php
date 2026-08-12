@extends('layouts.app')

@section('content')
    @php
        $sessao = is_array($sessao ?? null) ? $sessao : null;
        $aberto = $sessao !== null;
        $money = static fn ($value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
    @endphp

    <section class="surface-card">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h1 class="surface-title mb-1">Caixa</h1>
                <p class="surface-subtitle mb-0">
                    @if ($aberto)
                        Turno aberto por {{ $sessao['operador_nome'] ?? '—' }}
                        @if (! empty($sessao['aberto_em']))
                            em {{ \Illuminate\Support\Carbon::parse($sessao['aberto_em'])->format('d/m/Y H:i') }}
                        @endif
                    @else
                        Nenhum caixa aberto no momento.
                    @endif
                </p>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('caixa.historico') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-clock-history me-2"></i>Histórico
                </a>
                <a href="{{ route('caixa.help') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-question-circle me-2"></i>Ajuda
                </a>
            </div>
        </div>
    </section>

    @if (! $aberto)
        <section class="surface-card mt-3 text-center py-5">
            <i class="bi bi-cash-stack fs-1 d-block mb-3 text-secondary"></i>
            <h2 class="surface-title fs-5">Caixa fechado</h2>
            <p class="text-secondary mb-4">
                Abra o turno declarando o troco que está na gaveta.
                @if (empty($conta))
                    <br>
                    <small>A conta "Caixa da loja" será criada nesta primeira abertura.</small>
                @endif
            </p>

            @if (\App\Support\DesktopSession::can('caixa', 'criar'))
                <button type="button" class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#caixaAbrirModal">
                    <i class="bi bi-unlock me-2"></i>Abrir caixa
                </button>
            @endif
        </section>
    @else
        <section class="row g-3 mt-0">
            @foreach ([
                ['rotulo' => 'Abertura (troco)', 'valor' => $money($sessao['valor_abertura'] ?? 0), 'icone' => 'bi-unlock'],
                ['rotulo' => 'Vendas em dinheiro', 'valor' => $money($sessao['total_vendas_dinheiro'] ?? 0), 'icone' => 'bi-cash-coin'],
                ['rotulo' => 'Suprimentos', 'valor' => $money($sessao['total_suprimentos'] ?? 0), 'icone' => 'bi-plus-circle'],
                ['rotulo' => 'Sangrias', 'valor' => $money($sessao['total_sangrias'] ?? 0), 'icone' => 'bi-dash-circle'],
            ] as $card)
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="surface-card h-100">
                        <div class="d-flex align-items-center gap-2 text-secondary">
                            <i class="bi {{ $card['icone'] }}"></i>
                            <small>{{ $card['rotulo'] }}</small>
                        </div>
                        <div class="fs-5 fw-semibold mt-1">{{ $card['valor'] }}</div>
                    </div>
                </div>
            @endforeach
        </section>

        {{-- O valor esperado NÃO aparece aqui de propósito: a conferência é
             cega, e vê-lo antes de contar transformaria o fechamento em
             "digitar o número que o sistema quer" (specs/028-caixa-sessoes). --}}
        <section class="surface-card mt-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h2 class="surface-title fs-6 mb-1">
                        {{ (int) ($sessao['quantidade_vendas'] ?? 0) }} venda(s) em dinheiro neste turno
                    </h2>
                    @if (! empty($sessao['abertura_automatica']))
                        <p class="text-warning small mb-0">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Turno aberto automaticamente pela primeira venda. Confira o valor de abertura.
                        </p>
                    @endif
                </div>

                <div class="d-flex flex-wrap gap-2">
                    @if (\App\Support\DesktopSession::can('caixa', 'editar'))
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#caixaSuprimentoModal">
                            <i class="bi bi-plus-circle me-1"></i>Suprimento
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#caixaSangriaModal">
                            <i class="bi bi-dash-circle me-1"></i>Sangria
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#caixaAberturaModal">
                            <i class="bi bi-pencil me-1"></i>Corrigir abertura
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#caixaFecharModal">
                            <i class="bi bi-lock me-1"></i>Fechar caixa
                        </button>
                    @endif
                </div>
            </div>
        </section>

        <section class="surface-table mt-3">
            <div class="surface-table-header">
                <h2 class="surface-title">Sangrias e suprimentos</h2>
            </div>

            @if (! empty($sessao['movimentos']))
                <div class="table-responsive">
                    <table class="table table-stack align-middle">
                        <thead>
                        <tr>
                            <th>Quando</th>
                            <th>Tipo</th>
                            <th>Motivo</th>
                            <th>Responsável</th>
                            <th class="text-end">Valor</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($sessao['movimentos'] as $movimento)
                            <tr>
                                <td data-label="Quando">
                                    {{ ! empty($movimento['created_at']) ? \Illuminate\Support\Carbon::parse($movimento['created_at'])->format('d/m/Y H:i') : '-' }}
                                </td>
                                <td data-label="Tipo">
                                    <span class="badge {{ ($movimento['tipo'] ?? '') === 'sangria' ? 'bg-danger' : 'bg-success' }}">
                                        {{ $movimento['tipo_label'] ?? '' }}
                                    </span>
                                </td>
                                <td data-label="Motivo">{{ $movimento['motivo'] ?? '' }}</td>
                                <td data-label="Responsável">{{ $movimento['responsavel_nome'] ?? '-' }}</td>
                                <td data-label="Valor" class="text-end fw-semibold">{{ $money($movimento['valor'] ?? 0) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-secondary px-3 pb-3 mb-0">Nenhuma sangria ou suprimento neste turno.</p>
            @endif
        </section>
    @endif
@endsection

@push('modals')
    @if (! $aberto && \App\Support\DesktopSession::can('caixa', 'criar'))
        @include('caixa._abrir_modal')
    @endif

    @if ($aberto && \App\Support\DesktopSession::can('caixa', 'editar'))
        @include('caixa._movimento_modal', [
            'id' => 'caixaSangriaModal',
            'tipo' => 'sangria',
            'titulo' => 'Registrar sangria',
            'descricao' => 'Dinheiro que sai da gaveta. Com conta de destino, gera uma transferência real.',
            'sessao' => $sessao,
            'contasDestino' => $contasDestino,
        ])
        @include('caixa._movimento_modal', [
            'id' => 'caixaSuprimentoModal',
            'tipo' => 'suprimento',
            'titulo' => 'Registrar suprimento',
            'descricao' => 'Dinheiro que entra na gaveta, como reforço de troco.',
            'sessao' => $sessao,
            'contasDestino' => [],
        ])
        @include('caixa._abertura_modal', ['sessao' => $sessao])
        @include('caixa._fechar_modal', ['sessao' => $sessao])
    @endif
@endpush
