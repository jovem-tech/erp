@props([
    'formId' => 'listFilterPanel',
    'showSearch' => true,
    'searchName' => 'search',
    'searchValue' => '',
    'searchPlaceholder' => 'Buscar...',
    'resultsCount' => 0,
    'resultsLabel' => 'resultados',
    'clearUrl' => '',
    'hasActiveFilters' => false,
    'activeFilterCount' => 0,
    'method' => 'get',
])

{{--
    Moldura padrão de filtros das listagens do desktop (mesmo padrão da tela de
    OS): busca em destaque com botão "Filtrar" ao lado, chip de resultados, e os
    demais filtros (passados via slot) recolhidos atrás do botão "Filtros".
    Reaproveita as classes os-search-block/os-filter-* e desktop-filter-grid já
    existentes no CSS (não são específicas de OS apesar do nome).
--}}
<section class="desktop-form-card mb-4">
    <div class="surface-card-header {{ $showSearch ? '' : 'justify-content-end' }}">
        @if ($showSearch)
            <div class="os-search-block">
                <label for="{{ $formId }}-search">Busca</label>
                <div class="input-group">
                    <input
                        type="text"
                        id="{{ $formId }}-search"
                        name="{{ $searchName }}"
                        form="{{ $formId }}"
                        class="form-control"
                        value="{{ $searchValue }}"
                        placeholder="{{ $searchPlaceholder }}"
                    >
                    <button type="submit" form="{{ $formId }}" class="btn btn-primary">
                        <i class="bi bi-search me-1"></i>Filtrar
                    </button>
                </div>
            </div>
        @endif
        <div class="os-filter-summary-actions">
            <span class="desktop-chip">{{ number_format((int) $resultsCount, 0, ',', '.') }} {{ $resultsLabel }}</span>
            <button
                type="button"
                class="btn btn-outline-light os-filter-toggle {{ $hasActiveFilters ? 'is-active' : '' }}"
                @unless($hasActiveFilters)
                    data-bs-toggle="collapse"
                    data-bs-target="#{{ $formId }}"
                @endunless
                aria-expanded="{{ $hasActiveFilters ? 'true' : 'false' }}"
                aria-controls="{{ $formId }}"
                @if($hasActiveFilters)
                    aria-disabled="true"
                    title="Existem filtros ativos. Use Limpar para resetar os filtros."
                @endif
            >
                <i class="bi bi-funnel me-2"></i>
                <span>{{ $hasActiveFilters ? 'Filtros ativos' : 'Filtros' }}</span>
                @if ($hasActiveFilters)
                    <span class="os-filter-active-count" aria-label="{{ $activeFilterCount }} filtros ativos">{{ $activeFilterCount }}</span>
                @endif
            </button>

            {{-- Slot opcional: botões de ação do cabeçalho da página (ex.: "Novo X",
                 "Mais ações") na mesma linha do chip/Filtros, quando a página não tem
                 um card de título próprio (ver servicos/index.blade.php). --}}
            {{ $actions ?? '' }}
        </div>
    </div>

    <form method="{{ $method }}" class="desktop-filter-grid collapse {{ $hasActiveFilters ? 'show' : '' }}" id="{{ $formId }}">
        {{ $slot }}

        <div class="field-actions">
            <button type="submit" class="btn btn-primary flex-fill">
                <i class="bi bi-search me-2"></i>
                Filtrar
            </button>
            @if ($clearUrl !== '')
                <a href="{{ $clearUrl }}" class="btn btn-outline-light">Limpar</a>
            @endif
        </div>
    </form>
</section>
