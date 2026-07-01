@extends('layouts.app')

@section('content')
    @php
        $usesOpenQueueScope = trim((string) ($filters['status_scope'] ?? '')) === 'open';
        $hasAdvancedFilters = (int) ($filters['technician_id'] ?? 0) > 0
            || trim((string) ($filters['grupo_macro'] ?? '')) !== ''
            || trim((string) ($filters['data_abertura_de'] ?? '')) !== ''
            || trim((string) ($filters['data_abertura_ate'] ?? '')) !== ''
            || trim((string) ($filters['valor_min'] ?? '')) !== ''
            || trim((string) ($filters['valor_max'] ?? '')) !== '';
        $hasBasicFilters = trim((string) ($filters['search'] ?? '')) !== ''
            || trim((string) ($filters['status'] ?? '')) !== '';
        $hasAnyFilters = $hasBasicFilters || $hasAdvancedFilters;

        $statusPlaceholder = $usesOpenQueueScope ? 'Padrão: OS abertas' : 'Todos os status';

        $deadlineColors = [
            'atrasado' => '#dc2626',
            'critico' => '#f59e0b',
            'vence_hoje' => '#f97316',
            'no_prazo' => '#16a34a',
            'concluido_no_prazo' => '#16a34a',
            'concluido_atrasado' => '#dc2626',
            'sem_previsao' => '#64748b',
        ];

        $formatOrderDate = static function (?string $value): ?string {
            if ($value === null || trim($value) === '') {
                return null;
            }

            try {
                return \Illuminate\Support\Carbon::parse($value)->format('d/m/Y');
            } catch (\Throwable) {
                return null;
            }
        };
    @endphp

    <section class="desktop-form-card mb-4">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Filtro operacional de OS</h2>
                @unless ($usesOpenQueueScope)
                    <p class="surface-subtitle">Listagem administrativa ou técnica conforme as permissões efetivas do usuário.</p>
                @endunless
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="desktop-chip">{{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} resultados</span>
                <button type="button" class="btn btn-sm btn-outline-light" data-bs-toggle="collapse" data-bs-target="#osFilterPanel" aria-expanded="{{ $hasAnyFilters ? 'true' : 'false' }}" aria-controls="osFilterPanel">
                    <i class="bi bi-funnel me-2"></i>
                    Filtros
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
                <label for="search">Busca</label>
                <input type="text" id="search" name="search" class="form-control" value="{{ $filters['search'] ?? '' }}" placeholder="OS, cliente, série ou resumo técnico">
            </div>

            <div>
                <label for="status">Status</label>
                @if (! empty($statusOptions))
                    <select id="status" name="status" class="form-select">
                        <option value="">{{ $statusPlaceholder }}</option>
                        @foreach ($statusOptions as $statusOption)
                            <option value="{{ $statusOption['codigo'] ?? '' }}" @selected(($filters['status'] ?? '') === ($statusOption['codigo'] ?? ''))>
                                {{ $statusOption['nome'] ?? ($statusOption['codigo'] ?? '') }}
                            </option>
                        @endforeach
                    </select>
                @else
                    <input type="text" id="status" name="status" class="form-control" value="{{ $filters['status'] ?? '' }}" placeholder="Ex.: em_diagnostico">
                @endif
            </div>

            <div>
                <label for="per_page">Itens por página</label>
                <select id="per_page" name="per_page" class="form-select">
                    @foreach ([15, 30, 50] as $size)
                        <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 15) === $size)>{{ $size }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field-actions">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="bi bi-search me-2"></i>
                    Filtrar
                </button>
                <a href="{{ route('orders.index') }}" class="btn btn-outline-light">Limpar</a>
            </div>

            <div class="desktop-filter-advanced-toggle">
                <button type="button" class="btn btn-sm btn-outline-light" data-bs-toggle="collapse" data-bs-target="#osAdvancedFilters" aria-expanded="{{ $hasAdvancedFilters ? 'true' : 'false' }}" aria-controls="osAdvancedFilters">
                    <i class="bi bi-sliders me-2"></i>
                    Filtros avançados
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
                        <label for="grupo_macro">Macrofase</label>
                        @if (! empty($macroGroupOptions))
                            <select id="grupo_macro" name="grupo_macro" class="form-select">
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
            </div>
        </form>
    </section>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Ordens de Serviço</h2>
            </div>
        </div>

        @if ($orders !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>Foto / OS</th>
                        <th>Cliente</th>
                        <th>Equipamento</th>
                        <th>Datas</th>
                        <th>Status / Orçamento</th>
                        <th>Valor</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($orders as $order)
                        @php
                            $orderId = (int) ($order['id'] ?? 0);
                            $numeroOs = trim((string) ($order['numero_os'] ?? ''));
                            $numeroOsLegado = trim((string) ($order['numero_os_legado'] ?? ''));
                            $equipmentId = (int) ($order['equipamento_id'] ?? 0);
                            $equipmentPhotoId = (int) ($order['equipamento_foto_id'] ?? 0);
                            $fotoUrl = $equipmentId > 0 && $equipmentPhotoId > 0
                                ? route('equipments.photos.show', [$equipmentId, $equipmentPhotoId])
                                : '';

                            $clientName = trim((string) ($order['cliente_nome'] ?? ''));
                            $clientPhone = trim((string) ($order['cliente_telefone'] ?? ''));
                            $clientPhoneDigits = preg_replace('/\D+/', '', $clientPhone);
                            $whatsappUrl = $clientPhoneDigits !== '' ? 'https://wa.me/55' . $clientPhoneDigits : '';

                            $equipmentSummary = trim((string) ($order['equipamento_resumo_curto'] ?? ''));
                            $equipmentFullSummary = trim((string) ($order['equipamento_resumo_tecnico'] ?? ''));
                            $equipmentSerial = trim((string) ($order['equipamento_numero_serie'] ?? ''));

                            $deadline = is_array($order['prazo'] ?? null) ? $order['prazo'] : [];
                            $deadlineColor = $deadlineColors[$deadline['estado'] ?? 'sem_previsao'] ?? '#64748b';

                            $dataEntrada = $formatOrderDate($order['data_entrada'] ?? null) ?? $formatOrderDate($order['data_abertura'] ?? null);
                            $dataConclusao = $formatOrderDate($order['data_conclusao'] ?? null);
                            $dataEntrega = $formatOrderDate($order['data_entrega'] ?? null);

                            $budget = is_array($order['orcamento'] ?? null) ? $order['orcamento'] : null;

                            $valorFinal = $order['valor_final'] ?? null;
                            $valorRecebido = $order['valor_recebido'] ?? null;
                            $saldo = $order['saldo'] ?? null;

                            $estadoFluxo = trim((string) ($order['estado_fluxo'] ?? ''));
                            $canEditOrder = \App\Support\DesktopSession::can('os', 'editar');
                            $canCloseOrder = $canEditOrder && ! in_array($estadoFluxo, ['encerrado', 'cancelado'], true);
                        @endphp
                        <tr data-order-id="{{ $orderId }}">
                            <td data-label="Foto / OS">
                                <div class="os-photo-cell">
                                    @if ($fotoUrl !== '')
                                        <a href="{{ route('orders.show', $orderId) }}" class="equipment-list-photo-link" aria-label="Abrir detalhe da OS {{ $numeroOs }}">
                                            <img src="{{ $fotoUrl }}" alt="Foto do equipamento da OS {{ $numeroOs }}" class="equipment-list-photo" data-photo-fallback>
                                        </a>
                                        <span class="equipment-list-photo-placeholder d-none" aria-hidden="true">
                                            <i class="bi bi-camera"></i>
                                        </span>
                                    @else
                                        <span class="equipment-list-photo-placeholder" aria-hidden="true">
                                            <i class="bi bi-camera"></i>
                                        </span>
                                    @endif

                                    <div>
                                        <a href="{{ route('orders.show', $orderId) }}" class="fw-semibold">
                                            {{ $numeroOs !== '' ? $numeroOs : '#' . $orderId }}
                                        </a>
                                        <div class="text-secondary small">
                                            {{ $numeroOsLegado !== '' ? 'Legado: ' . $numeroOsLegado : 'ID interno ' . $orderId }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Cliente">
                                <div>{{ $clientName !== '' ? $clientName : 'Não informado' }}</div>
                                @if ($whatsappUrl !== '')
                                    <a href="{{ $whatsappUrl }}" target="_blank" rel="noreferrer" class="text-decoration-none small">
                                        <i class="bi bi-whatsapp me-1"></i>{{ $clientPhone }}
                                    </a>
                                @endif
                            </td>
                            <td data-label="Equipamento">
                                <div title="{{ $equipmentFullSummary }}">
                                    {{ $equipmentSummary !== '' ? $equipmentSummary : 'Sem resumo técnico' }}
                                </div>
                                <small class="text-secondary">{{ $equipmentSerial !== '' ? 'S/N ' . $equipmentSerial : 'Série não informada' }}</small>
                            </td>
                            <td data-label="Datas">
                                <div class="os-dates-cell">
                                    <div><span class="text-secondary small">Entrada:</span> {{ $dataEntrada ?? 'Não informada' }}</div>
                                    @if ($deadline !== [] && ($deadline['estado'] ?? 'sem_previsao') !== 'sem_previsao')
                                        <div>
                                            <span class="text-secondary small">Prazo:</span>
                                            @include('layouts.partials.status-pill', [
                                                'label' => ($deadline['label'] ?? '') . (($deadline['dias'] ?? null) !== null ? ' (' . $deadline['dias'] . 'd)' : ''),
                                                'color' => $deadlineColor,
                                                'small' => true,
                                            ])
                                        </div>
                                    @endif
                                    @if ($dataConclusao !== null)
                                        <div><span class="text-secondary small">Conclusão:</span> {{ $dataConclusao }}</div>
                                    @endif
                                    @if ($dataEntrega !== null)
                                        <div><span class="text-secondary small">Entrega:</span> {{ $dataEntrega }}</div>
                                    @endif
                                </div>
                            </td>
                            <td data-label="Status / Orçamento">
                                <div class="os-status-cell">
                                    @include('layouts.partials.status-pill', [
                                        'label' => $order['status_nome'] !== '' ? $order['status_nome'] : 'Sem status',
                                        'color' => $order['status_cor'] ?? '#64748b',
                                    ])

                                    @if ($budget !== null)
                                        @include('layouts.partials.status-pill', [
                                            'label' => 'Orçamento: ' . ($budget['status_label'] ?? ''),
                                            'color' => $budget['status_color'] ?? '#64748b',
                                            'small' => true,
                                        ])
                                    @else
                                        <span class="text-secondary small">Sem orçamento</span>
                                    @endif
                                </div>
                            </td>
                            <td data-label="Valor">
                                <div class="os-value-cell">
                                    <strong>{{ $valorFinal !== null ? 'R$ ' . number_format((float) $valorFinal, 2, ',', '.') : 'Não informado' }}</strong>
                                    @if ($valorRecebido !== null || $saldo !== null)
                                        <div class="text-secondary small">Recebido: R$ {{ number_format((float) ($valorRecebido ?? 0), 2, ',', '.') }}</div>
                                        <div class="small {{ (float) ($saldo ?? 0) > 0 ? 'text-danger' : 'text-success' }}">Saldo: R$ {{ number_format((float) ($saldo ?? 0), 2, ',', '.') }}</div>
                                    @else
                                        <div class="text-secondary small">Sem cobrança</div>
                                    @endif
                                </div>
                            </td>
                            <td data-label="Ações" class="text-end">
                                <div class="dropdown os-actions-dropdown">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-light dropdown-toggle os-actions-toggle"
                                        data-bs-toggle="dropdown"
                                        aria-expanded="false"
                                    >
                                        <span>Ações</span>
                                        <i class="bi bi-chevron-down"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end os-actions-menu">
                                        <li>
                                            <a href="{{ route('orders.show', $orderId) }}" class="dropdown-item">
                                                <i class="bi bi-eye me-2"></i>
                                                Detalhe
                                            </a>
                                        </li>

                                        @if ($canEditOrder)
                                            <li>
                                                <a href="{{ route('orders.edit', $orderId) }}" class="dropdown-item">
                                                    <i class="bi bi-pencil-square me-2"></i>
                                                    Editar
                                                </a>
                                            </li>
                                        @endif

                                        @if ($canCloseOrder)
                                            <li>
                                                <a href="{{ route('orders.closure.show', $orderId) }}" class="dropdown-item">
                                                    <i class="bi bi-box-seam me-2"></i>
                                                    Baixa
                                                </a>
                                            </li>
                                        @endif
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @include('layouts.partials.pagination', ['pagination' => $pagination, 'filters' => $filters])
        @else
            @include('layouts.partials.empty-state', [
                'icon' => 'bi-clipboard2-x',
                'title' => 'Nenhuma OS encontrada',
                'message' => 'Ajuste os filtros ou confirme se existem ordens disponíveis para o seu perfil atual.',
            ])
        @endif
    </section>
@endsection

@section('scripts')
    <script>
        window.__DESKTOP_ORDER_LIST = {!! json_encode([
            'channelName'        => 'orders',
            'broadcastAuthUrl'   => env('DESKTOP_BROADCAST_AUTH_URL', ''),
            'pusherKey'          => env('REVERB_APP_KEY', ''),
            'pusherHost'         => env('REVERB_HOST', 'localhost'),
            'pusherPort'         => (int) env('REVERB_PORT', 8090),
            'pusherScheme'       => env('REVERB_SCHEME', 'http'),
            'apiToken'           => \App\Support\DesktopSession::token() ?? '',
            'hasFilters'         => $hasAnyFilters,
            'ordersShowUrlBase'  => rtrim(route('orders.show', ['order' => 0]), '0'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
    </script>
    @if (file_exists(public_path('assets/js/orders-list.js')))
        <script src="{{ asset('assets/js/orders-list.js') }}?v={{ filemtime(public_path('assets/js/orders-list.js')) }}"></script>
    @endif
@endsection
