@extends('layouts.app')

@section('content')
    @php
        $usesOpenQueueScope = trim((string) ($filters['status_scope'] ?? '')) === 'open';
        $selectedGrupoMacroMulti = is_array($filters['grupo_macro_multi'] ?? null) ? $filters['grupo_macro_multi'] : [];
        $selectedStatusMulti = is_array($filters['status_multi'] ?? null) ? $filters['status_multi'] : [];
        $hasAdvancedFilters = (int) ($filters['technician_id'] ?? 0) > 0
            || trim((string) ($filters['data_abertura_de'] ?? '')) !== ''
            || trim((string) ($filters['data_abertura_ate'] ?? '')) !== ''
            || trim((string) ($filters['valor_min'] ?? '')) !== ''
            || trim((string) ($filters['valor_max'] ?? '')) !== ''
            || $selectedGrupoMacroMulti !== []
            || $selectedStatusMulti !== [];
        $hasBasicFilters = trim((string) ($filters['search'] ?? '')) !== ''
            || trim((string) ($filters['status'] ?? '')) !== ''
            || trim((string) ($filters['grupo_macro'] ?? '')) !== '';
        $hasAnyFilters = $hasBasicFilters || $hasAdvancedFilters;
        $activeFilterCount = count(array_filter([
            trim((string) ($filters['search'] ?? '')) !== '',
            trim((string) ($filters['status'] ?? '')) !== '',
            trim((string) ($filters['grupo_macro'] ?? '')) !== '',
            (int) ($filters['technician_id'] ?? 0) > 0,
            trim((string) ($filters['data_abertura_de'] ?? '')) !== '',
            trim((string) ($filters['data_abertura_ate'] ?? '')) !== '',
            trim((string) ($filters['valor_min'] ?? '')) !== '',
            trim((string) ($filters['valor_max'] ?? '')) !== '',
            $selectedGrupoMacroMulti !== [],
            $selectedStatusMulti !== [],
        ]));
        $activeAdvancedFilterCount = count(array_filter([
            (int) ($filters['technician_id'] ?? 0) > 0,
            trim((string) ($filters['data_abertura_de'] ?? '')) !== '',
            trim((string) ($filters['data_abertura_ate'] ?? '')) !== '',
            trim((string) ($filters['valor_min'] ?? '')) !== '',
            trim((string) ($filters['valor_max'] ?? '')) !== '',
            $selectedGrupoMacroMulti !== [],
            $selectedStatusMulti !== [],
        ]));

        // Pares de macrofases que definem os dois atalhos de "situacao da OS" dos
        // filtros avancados: juntos cobrem os 10 grupos macro do fluxo (ver
        // App\Models\OrderStatus no backend). "Em andamento" e tudo que ainda nao
        // chegou a um desfecho; "Sem reparo" agrupa os tres desfechos possiveis
        // (encerrado, cancelado ou finalizado sem reparo).
        $macroPresetEmAndamento = ['recepcao', 'diagnostico', 'orcamento', 'execucao', 'qualidade', 'interrupcao', 'concluido'];
        $macroPresetSemReparo = ['finalizado_sem_reparo', 'encerrado', 'cancelado'];
        $isMacroPresetActive = static function (array $preset) use ($selectedGrupoMacroMulti): bool {
            return count($preset) === count($selectedGrupoMacroMulti)
                && array_diff($preset, $selectedGrupoMacroMulti) === [];
        };

        $macroGroupLabel = static fn (string $group): string => ucfirst(str_replace('_', ' ', $group));

        $statusByMacroGroup = [];
        foreach (($macroGroupOptions ?? []) as $macroGroupCode) {
            $statusByMacroGroup[$macroGroupCode] = array_values(array_filter(
                $statusOptions ?? [],
                static fn (array $statusOption): bool => ($statusOption['grupo_macro'] ?? '') === $macroGroupCode
            ));
        }

        $statusPlaceholder = $usesOpenQueueScope ? 'Padrão: em posse da assistência' : 'Todos os status';

        $canEditOrder = \App\Support\DesktopSession::can('os', 'editar');
    @endphp

    <section class="desktop-form-card mb-4">
        <div class="surface-card-header">
            <div class="os-search-block">
                <label for="search">Busca</label>
                {{-- Busca + botao Filtrar vivem no cabecalho, mas usam o atributo
                     form="osFilterPanel" para submeter o form de filtros junto
                     (status, itens por pagina e filtros avancados, mesmo recolhidos). --}}
                <div class="input-group">
                    <input type="text" id="search" name="search" form="osFilterPanel" class="form-control" value="{{ $filters['search'] ?? '' }}" placeholder="OS, cliente, série ou resumo técnico">
                    <button type="submit" form="osFilterPanel" class="btn btn-primary">
                        <i class="bi bi-search me-1"></i>Filtrar
                    </button>
                </div>
            </div>
            <div class="os-filter-summary-actions">
                <span class="desktop-chip" id="osResultsCount">{{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} resultados</span>
                @if ($canEditOrder)
                    <span class="desktop-chip" id="osBulkSelectionCount">0 selecionadas</span>
                    <x-list-actions label="Mais ações" size="">
                        <li>
                            <button type="button" class="dropdown-item" id="osBulkClosureTrigger" disabled>
                                <i class="bi bi-box-seam me-2"></i>Dar baixa em lote
                            </button>
                        </li>
                        <li>
                            <button type="button" class="dropdown-item" id="osBulkStatusTrigger" disabled>
                                <i class="bi bi-arrow-left-right me-2"></i>Alterar status em lote
                            </button>
                        </li>
                    </x-list-actions>
                @endif
                <button
                    type="button"
                    class="btn btn-outline-light os-filter-toggle {{ $hasAnyFilters ? 'is-active' : '' }}"
                    @unless($hasAnyFilters)
                        data-bs-toggle="collapse"
                        data-bs-target="#osFilterPanel"
                    @endunless
                    aria-expanded="{{ $hasAnyFilters ? 'true' : 'false' }}"
                    aria-controls="osFilterPanel"
                    @if($hasAnyFilters)
                        aria-disabled="true"
                        title="Existem filtros ativos. Use Limpar para resetar os filtros."
                    @endif
                >
                    <i class="bi bi-funnel me-2"></i>
                    <span>{{ $hasAnyFilters ? 'Filtros ativos' : 'Filtros' }}</span>
                    @if ($hasAnyFilters)
                        <span class="os-filter-active-count" aria-label="{{ $activeFilterCount }} filtros ativos">{{ $activeFilterCount }}</span>
                    @endif
                </button>
            </div>
        </div>

        <form method="get" class="desktop-filter-grid collapse {{ $hasAnyFilters ? 'show' : '' }}" id="osFilterPanel">
            @if ((int) ($filters['client_id'] ?? 0) > 0)
                <input type="hidden" name="client_id" value="{{ $filters['client_id'] }}">
            @endif

            @if ((int) ($filters['equipment_id'] ?? 0) > 0)
                <input type="hidden" name="equipment_id" value="{{ $filters['equipment_id'] }}">
            @endif

            <div>
                <label for="status">Status</label>
                @if (! empty($statusOptions))
                    <select id="status" name="status" class="form-select" data-select2-placeholder="{{ $statusPlaceholder }}" data-select2-allow-clear="true">
                        <option value="">{{ $statusPlaceholder }}</option>
                        @foreach ($statusOptions as $statusOption)
                            <option value="{{ $statusOption['codigo'] ?? '' }}" data-macro="{{ $statusOption['grupo_macro'] ?? '' }}" @selected(($filters['status'] ?? '') === ($statusOption['codigo'] ?? ''))>
                                {{ $statusOption['nome'] ?? ($statusOption['codigo'] ?? '') }}
                            </option>
                        @endforeach
                    </select>
                @else
                    <input type="text" id="status" name="status" class="form-control" value="{{ $filters['status'] ?? '' }}" placeholder="Ex.: em_diagnostico">
                @endif
            </div>

            <div>
                <label for="grupo_macro">Macrofase</label>
                @if (! empty($macroGroupOptions))
                    <select id="grupo_macro" name="grupo_macro" class="form-select" data-select2-placeholder="Todas as macrofases" data-select2-allow-clear="true">
                        <option value="">Todas as macrofases</option>
                        @foreach ($macroGroupOptions as $macroGroup)
                            <option value="{{ $macroGroup }}" @selected(($filters['grupo_macro'] ?? '') === $macroGroup)>
                                {{ ucfirst(str_replace('_', ' ', $macroGroup)) }}
                            </option>
                        @endforeach
                    </select>
                @else
                    <input type="text" id="grupo_macro" name="grupo_macro" class="form-control" value="{{ $filters['grupo_macro'] ?? '' }}" placeholder="Ex.: execucao">
                @endif
            </div>

            <div>
                <label for="per_page">Itens por página</label>
                <select id="per_page" name="per_page" class="form-select" data-os-per-page>
                    @foreach ([15, 30, 60, 100, 500] as $size)
                        <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 15) === $size)>{{ $size }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field-actions">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="bi bi-search me-2"></i>
                    Filtrar
                </button>
                <button type="button" class="btn btn-outline-light" id="osFilterClearButton" data-reset-url="{{ route('orders.index') }}">Limpar</button>
            </div>

            <div class="desktop-filter-advanced-toggle">
                <button
                    type="button"
                    class="btn btn-sm btn-outline-light {{ $hasAdvancedFilters ? 'is-active' : '' }}"
                    @unless($hasAdvancedFilters)
                        data-bs-toggle="collapse"
                        data-bs-target="#osAdvancedFilters"
                    @endunless
                    aria-expanded="{{ $hasAdvancedFilters ? 'true' : 'false' }}"
                    aria-controls="osAdvancedFilters"
                    @if($hasAdvancedFilters)
                        aria-disabled="true"
                        title="Existem filtros avançados ativos. Use Limpar para resetar os filtros."
                    @endif
                >
                    <i class="bi bi-sliders me-2"></i>
                    <span>{{ $hasAdvancedFilters ? 'Filtros avançados ativos' : 'Filtros avançados' }}</span>
                    @if ($hasAdvancedFilters)
                        <span class="os-filter-active-count" aria-label="{{ $activeAdvancedFilterCount }} filtros avançados ativos">{{ $activeAdvancedFilterCount }}</span>
                    @endif
                </button>
            </div>

            <div id="osAdvancedFilters" class="collapse {{ $hasAdvancedFilters ? 'show' : '' }} desktop-filter-advanced-panel">
                <div class="desktop-filter-grid">
                    <div>
                        <label for="technician_id">Técnico</label>
                        @if (! empty($technicians))
                            <select id="technician_id" name="technician_id" class="form-select">
                                <option value="">Todos os técnicos</option>
                                @foreach ($technicians as $technician)
                                    <option value="{{ $technician['id'] ?? 0 }}" @selected((int) ($filters['technician_id'] ?? 0) === (int) ($technician['id'] ?? 0))>
                                        {{ $technician['nome'] ?? ('Técnico #' . ($technician['id'] ?? '')) }}
                                    </option>
                                @endforeach
                            </select>
                        @else
                            <select id="technician_id" name="technician_id" class="form-select" disabled>
                                <option value="">Sem técnicos disponíveis</option>
                            </select>
                        @endif
                    </div>

                    <div>
                        <label for="data_abertura_de">Abertura de</label>
                        <input type="date" id="data_abertura_de" name="data_abertura_de" class="form-control" value="{{ $filters['data_abertura_de'] ?? '' }}">
                    </div>

                    <div>
                        <label for="data_abertura_ate">Abertura até</label>
                        <input type="date" id="data_abertura_ate" name="data_abertura_ate" class="form-control" value="{{ $filters['data_abertura_ate'] ?? '' }}">
                    </div>

                    <div>
                        <label for="valor_min">Valor mínimo</label>
                        <input type="number" step="0.01" min="0" id="valor_min" name="valor_min" class="form-control" value="{{ $filters['valor_min'] ?? '' }}" placeholder="R$ 0,00">
                    </div>

                    <div>
                        <label for="valor_max">Valor máximo</label>
                        <input type="number" step="0.01" min="0" id="valor_max" name="valor_max" class="form-control" value="{{ $filters['valor_max'] ?? '' }}" placeholder="R$ 0,00">
                    </div>
                </div>

                @if (! empty($macroGroupOptions))
                    <div class="desktop-order-quickfilters">
                        <div class="desktop-order-quickfilters-header">
                            <span class="desktop-order-quickfilters-label">Situação da OS</span>
                            <div class="desktop-order-quickfilters-actions">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-light desktop-order-quickset-btn {{ $isMacroPresetActive($macroPresetEmAndamento) ? 'is-active' : '' }}"
                                    data-order-macro-preset="{{ implode(',', $macroPresetEmAndamento) }}"
                                >
                                    <i class="bi bi-arrow-repeat me-1"></i>Abertas · Em andamento
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-light desktop-order-quickset-btn {{ $isMacroPresetActive($macroPresetSemReparo) ? 'is-active' : '' }}"
                                    data-order-macro-preset="{{ implode(',', $macroPresetSemReparo) }}"
                                >
                                    <i class="bi bi-x-circle me-1"></i>Abertas · Sem reparo
                                </button>
                            </div>
                        </div>

                        <div class="desktop-order-multiselect-grid">
                            <div>
                                <label>Macrofases (seleção múltipla)</label>
                                <div class="desktop-search-scope-checklist desktop-order-macro-checklist" data-order-macro-checklist>
                                    @foreach ($macroGroupOptions as $macroGroupCode)
                                        <label class="desktop-search-scope-item">
                                            <input
                                                type="checkbox"
                                                class="form-check-input"
                                                name="grupo_macro_multi[]"
                                                value="{{ $macroGroupCode }}"
                                                data-order-macro-checkbox
                                                @checked(in_array($macroGroupCode, $selectedGrupoMacroMulti, true))
                                            >
                                            <span>{{ $macroGroupLabel($macroGroupCode) }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            @if (! empty($statusOptions))
                                <div>
                                    <label>Status (seleção múltipla)</label>
                                    <div class="desktop-order-status-checklist">
                                        @foreach ($statusByMacroGroup as $macroGroupCode => $groupStatuses)
                                            @continue(empty($groupStatuses))
                                            <div class="desktop-order-status-group">
                                                <span class="desktop-order-status-group-label">{{ $macroGroupLabel($macroGroupCode) }}</span>
                                                @foreach ($groupStatuses as $statusOption)
                                                    <label class="desktop-search-scope-item">
                                                        <input
                                                            type="checkbox"
                                                            class="form-check-input"
                                                            name="status_multi[]"
                                                            value="{{ $statusOption['codigo'] ?? '' }}"
                                                            @checked(in_array($statusOption['codigo'] ?? '', $selectedStatusMulti, true))
                                                        >
                                                        <span>{{ $statusOption['nome'] ?? ($statusOption['codigo'] ?? '') }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </form>
    </section>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Ordens de Serviço</h2>
            </div>
        </div>

        <div id="osTableContainer" data-os-table-container>
            @include('orders._table', ['orders' => $orders, 'pagination' => $pagination, 'filters' => $filters])
        </div>
    </section>
@endsection

@section('scripts')
    <script>
        window.__DESKTOP_ORDER_LIST = {!! json_encode([
            'channelName'        => 'orders',
            'broadcastAuthUrl'   => route('desktop.broadcasting.auth'),
            'pusherKey'          => env('REVERB_APP_KEY', ''),
            'pusherHost'         => env('REVERB_HOST', 'localhost'),
            'pusherPort'         => (int) env('REVERB_PORT', 8090),
            'pusherScheme'       => env('REVERB_SCHEME', 'http'),
            'csrfToken'          => csrf_token(),
            'hasFilters'         => $hasAnyFilters,
            'ordersShowUrlBase'  => rtrim(route('orders.show', ['order' => 0]), '0'),
            'canCreateBudget'    => \App\Support\DesktopSession::can('orcamentos', 'criar'),
            'canEditOrder'       => \App\Support\DesktopSession::can('os', 'editar'),
            'budgetCreateUrlBase'   => route('orcamentos.create') . '?os_id=',
            'ordersEditUrlTemplate' => route('orders.edit', ['order' => '__ORDER__']),
            'ordersClosureUrlTemplate' => route('orders.closure.show', ['order' => '__ORDER__']),
            'ordersStatusUpdateUrlTemplate' => route('orders.status.update', ['order' => '__ORDER__']),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};

        window.__DESKTOP_STATUS_MODAL = {
            statusContextUrlTemplate: '{{ route('orders.status.context', ['order' => '__ORDER__']) }}',
            statusUpdateUrlTemplate: '{{ route('orders.status.update', ['order' => '__ORDER__']) }}',
            proceduresUrlTemplate: '{{ route('orders.procedures.store', ['order' => '__ORDER__']) }}',
            mapDataUrlTemplate: '{{ route('orders.map.data', ['order' => '__ORDER__']) }}',
            closureUrlTemplate: '{{ route('orders.closure.show', ['order' => '__ORDER__']) }}',
            csrfToken: '{{ csrf_token() }}',
        };
        window.__DESKTOP_CANCEL_CLOSURE_MODAL = {
            cancelUrlTemplate: '{{ route('orders.closure.cancel', ['order' => '__ORDER__']) }}',
            csrfToken: '{{ csrf_token() }}',
        };
        window.__DESKTOP_BATCH_CLOSURE_MODAL = {
            batchClosureUrl: '{{ route('orders.closure.batch') }}',
            csrfToken: '{{ csrf_token() }}',
        };
        window.__DESKTOP_BATCH_STATUS_MODAL = {
            batchStatusUrl: '{{ route('orders.status.batch') }}',
            csrfToken: '{{ csrf_token() }}',
        };

        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('osFilterPanel');
            const statusSelect = document.getElementById('status');
            const macroSelect = document.getElementById('grupo_macro');
            const clearButton = document.getElementById('osFilterClearButton');

            if (!(form instanceof HTMLFormElement)
                || !(statusSelect instanceof HTMLSelectElement)
                || !(macroSelect instanceof HTMLSelectElement)) {
                return;
            }

            const statusPlaceholder = statusSelect.querySelector('option[value=""]')?.textContent?.trim() || 'Todos os status';
            const macroPlaceholder = macroSelect.querySelector('option[value=""]')?.textContent?.trim() || 'Todas as macrofases';

            const statuses = Array.from(statusSelect.options)
                .filter((option) => option.value !== '')
                .map((option) => ({
                    value: option.value,
                    label: option.textContent?.trim() || option.value,
                    macro: option.dataset.macro || '',
                }));

            const macros = Array.from(macroSelect.options)
                .filter((option) => option.value !== '')
                .map((option) => ({
                    value: option.value,
                    label: option.textContent?.trim() || option.value,
                }));

            if (statuses.length === 0 || macros.length === 0) {
                return;
            }

            let isSyncing = false;
            let lastStatusValue = statusSelect.value;
            let lastMacroValue = macroSelect.value;

            const rebuildSelect = (select, placeholder, items, selectedValue) => {
                select.replaceChildren(new Option(placeholder, ''));

                items.forEach((item) => {
                    const option = new Option(item.label, item.value);
                    if (item.macro) {
                        option.dataset.macro = item.macro;
                    }
                    select.appendChild(option);
                });

                select.value = items.some((item) => item.value === selectedValue) ? selectedValue : '';
            };

            const refreshSelect2 = () => {
                if (window.DesktopUi && typeof window.DesktopUi.refreshSelect2 === 'function') {
                    window.DesktopUi.refreshSelect2(form);
                    return;
                }

                statusSelect.dispatchEvent(new Event('change.select2'));
                macroSelect.dispatchEvent(new Event('change.select2'));
            };

            const readCurrentValues = () => {
                if (window.jQuery) {
                    const $ = window.jQuery;
                    return {
                        status: String($(statusSelect).val() || ''),
                        macro: String($(macroSelect).val() || ''),
                    };
                }

                return {
                    status: statusSelect.value,
                    macro: macroSelect.value,
                };
            };

            const macroForStatus = (statusValue) => statuses.find((status) => status.value === statusValue)?.macro || '';
            const statusesForMacro = (macroValue) => macroValue === ''
                ? statuses
                : statuses.filter((status) => status.macro === macroValue);
            const macrosForStatus = (statusValue) => {
                const macroValue = macroForStatus(statusValue);
                return macroValue === '' ? macros : macros.filter((macro) => macro.value === macroValue);
            };

            const syncFilters = (source) => {
                if (isSyncing) {
                    return;
                }

                isSyncing = true;

                const currentValues = readCurrentValues();
                let statusValue = currentValues.status;
                let macroValue = currentValues.macro;

                if (source === 'status') {
                    const statusMacro = macroForStatus(statusValue);
                    if (statusValue !== '') {
                        macroValue = statusMacro;
                    } else if (macroValue !== '') {
                        macroValue = macroSelect.value;
                    }
                }

                if (source === 'macro') {
                    if (macroValue === '') {
                        statusValue = '';
                    } else if (statusValue !== '' && macroForStatus(statusValue) !== macroValue) {
                        statusValue = '';
                    }
                }

                if (source === 'initial' && statusValue !== '') {
                    macroValue = macroForStatus(statusValue);
                }

                const nextStatusItems = statusesForMacro(macroValue);
                if (statusValue !== '' && !nextStatusItems.some((status) => status.value === statusValue)) {
                    statusValue = '';
                }

                const nextMacroItems = statusValue !== '' ? macrosForStatus(statusValue) : macros;
                if (statusValue !== '') {
                    macroValue = macroForStatus(statusValue);
                } else if (macroValue !== '' && !nextMacroItems.some((macro) => macro.value === macroValue)) {
                    macroValue = '';
                }

                rebuildSelect(statusSelect, statusPlaceholder, nextStatusItems, statusValue);
                rebuildSelect(macroSelect, macroPlaceholder, nextMacroItems, macroValue);

                refreshSelect2();
                lastStatusValue = statusSelect.value;
                lastMacroValue = macroSelect.value;
                isSyncing = false;
            };

            const inferNativeSource = () => {
                const currentValues = readCurrentValues();
                if (currentValues.status !== lastStatusValue) {
                    return 'status';
                }

                if (currentValues.macro !== lastMacroValue) {
                    return 'macro';
                }

                return 'initial';
            };

            const resetBasicFilters = () => {
                // Navegação programática: precisa avisar o guard de sessão
                // (layouts/app.blade.php) de que isso é navegação interna,
                // senão o pagehide seguinte marca a saída como "navegador
                // fechado" e a próxima página desloga o usuário sozinha.
                window.erpMarkInternalNavigation?.();

                const resetUrl = clearButton instanceof HTMLElement ? (clearButton.dataset.resetUrl || '') : '';
                if (resetUrl !== '') {
                    window.location.assign(resetUrl);
                    return;
                }

                window.location.assign(window.location.pathname);
            };

            statusSelect.addEventListener('change', () => syncFilters(inferNativeSource()));
            macroSelect.addEventListener('change', () => syncFilters(inferNativeSource()));

            if (window.jQuery) {
                const $ = window.jQuery;
                $(statusSelect).on('select2:select.osFilters select2:clear.osFilters', () => syncFilters('status'));
                $(macroSelect).on('select2:select.osFilters select2:clear.osFilters', () => syncFilters('macro'));
            }

            if (clearButton instanceof HTMLButtonElement) {
                clearButton.addEventListener('click', resetBasicFilters);
            }

            syncFilters('initial');
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Atalhos de "Situação da OS": marcam de uma vez o conjunto de
            // checkboxes de macrofase correspondente ao preset e enviam o form.
            // Independente do bloco acima (sync status/macrofase de valor único) —
            // aqui não há nada para sincronizar, só aplicar o preset e filtrar.
            const form = document.getElementById('osFilterPanel');
            const macroCheckboxes = document.querySelectorAll('[data-order-macro-checkbox]');
            const presetButtons = document.querySelectorAll('[data-order-macro-preset]');

            if (!(form instanceof HTMLFormElement) || macroCheckboxes.length === 0 || presetButtons.length === 0) {
                return;
            }

            presetButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    const presetGroups = (button.dataset.orderMacroPreset || '').split(',').filter(Boolean);
                    macroCheckboxes.forEach((checkbox) => {
                        checkbox.checked = presetGroups.includes(checkbox.value);
                    });
                    form.requestSubmit();
                });
            });
        });
    </script>
    @if (file_exists(public_path('assets/js/orders-list.js')))
        <script src="{{ asset('assets/js/orders-list.js') }}?v={{ filemtime(public_path('assets/js/orders-list.js')) }}"></script>
    @endif
    {{-- orders-map.js registra window.DesktopOsMap.create(), usado pela aba
         "Mapa de status" do modal de alteração de status (_status_modal). Sem
         ele o SVG do mapa fica estático: sem decoração, sem zoom/pan/clique. --}}
    <script src="{{ asset('assets/js/orders-map.js') }}?v={{ filemtime(public_path('assets/js/orders-map.js')) }}"></script>
    <script src="{{ asset('assets/js/orders-status-modal.js') }}?v={{ filemtime(public_path('assets/js/orders-status-modal.js')) }}"></script>
    <script src="{{ asset('assets/js/orders-cancel-closure-modal.js') }}"></script>
    <script src="{{ asset('assets/js/orders-batch-closure.js') }}?v={{ filemtime(public_path('assets/js/orders-batch-closure.js')) }}"></script>
    <script src="{{ asset('assets/js/orders-status-batch.js') }}?v={{ filemtime(public_path('assets/js/orders-status-batch.js')) }}"></script>
    {{-- Busca dinamica + paginacao/itens-por-pagina sem reload: substitui so o
         conteudo de #osTableContainer via fetch (ver OrderController::index()). --}}
    <script src="{{ asset('assets/js/orders-dynamic-list.js') }}?v={{ filemtime(public_path('assets/js/orders-dynamic-list.js')) }}"></script>
@endsection

@push('modals')
    @include('orders._status_modal')
    @include('orders._cancel_closure_modal')
    @include('orders._batch_closure_modal')
    @include('orders._batch_status_modal')
@endpush
