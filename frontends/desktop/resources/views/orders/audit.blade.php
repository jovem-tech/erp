@extends('layouts.app')

@section('content')
    @php
        $orderId = (int) ($order['id'] ?? 0);
        $orderNumber = trim((string) ($order['numero_os'] ?? ''));
        $categoryMeta = [
            'status' => ['label' => 'Status', 'color' => '#6f5afc', 'icon' => 'bi-arrow-repeat'],
            'orcamento' => ['label' => 'Orçamento', 'color' => '#3b82f6', 'icon' => 'bi-file-earmark-text'],
            'financeiro' => ['label' => 'Financeiro', 'color' => '#22c55e', 'icon' => 'bi-cash-coin'],
            'documento' => ['label' => 'Documentos', 'color' => '#f59e0b', 'icon' => 'bi-file-earmark-pdf'],
            'mensagem' => ['label' => 'Mensagens', 'color' => '#14b8a6', 'icon' => 'bi-whatsapp'],
            'registro' => ['label' => 'Registros', 'color' => '#94a3b8', 'icon' => 'bi-journal-text'],
        ];
        $originLabels = [
            'sistema' => 'Sistema',
            'usuario' => 'Usuário',
            'cliente' => 'Cliente',
            'automacao' => 'Automação',
        ];
        $categoryCounts = is_array($stats['categories'] ?? null) ? $stats['categories'] : [];
        $typeCounts = is_array($stats['types'] ?? null) ? $stats['types'] : [];
        $totalEvents = (int) ($stats['total'] ?? 0);
        $lastPage = max(1, (int) ($pagination['last_page'] ?? 1));
        $currentPage = min($lastPage, max(1, (int) ($pagination['current_page'] ?? 1)));
        $from = (int) ($pagination['from'] ?? 0);
        $to = (int) ($pagination['to'] ?? 0);
        $filteredTotal = (int) ($pagination['total'] ?? 0);
        $filterValues = array_merge([
            'category' => '',
            'origin' => '',
            'type' => '',
            'search' => '',
            'date_from' => '',
            'date_to' => '',
            'per_page' => 50,
        ], $filters ?? []);

        $auditUrl = static function (array $overrides = []) use ($orderId, $filterValues): string {
            $query = array_merge($filterValues, $overrides);
            $query = array_filter($query, static fn ($value): bool => $value !== '' && $value !== null);

            return route('orders.audit', array_merge(['order' => $orderId], $query));
        };

        $pageCandidates = array_merge([1, $lastPage], range(max(1, $currentPage - 2), min($lastPage, $currentPage + 2)));
        $pageNumbers = array_values(array_unique(array_filter($pageCandidates, static fn (int $page): bool => $page >= 1 && $page <= $lastPage)));
        sort($pageNumbers);

        $flattenAuditData = null;
        $flattenAuditData = static function (array $data, string $prefix = '') use (&$flattenAuditData): array {
            $rows = [];

            foreach ($data as $key => $value) {
                $label = $prefix !== '' ? $prefix . '.' . $key : (string) $key;
                if (is_array($value)) {
                    $rows = array_merge($rows, $flattenAuditData($value, $label));
                    continue;
                }

                $rows[] = [
                    'key' => str_replace('_', ' ', $label),
                    'value' => is_bool($value)
                        ? ($value ? 'sim' : 'não')
                        : ($value === null ? 'nulo' : (string) $value),
                ];
            }

            return $rows;
        };
    @endphp

    <div class="audit-page" data-order-audit-page>
        <div class="audit-page-header">
            <div>
                <a href="{{ route('orders.show', $orderId) }}" class="btn btn-sm btn-outline-primary mb-3">
                    <i class="bi bi-arrow-left me-1"></i>Voltar para a OS
                </a>
                <p class="desktop-eyebrow">Auditoria completa da ordem de serviço</p>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <h1 class="surface-title fs-3 mb-0">{{ $orderNumber !== '' ? $orderNumber : ('#' . $orderId) }}</h1>
                    @include('layouts.partials.status-pill', [
                        'label' => ($order['status_nome'] ?? '') !== '' ? $order['status_nome'] : 'Sem status',
                        'color' => $order['status_cor'] ?? '#64748b',
                    ])
                </div>
                <p class="surface-subtitle mt-2 mb-0">
                    Todos os eventos registrados pela aplicação, sem o limite de 200 itens do resumo da OS.
                </p>
            </div>

            <div class="audit-integrity-badge">
                <i class="bi bi-shield-check"></i>
                <span><strong>{{ number_format($totalEvents, 0, ',', '.') }}</strong> registros append-only</span>
            </div>
        </div>

        <section class="audit-snapshot-grid" aria-label="Estado atual da OS">
            <article class="surface-card audit-snapshot-card">
                <span class="audit-snapshot-label"><i class="bi bi-person"></i>Cliente</span>
                <strong>{{ ($order['cliente_nome'] ?? '') !== '' ? $order['cliente_nome'] : 'Não informado' }}</strong>
                <small>ID {{ (int) ($order['cliente_id'] ?? 0) }}</small>
            </article>
            <article class="surface-card audit-snapshot-card">
                <span class="audit-snapshot-label"><i class="bi bi-laptop"></i>Equipamento</span>
                <strong>{{ ($order['equipamento_resumo_curto'] ?? '') !== '' ? $order['equipamento_resumo_curto'] : 'Não informado' }}</strong>
                <small>SN: {{ ($order['equipamento_numero_serie'] ?? '') !== '' ? $order['equipamento_numero_serie'] : '—' }}</small>
            </article>
            <article class="surface-card audit-snapshot-card">
                <span class="audit-snapshot-label"><i class="bi bi-person-badge"></i>Responsável atual</span>
                <strong>{{ ($order['tecnico']['nome'] ?? '') !== '' ? $order['tecnico']['nome'] : 'Técnico não atribuído' }}</strong>
                <small>{{ $order['tecnico']['email'] ?? 'Sem e-mail vinculado' }}</small>
            </article>
            <article class="surface-card audit-snapshot-card">
                <span class="audit-snapshot-label"><i class="bi bi-calendar-check"></i>Datas operacionais</span>
                <strong>Abertura: {{ ($order['data_abertura'] ?? '') !== '' ? $order['data_abertura'] : '—' }}</strong>
                <small>Status atualizado: {{ ($order['status_atualizado_em'] ?? '') !== '' ? $order['status_atualizado_em'] : '—' }}</small>
            </article>
        </section>

        <section class="surface-card audit-filter-card">
            <div class="surface-card-header align-items-start">
                <div>
                    <h2 class="surface-title fs-6"><i class="bi bi-funnel me-1"></i>Pesquisar na trilha</h2>
                    <p class="surface-subtitle">Filtre por texto, categoria, origem, tipo ou período sem perder o acesso ao histórico integral.</p>
                </div>
                @if (array_filter([$filterValues['category'], $filterValues['origin'], $filterValues['type'], $filterValues['search'], $filterValues['date_from'], $filterValues['date_to']]) !== [])
                    <a href="{{ route('orders.audit', $orderId) }}" class="btn btn-sm btn-outline-secondary">Limpar filtros</a>
                @endif
            </div>

            <form method="GET" action="{{ route('orders.audit', $orderId) }}" class="audit-filter-grid">
                <div class="audit-filter-search">
                    <label for="audit-search" class="form-label">Busca em todo o evento</label>
                    <input id="audit-search" name="search" type="search" maxlength="100" class="form-control"
                        value="{{ $filterValues['search'] }}" placeholder="Título, descrição, tipo ou dado técnico">
                </div>
                <div>
                    <label for="audit-category" class="form-label">Categoria</label>
                    <select id="audit-category" name="category" class="form-select">
                        <option value="">Todas</option>
                        @foreach ($categoryMeta as $value => $meta)
                            <option value="{{ $value }}" @selected($filterValues['category'] === $value)>{{ $meta['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="audit-origin" class="form-label">Origem</label>
                    <select id="audit-origin" name="origin" class="form-select">
                        <option value="">Todas</option>
                        @foreach ($originLabels as $value => $label)
                            <option value="{{ $value }}" @selected($filterValues['origin'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="audit-type" class="form-label">Tipo técnico</label>
                    <select id="audit-type" name="type" class="form-select">
                        <option value="">Todos</option>
                        @foreach ($typeCounts as $type => $count)
                            <option value="{{ $type }}" @selected($filterValues['type'] === $type)>
                                {{ str_replace('_', ' ', $type) }} ({{ $count }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="audit-date-from" class="form-label">De</label>
                    <input id="audit-date-from" name="date_from" type="date" class="form-control" value="{{ $filterValues['date_from'] }}">
                </div>
                <div>
                    <label for="audit-date-to" class="form-label">Até</label>
                    <input id="audit-date-to" name="date_to" type="date" class="form-control" value="{{ $filterValues['date_to'] }}">
                </div>
                <div>
                    <label for="audit-per-page" class="form-label">Por página</label>
                    <select id="audit-per-page" name="per_page" class="form-select">
                        @foreach ([25, 50, 100] as $size)
                            <option value="{{ $size }}" @selected((int) $filterValues['per_page'] === $size)>{{ $size }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="audit-filter-submit">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>Aplicar filtros</button>
                </div>
            </form>
        </section>

        <nav class="audit-category-nav" aria-label="Categorias do histórico">
            <a href="{{ $auditUrl(['category' => '', 'page' => 1]) }}"
                class="event-chip {{ $filterValues['category'] === '' ? 'is-active' : '' }}">
                Todos <span class="event-chip-count">{{ $totalEvents }}</span>
            </a>
            @foreach ($categoryMeta as $category => $meta)
                @php $count = (int) ($categoryCounts[$category] ?? 0); @endphp
                <a href="{{ $auditUrl(['category' => $category, 'page' => 1]) }}"
                    class="event-chip {{ $filterValues['category'] === $category ? 'is-active' : '' }} {{ $count === 0 ? 'is-empty' : '' }}"
                    style="--event-color: {{ $meta['color'] }}">
                    <span class="event-chip-dot"></span>{{ $meta['label'] }} <span class="event-chip-count">{{ $count }}</span>
                </a>
            @endforeach
        </nav>

        <section class="surface-card audit-results-card">
            <div class="surface-card-header align-items-start">
                <div>
                    <h2 class="surface-title fs-6"><i class="bi bi-clock-history me-1"></i>Linha do tempo auditável</h2>
                    <p class="surface-subtitle">
                        @if ($filteredTotal > 0)
                            Exibindo {{ $from }}–{{ $to }} de {{ number_format($filteredTotal, 0, ',', '.') }} registros encontrados.
                        @else
                            Nenhum registro encontrado com os filtros atuais.
                        @endif
                    </p>
                </div>
                <span class="audit-order-id">OS interna #{{ $orderId }}</span>
            </div>

            @if ($events !== [])
                <div class="audit-event-list">
                    @foreach ($events as $event)
                        @php
                            $category = (string) ($event['category'] ?? 'registro');
                            $meta = $categoryMeta[$category] ?? $categoryMeta['registro'];
                            $origin = (string) ($event['origin'] ?? 'sistema');
                            $userName = trim((string) ($event['user']['name'] ?? ''));
                            $responsible = $userName !== '' ? $userName : ($originLabels[$origin] ?? 'Sistema');
                            $eventData = is_array($event['data'] ?? null) ? $event['data'] : [];
                            $dataRows = $flattenAuditData($eventData);
                            $provenance = is_array($event['provenance'] ?? null) ? $event['provenance'] : [];
                            $createdAt = (string) ($event['created_at'] ?? '');
                            $createdAtLabel = $createdAt;
                            if ($createdAt !== '') {
                                try {
                                    $createdAtLabel = \Illuminate\Support\Carbon::parse($createdAt)->format('d/m/Y H:i:s');
                                } catch (\Throwable) {
                                    $createdAtLabel = $createdAt;
                                }
                            }
                        @endphp

                        <article class="audit-event" data-audit-event data-event-category="{{ $category }}"
                            style="--event-color: {{ $meta['color'] }}">
                            <div class="audit-event-heading">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <span class="event-category-pill"><i class="bi {{ $meta['icon'] }}"></i>{{ $meta['label'] }}</span>
                                    <code>{{ $event['type'] ?? 'evento' }}</code>
                                    <span class="audit-event-sequence">#{{ (int) ($event['id'] ?? 0) }}</span>
                                </div>
                                <time datetime="{{ $createdAt }}"><i class="bi bi-calendar3 me-1"></i>{{ $createdAtLabel !== '' ? $createdAtLabel : 'Data não informada' }}</time>
                            </div>

                            <h3>{{ ($event['title'] ?? '') !== '' ? $event['title'] : 'Evento sem título' }}</h3>
                            @if (trim((string) ($event['description'] ?? '')) !== '')
                                <p>{{ $event['description'] }}</p>
                            @endif

                            <div class="audit-event-meta">
                                <span><i class="bi bi-person-check"></i>Responsável: <strong>{{ $responsible }}</strong></span>
                                <span><i class="bi bi-diagram-3"></i>Origem: <strong>{{ $originLabels[$origin] ?? $origin }}</strong></span>
                                @if (($event['user']['email'] ?? '') !== '')
                                    <span><i class="bi bi-envelope"></i>{{ $event['user']['email'] }}</span>
                                @endif
                            </div>

                            <div class="audit-event-footer">
                                <span class="audit-provenance">
                                    <i class="bi bi-database-check"></i>
                                    @if (($provenance['kind'] ?? 'native') === 'legacy')
                                        Importado de {{ $provenance['legacy_table'] ?? 'fonte legada' }} #{{ (int) ($provenance['legacy_id'] ?? 0) }}
                                    @else
                                        Evento nativo da trilha append-only
                                    @endif
                                </span>

                                @if ($dataRows !== [])
                                    <details class="audit-event-data">
                                        <summary><i class="bi bi-braces me-1"></i>Ver dados técnicos ({{ count($dataRows) }})</summary>
                                        <dl>
                                            @foreach ($dataRows as $row)
                                                <dt>{{ $row['key'] }}</dt>
                                                <dd>{{ $row['value'] }}</dd>
                                            @endforeach
                                        </dl>
                                    </details>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @else
                @include('layouts.partials.empty-state', [
                    'icon' => 'bi-search',
                    'title' => 'Nenhum evento encontrado',
                    'message' => 'A trilha não possui registros compatíveis com os filtros selecionados.',
                ])
            @endif
        </section>

        @if ($lastPage > 1)
            <nav class="audit-pagination" aria-label="Paginação do histórico completo">
                @if ($currentPage > 1)
                    <a href="{{ $auditUrl(['page' => $currentPage - 1]) }}" class="audit-page-link"><i class="bi bi-chevron-left"></i>Anterior</a>
                @else
                    <span class="audit-page-link is-disabled"><i class="bi bi-chevron-left"></i>Anterior</span>
                @endif

                @php $previousRenderedPage = 0; @endphp
                @foreach ($pageNumbers as $pageNumber)
                    @if ($previousRenderedPage > 0 && $pageNumber > $previousRenderedPage + 1)
                        <span class="audit-page-ellipsis">…</span>
                    @endif
                    <a href="{{ $auditUrl(['page' => $pageNumber]) }}"
                        class="audit-page-number {{ $pageNumber === $currentPage ? 'is-active' : '' }}"
                        @if ($pageNumber === $currentPage) aria-current="page" @endif>{{ $pageNumber }}</a>
                    @php $previousRenderedPage = $pageNumber; @endphp
                @endforeach

                @if ($currentPage < $lastPage)
                    <a href="{{ $auditUrl(['page' => $currentPage + 1]) }}" class="audit-page-link">Próxima<i class="bi bi-chevron-right"></i></a>
                @else
                    <span class="audit-page-link is-disabled">Próxima<i class="bi bi-chevron-right"></i></span>
                @endif
            </nav>
        @endif
    </div>
@endsection
