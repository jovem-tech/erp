<section class="dashboard-panel dashboard-low-stock mb-4" data-dashboard-low-stock-panel>
    <div class="dashboard-panel-head dashboard-panel-head-end">
        <div>
            <h2>Alerta de estoque baixo</h2>
            <p>Peças com quantidade igual ou abaixo do mínimo cadastrado.</p>
        </div>

        @if (\App\Support\DesktopSession::can('estoque', 'visualizar'))
            <a href="{{ route('estoque.index', ['estoque_baixo' => 1]) }}" class="btn btn-outline-light btn-sm" data-dashboard-low-stock-all hidden>
                Ver todos
            </a>
        @endif
    </div>

    <div data-dashboard-low-stock-slot>
        <div class="dashboard-low-stock-list" aria-hidden="true">
            @for ($i = 0; $i < 3; $i++)
                <article class="dashboard-low-stock-item">
                    <div>
                        <span class="dashboard-skeleton dashboard-skeleton-line"></span>
                        <span class="dashboard-skeleton dashboard-skeleton-inline"></span>
                    </div>
                </article>
            @endfor
        </div>
    </div>
</section>
