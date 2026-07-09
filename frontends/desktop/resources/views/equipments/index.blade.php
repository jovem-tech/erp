@extends('layouts.app')

@section('content')
    @php
        $hasActiveFilters = trim((string) ($filters['search'] ?? '')) !== '';
        $activeFilterCount = count(array_filter([
            trim((string) ($filters['search'] ?? '')) !== '',
        ]));
    @endphp

    <x-list-filters
        form-id="equipmentsFilterPanel"
        search-name="search"
        :search-value="$filters['search'] ?? ''"
        search-placeholder="Resumo tecnico, serie, IMEI ou cliente"
        :results-count="$pagination['total'] ?? 0"
        results-label="equipamentos"
        :clear-url="route('equipments.index')"
        :has-active-filters="$hasActiveFilters"
        :active-filter-count="$activeFilterCount"
    >
        <x-slot:actions>
            @if (\App\Support\DesktopSession::can('equipamentos', 'criar'))
                <a href="{{ route('equipments.create') }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-2"></i>
                    Novo equipamento
                </a>
            @endif
        </x-slot:actions>

        @if ((int) ($filters['client_id'] ?? 0) > 0)
            <input type="hidden" name="client_id" value="{{ $filters['client_id'] }}">
        @endif

        <div>
            <label for="per_page">Itens por página</label>
            <select id="per_page" name="per_page" class="form-select">
                @foreach ([15, 30, 50] as $size)
                    <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 15) === $size)>{{ $size }}</option>
                @endforeach
            </select>
        </div>
    </x-list-filters>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Cadastro de equipamentos</h2>
                <p class="surface-subtitle">
                    {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} equipamentos retornados pela API central.
                </p>
            </div>

            <span class="desktop-chip">
                <i class="bi bi-laptop"></i>
                {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} registros
            </span>
        </div>

        @if ($equipments !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle equipment-list-table">
                    <thead>
                    <tr>
                        <th>Foto</th>
                        <th>ID</th>
                        <th>Equipamento</th>
                        <th>Cliente</th>
                        <th>Serie / IMEI</th>
                        <th>Modalidade</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($equipments as $equipment)
                        @php
                            $equipment = array_merge([
                                'id' => 0,
                                'cliente_id' => 0,
                                'cliente_nome' => '',
                                'resumo_tecnico' => '',
                                'numero_serie' => '',
                                'imei' => '',
                                'desktop_modalidade' => '',
                                'status_operacional' => '',
                                'orders_count' => 0,
                                'primary_photo_url' => null,
                            ], is_array($equipment) ? $equipment : []);

                            $equipmentId = (int) ($equipment['id'] ?? 0);
                            $equipmentName = trim((string) ($equipment['resumo_tecnico'] ?? ''));
                            $clientName = trim((string) ($equipment['cliente_nome'] ?? ''));
                            $clientId = (int) ($equipment['cliente_id'] ?? 0);
                            $serial = trim((string) ($equipment['numero_serie'] ?? ''));
                            $imei = trim((string) ($equipment['imei'] ?? ''));
                            $modality = trim((string) ($equipment['desktop_modalidade'] ?? ''));
                            $statusLabel = trim((string) ($equipment['status_operacional'] ?? ''));
                            $ordersCount = max(0, (int) ($equipment['orders_count'] ?? 0));
                            $primaryPhotoUrl = trim((string) ($equipment['primary_photo_url'] ?? ''));
                        @endphp
                        <tr>
                            <td data-label="Foto">
                                @if ($primaryPhotoUrl !== '')
                                    <a href="{{ route('equipments.show', $equipmentId) }}" class="equipment-list-photo-link" aria-label="Abrir detalhe do equipamento {{ $equipmentName !== '' ? $equipmentName : $equipmentId }}">
                                        <img src="{{ $primaryPhotoUrl }}" alt="Miniatura do equipamento {{ $equipmentName !== '' ? $equipmentName : $equipmentId }}" class="equipment-list-photo" data-photo-fallback>
                                    </a>
                                    <span class="equipment-list-photo-placeholder d-none" aria-hidden="true">
                                        <i class="bi bi-camera"></i>
                                    </span>
                                @else
                                    <span class="equipment-list-photo-placeholder" aria-hidden="true">
                                        <i class="bi bi-camera"></i>
                                    </span>
                                @endif
                            </td>
                            <td data-label="ID" class="equipment-row-id">{{ $equipmentId > 0 ? $equipmentId : '-' }}</td>
                            <td data-label="Equipamento">
                                <div class="fw-semibold equipment-list-name">
                                    {{ $equipmentName !== '' ? $equipmentName : 'Sem resumo tecnico' }}
                                </div>
                                <div class="equipment-counts">
                                    <span class="equipment-count-chip equipment-count-chip-primary">
                                        {{ number_format($ordersCount, 0, ',', '.') }} {{ $ordersCount === 1 ? 'OS' : 'OS' }}
                                    </span>
                                    <span class="equipment-count-chip equipment-count-chip-muted">
                                        {{ $modality !== '' ? ucfirst((string) $modality) : 'Modalidade nao informada' }}
                                    </span>
                                </div>
                            </td>
                            <td data-label="Cliente">
                                <div>{{ $clientName !== '' ? $clientName : 'Nao vinculado' }}</div>
                                @if ($clientId > 0 && \App\Support\DesktopSession::can('clientes', 'visualizar'))
                                    <small class="text-secondary d-block mt-1">Cliente #{{ $clientId }}</small>
                                @endif
                            </td>
                            <td data-label="Serie / IMEI">
                                @if ($serial !== '')
                                    <div>S/N {{ $serial }}</div>
                                @elseif ($imei !== '')
                                    <div>IMEI {{ $imei }}</div>
                                @else
                                    <span class="text-secondary">Nao informado</span>
                                @endif
                            </td>
                            <td data-label="Modalidade">
                                {{ $modality !== '' ? ucfirst((string) $modality) : 'Nao informada' }}
                            </td>
                            <td data-label="Status">
                                @include('layouts.partials.status-pill', [
                                    'label' => $statusLabel !== '' ? ucfirst((string) $statusLabel) : 'Sem status',
                                    'color' => '#29c384',
                                    'small' => true,
                                ])
                            </td>
                            <td data-label="Ações" class="text-end">
                                <x-list-actions>
                                    <li>
                                        <a href="{{ route('equipments.show', $equipmentId) }}" class="dropdown-item">
                                            <i class="bi bi-eye me-2"></i>
                                            Detalhe
                                        </a>
                                    </li>

                                    @if (\App\Support\DesktopSession::can('equipamentos', 'editar'))
                                        <li>
                                            <a href="{{ route('equipments.edit', $equipmentId) }}" class="dropdown-item">
                                                <i class="bi bi-pencil-square me-2"></i>
                                                Editar
                                            </a>
                                        </li>
                                    @endif

                                    @if ($clientId > 0 && \App\Support\DesktopSession::can('clientes', 'visualizar'))
                                        <li>
                                            <a href="{{ route('clients.show', $clientId) }}" class="dropdown-item">
                                                <i class="bi bi-person-badge me-2"></i>
                                                Abrir cliente
                                            </a>
                                        </li>
                                    @endif

                                    @if (\App\Support\DesktopSession::can('os', 'visualizar'))
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <a href="{{ route('orders.index', ['equipment_id' => $equipmentId]) }}" class="dropdown-item">
                                                <i class="bi bi-clipboard2-check me-2"></i>
                                                Ver OS
                                            </a>
                                        </li>
                                    @endif

                                    @if (\App\Support\DesktopSession::can('os', 'criar') && $clientId > 0)
                                        <li>
                                            <a href="{{ route('orders.create', ['cliente_id' => $clientId, 'equipamento_id' => $equipmentId]) }}" class="dropdown-item">
                                                <i class="bi bi-plus-circle me-2"></i>
                                                Nova OS
                                            </a>
                                        </li>
                                    @endif
                                </x-list-actions>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @include('layouts.partials.pagination', ['pagination' => $pagination, 'filters' => $filters])
        @else
            @include('layouts.partials.empty-state', [
                'icon' => 'bi-laptop',
                'title' => 'Nenhum equipamento encontrado',
                'message' => 'Ajuste os filtros para localizar os aparelhos desejados.',
            ])
        @endif
    </section>
@endsection
