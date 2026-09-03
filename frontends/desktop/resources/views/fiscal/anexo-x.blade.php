@extends('layouts.app')

@section('content')
    @php
        $meses = $resumo['meses'] ?? [];
        $acumulado = $resumo['acumulado'][$resumo['regime_que_conta_para_o_limite'] ?? 'competencia'] ?? null;
        $totais = $resumo['totais'][$regime] ?? [];
        $mostrarIndustria = (bool) ($resumo['mostrar_industria'] ?? false);
        $regimeDoLimite = $resumo['regime_que_conta_para_o_limite'] ?? 'competencia';
        $fmt = static fn ($valor) => 'R$ ' . number_format((float) ($valor ?? 0), 2, ',', '.');
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Fiscal</p>
            <h2 class="surface-title fs-3 mb-2">Anexo X — Relatório Mensal das Receitas Brutas <x-favorite-toggle /></h2>
            <p class="surface-subtitle mb-0">
                Obrigação do MEI (Resolução CGSN nº 140/2018, art. 106): preencher até o dia 20 do mês seguinte
                e guardar pelo prazo decadencial. Ano-calendário {{ $resumo['ano'] ?? '' }}.
            </p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <div class="dropdown os-actions-dropdown">
                <button type="button" class="btn btn-outline-light dropdown-toggle os-actions-toggle"
                        data-bs-toggle="dropdown" aria-expanded="false">
                    Mais ações
                </button>

                <div class="dropdown-menu dropdown-menu-end os-actions-menu">
                    <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#modalBaixarAnexoX">
                        <i class="bi bi-file-earmark-pdf me-2"></i>Baixar Anexo X (PDF)
                    </button>
                    <a href="{{ route('fiscal.anexo-x.documentos-pdf', ['competencia' => sprintf('%04d-%02d', $resumo['ano'] ?? now()->year, now()->month)]) }}"
                       target="_blank" rel="noopener" class="dropdown-item">
                        <i class="bi bi-paperclip me-2"></i>Relação de documentos emitidos
                    </a>
                    <a href="{{ route('fiscal.anexo-x.ajuda') }}" class="dropdown-item">
                        <i class="bi bi-question-circle me-2"></i>Ajuda
                    </a>
                </div>
            </div>
        </div>
    </div>

    @if (! empty($resumo['aviso_regime_tributario']))
        <div class="alert alert-warning d-flex gap-2 align-items-start" role="alert">
            <i class="bi bi-exclamation-triangle-fill mt-1"></i>
            <div>{{ $resumo['aviso_regime_tributario'] }}</div>
        </div>
    @endif

    <section class="desktop-form-card mb-4">
        <form method="get" class="d-flex flex-wrap align-items-end gap-3">
            <input type="hidden" name="regime" value="{{ $regime }}">
            <div class="d-flex align-items-center gap-2">
                <a href="{{ route('fiscal.anexo-x', ['ano' => $anoAnterior, 'regime' => $regime]) }}"
                   class="btn btn-outline-light" aria-label="Ano anterior">
                    <i class="bi bi-chevron-left"></i>
                </a>
                <div>
                    <label class="form-label mb-1" for="ano">Ano-calendário</label>
                    <input type="number" id="ano" name="ano" class="form-control text-center" style="width: 120px;"
                           min="2000" max="{{ now()->year + 1 }}" step="1" value="{{ $resumo['ano'] ?? now()->year }}">
                </div>
                <a href="{{ route('fiscal.anexo-x', ['ano' => $anoProximo, 'regime' => $regime]) }}"
                   class="btn btn-outline-light align-self-end" aria-label="Próximo ano">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </div>

            <button type="submit" class="btn btn-primary align-self-end">
                <i class="bi bi-search me-2"></i>Atualizar
            </button>
        </form>
    </section>

    <div class="desktop-grid desktop-grid-two mb-4 anexo-x-topo">
        @include('fiscal.partials._anexo-x-acumulado', ['acumulado' => $acumulado, 'regimeDoLimite' => $regimeDoLimite])
        @include('fiscal.partials._anexo-x-grafico')
    </div>

    @include('fiscal.partials._anexo-x-tabela', [
        'meses' => $meses,
        'totais' => $totais,
        'mostrarIndustria' => $mostrarIndustria,
        'regimeDoLimite' => $regimeDoLimite,
    ])
@endsection

@push('modals')
    @include('fiscal.partials._anexo-x-modais')
@endpush

@section('scripts')
    <script>
        window.__DESKTOP_ANEXO_X = {!! \Illuminate\Support\Js::from([
            'resumo' => $resumo,
            'regime' => $regime,
            'podeEncerrar' => $podeEncerrar,
            'podeEditar' => $podeEditar,
            'rotas' => [
                'operacoes' => route('fiscal.anexo-x.operacoes'),
                'ajustes' => route('fiscal.anexo-x.ajustes'),
                'ajustesStore' => route('fiscal.anexo-x.ajustes.store'),
                'ajustesCancelar' => route('fiscal.anexo-x.ajustes.cancelar', ['ajuste' => '__ID__']),
                'pdf' => route('fiscal.anexo-x.pdf'),
            ],
        ]) !!};
    </script>
    <script src="{{ asset('assets/libs/chartjs/chart.umd.min.js') }}"></script>
    <script src="{{ asset('assets/js/anexo-x-chart.js') }}?v={{ filemtime(public_path('assets/js/anexo-x-chart.js')) }}"></script>
    <script src="{{ asset('assets/js/anexo-x.js') }}?v={{ filemtime(public_path('assets/js/anexo-x.js')) }}"></script>
@endsection
