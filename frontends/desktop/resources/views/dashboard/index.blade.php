@extends('layouts.app')

@section('content')
    @php
        // O controller renderiza a casca e o dashboard.js hidrata com
        // /dashboard/dados — por isso aqui só chegam os filtros e a agenda. Os
        // painéis nascem com skeleton, nunca com zeros de placeholder.
        $dashboard = is_array($dashboard ?? null) ? $dashboard : [];
        $heroCard = is_array($dashboard['heroCard'] ?? null) ? $dashboard['heroCard'] : [];
        $secondaryCard = is_array($dashboard['secondaryCard'] ?? null) ? $dashboard['secondaryCard'] : [];
        $contextCard = is_array($dashboard['contextCard'] ?? null) ? $dashboard['contextCard'] : [];
        $filters = is_array($dashboard['filters'] ?? null) ? $dashboard['filters'] : [];
        $years = is_array($filters['years'] ?? null) ? $filters['years'] : [];
        $equipmentYears = is_array($filters['equipmentYears'] ?? null) ? $filters['equipmentYears'] : [];
    @endphp

    {{-- Wrapper de escopo: .dashboard-panel também é usada pelas telas de
         ajuda (vendas, caixa, devoluções…), então os ajustes de espaçamento
         desta etapa ficam presos aqui em vez de vazar para elas. --}}
    <div class="dashboard-page">

    @include('dashboard.partials._hero')

    @include('dashboard.partials._attention')

    @include('dashboard.partials._kpi-grid')

    {{-- Os dois gráficos grandes dividem a linha em telas largas. Empilhados,
         somavam mais de uma tela cheia entre os indicadores e a tabela de OS, e
         o usuário precisava rolar para saber como a operação estava. --}}
    <section class="dashboard-charts-grid mb-4">
        @include('dashboard.partials._operations-chart')
        @include('dashboard.partials._equipment-chart')
    </section>

    <section class="dashboard-secondary-grid mb-4">
        @include('dashboard.partials._status-overview')
        @include('dashboard.partials._financial-summary')
    </section>

    @include('dashboard.partials._recent-orders')

    @include('dashboard.partials._stock-alert')

    </div>

    @php
        $dashboardBootstrap = [
            'dataUrl' => route('dashboard.data'),
        ];
    @endphp

    <script>
        window.__DESKTOP_DASHBOARD = {!! \Illuminate\Support\Js::from($dashboardBootstrap) !!};
    </script>
@endsection

@section('scripts')
    <script src="{{ asset('assets/libs/chartjs/chart.umd.min.js') }}"></script>
    <script src="{{ asset('assets/js/dashboard-charts.js') }}"></script>
    <script src="{{ asset('assets/js/dashboard.js') }}"></script>
@endsection

@push('modals')
    <div class="modal fade" id="dashboardOsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
            <div class="modal-content modal-shell dashboard-os-modal-shell">
                <div class="modal-header">
                    <div>
                        <p class="desktop-eyebrow mb-1">Ordem de serviço</p>
                        <h5 class="modal-title" id="dashboardOsModalTitle">Pré-visualização</h5>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <a href="#" id="dashboardOsModalOpenFull" target="_blank" rel="noreferrer" class="btn btn-soft btn-sm">
                            Abrir página cheia
                        </a>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                </div>

                <div class="modal-body p-0 position-relative">
                    <div id="dashboardOsModalLoading" class="dashboard-os-modal-loading">
                        <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                        <span>Carregando OS...</span>
                    </div>
                    <iframe
                        id="dashboardOsModalFrame"
                        class="dashboard-os-modal-frame"
                        title="Pré-visualização da ordem de serviço"
                        loading="lazy"
                    ></iframe>
                </div>
            </div>
        </div>
    </div>
@endpush
