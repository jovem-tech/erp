@php
    $canCreateOrder = \App\Support\DesktopSession::can('os', 'criar');
    $canViewOrders = \App\Support\DesktopSession::can('os', 'visualizar');
@endphp

<section class="dashboard-panel mb-4" data-dashboard-orders-panel>
    <div class="dashboard-panel-head dashboard-panel-head-end">
        <div>
            <h2>Últimas Ordens de Serviço</h2>
            <p>Visão operacional pronta para técnico e gestão.</p>
        </div>

        <div class="dashboard-panel-actions">
            @if ($canViewOrders)
                <a href="{{ route('orders.index') }}" class="btn btn-outline-light btn-sm">
                    Ver todas
                </a>
            @endif

            @if ($canCreateOrder)
                <a href="{{ route('orders.create') }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i>
                    Nova OS
                </a>
            @endif
        </div>
    </div>

    <div data-dashboard-orders-slot>
        <div class="table-responsive" aria-hidden="true">
            <table class="table align-middle dashboard-orders-table">
                <tbody>
                @for ($i = 0; $i < 5; $i++)
                    <tr>
                        <td colspan="7"><span class="dashboard-skeleton dashboard-skeleton-row"></span></td>
                    </tr>
                @endfor
                </tbody>
            </table>
        </div>
    </div>
</section>
