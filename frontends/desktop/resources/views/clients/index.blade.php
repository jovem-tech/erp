@extends('layouts.app')

@section('content')
    <section class="desktop-form-card mb-4">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Clientes</h2>
                <p class="surface-subtitle">
                    Organização operacional espelhada do legado: cliente como hub de contexto para OS, equipamentos e contato.
                </p>
            </div>

            @if (\App\Support\DesktopSession::can('clientes', 'criar'))
                <a href="{{ route('clients.create') }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-2"></i>
                    Novo cliente
                </a>
            @endif
        </div>

        <form method="get" class="desktop-filter-grid">
            <div>
                <label for="search">Busca</label>
                <input
                    type="text"
                    id="search"
                    name="search"
                    class="form-control"
                    value="{{ $filters['search'] ?? '' }}"
                    placeholder="Nome, documento, telefone ou e-mail"
                >
            </div>

            <div>
                <label for="status">Situação</label>
                <input
                    type="text"
                    id="status"
                    name="status"
                    class="form-control"
                    value="{{ $filters['status'] ?? '' }}"
                    placeholder="Ex.: completo, inativo"
                >
            </div>

            <div>
                <label for="sort">Ordenar por</label>
                <select id="sort" name="sort" class="form-select">
                    <option value="nome" @selected(($filters['sort'] ?? 'nome') === 'nome')>Nome A-Z</option>
                    <option value="nome_desc" @selected(($filters['sort'] ?? '') === 'nome_desc')>Nome Z-A</option>
                    <option value="recentes" @selected(($filters['sort'] ?? '') === 'recentes')>Mais recentes</option>
                    <option value="recentes_asc" @selected(($filters['sort'] ?? '') === 'recentes_asc')>Mais antigos</option>
                </select>
            </div>

            <div>
                <label for="per_page">Itens por página</label>
                <select id="per_page" name="per_page" class="form-select">
                    @foreach ([15, 30, 50] as $size)
                        <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 15) === $size)>{{ $size }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field-actions" style="grid-column: span 2;">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="bi bi-search me-2"></i>
                    Filtrar
                </button>
                <a href="{{ route('clients.index') }}" class="btn btn-outline-light">Limpar</a>
            </div>
        </form>
    </section>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Cadastro de clientes</h2>
                <p class="surface-subtitle">
                    {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} clientes disponíveis na API central.
                </p>
            </div>

            <span class="desktop-chip">
                <i class="bi bi-people"></i>
                {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} registros
            </span>
        </div>

        @if ($clients !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle client-list-table">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Cliente</th>
                        <th>Documento</th>
                        <th>Telefone</th>
                        <th>E-mail</th>
                        <th>Cidade</th>
                        <th>Situação</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($clients as $client)
                        @php
                            $client = array_merge([
                                'id' => 0,
                                'tipo_pessoa' => '',
                                'nome_razao' => '',
                                'cpf_cnpj' => '',
                                'nome_contato' => '',
                                'orders_count' => 0,
                                'equipments_count' => 0,
                                'telefone1' => '',
                                'telefone_contato' => '',
                                'email' => '',
                                'cidade' => '',
                                'uf' => '',
                                'status_cadastro' => '',
                            ], is_array($client) ? $client : []);

                            $clientId = (int) ($client['id'] ?? 0);
                            $clientName = trim((string) ($client['nome_razao'] ?? ''));
                            $clientContact = trim((string) ($client['nome_contato'] ?? ''));
                            $phone = trim((string) ($client['telefone1'] ?? ''));
                            $alternatePhone = trim((string) ($client['telefone_contato'] ?? ''));
                            $email = trim((string) ($client['email'] ?? ''));
                            $cidade = trim((string) ($client['cidade'] ?? ''));
                            $uf = trim((string) ($client['uf'] ?? ''));
                            $cpfCnpj = trim((string) ($client['cpf_cnpj'] ?? ''));
                            $phoneDigits = preg_replace('/\D+/', '', $phone);
                            $whatsappUrl = $phoneDigits !== '' ? 'https://wa.me/55' . $phoneDigits : '';
                            $ordersCount = max(0, (int) ($client['orders_count'] ?? 0));
                            $equipmentsCount = max(0, (int) ($client['equipments_count'] ?? 0));
                            $statusLabel = trim((string) ($client['status_cadastro'] ?? ''));
                        @endphp
                        <tr>
                            <td data-label="ID" class="client-row-id">{{ $clientId > 0 ? $clientId : '—' }}</td>
                            <td data-label="Cliente">
                                <div class="fw-semibold client-list-name">
                                    {{ $clientName !== '' ? $clientName : 'Sem nome' }}
                                </div>
                                <div class="client-counts">
                                    <span class="client-count-chip client-count-chip-primary">
                                        {{ number_format($ordersCount, 0, ',', '.') }} {{ $ordersCount === 1 ? 'OS' : 'OS' }}
                                    </span>
                                    <span class="client-count-chip client-count-chip-muted">
                                        {{ number_format($equipmentsCount, 0, ',', '.') }} {{ $equipmentsCount === 1 ? 'equipamento' : 'equipamentos' }}
                                    </span>
                                </div>
                                @if ($clientContact !== '')
                                    <small class="text-secondary d-block mt-1">Contato: {{ $clientContact }}</small>
                                @endif
                            </td>
                            <td data-label="Documento">
                                <div>{{ $cpfCnpj !== '' ? $cpfCnpj : 'Não informado' }}</div>
                                <small class="text-secondary">Pessoa: {{ $client['tipo_pessoa'] !== '' ? ucfirst((string) $client['tipo_pessoa']) : 'Não informada' }}</small>
                            </td>
                            <td data-label="Telefone">
                                <div>{{ $phone !== '' ? $phone : 'Não informado' }}</div>
                                @if ($alternatePhone !== '' && $alternatePhone !== $phone)
                                    <small class="text-secondary d-block">Contato: {{ $alternatePhone }}</small>
                                @endif
                            </td>
                            <td data-label="E-mail">
                                @if ($email !== '')
                                    <a href="mailto:{{ $email }}" class="client-contact-link">{{ $email }}</a>
                                @else
                                    <span class="text-secondary">Não informado</span>
                                @endif
                            </td>
                            <td data-label="Cidade">
                                {{ $cidade !== '' ? trim($cidade . ($uf !== '' ? ' / ' . $uf : '')) : 'Não informada' }}
                            </td>
                            <td data-label="Situação">
                                @include('layouts.partials.status-pill', [
                                    'label' => $statusLabel !== '' ? ucfirst($statusLabel) : 'Sem status',
                                    'color' => '#4da4ff',
                                    'small' => true,
                                ])
                            </td>
                            <td data-label="Ações" class="text-end">
                                <div class="dropdown client-actions-dropdown">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-light dropdown-toggle client-actions-toggle"
                                        data-bs-toggle="dropdown"
                                        aria-expanded="false"
                                    >
                                        <span>Ações</span>
                                        <i class="bi bi-chevron-down"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end client-actions-menu">
                                        <li>
                                            <a href="{{ route('clients.show', $clientId) }}" class="dropdown-item">
                                                <i class="bi bi-eye me-2"></i>
                                                Detalhe
                                            </a>
                                        </li>

                                        @if (\App\Support\DesktopSession::can('clientes', 'editar'))
                                            <li>
                                                <a href="{{ route('clients.edit', $clientId) }}" class="dropdown-item">
                                                    <i class="bi bi-pencil me-2"></i>
                                                    Editar
                                                </a>
                                            </li>
                                        @endif

                                        @if (\App\Support\DesktopSession::can('os', 'visualizar'))
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a href="{{ route('orders.index', ['client_id' => $clientId]) }}" class="dropdown-item">
                                                    <i class="bi bi-clipboard2-check me-2"></i>
                                                    Abrir OS
                                                </a>
                                            </li>
                                        @endif

                                        @if (\App\Support\DesktopSession::can('equipamentos', 'visualizar'))
                                            <li>
                                                <a href="{{ route('equipments.index', ['client_id' => $clientId]) }}" class="dropdown-item">
                                                    <i class="bi bi-laptop me-2"></i>
                                                    Abrir equipamentos
                                                </a>
                                            </li>
                                        @endif

                                        @if ($phone !== '' || $email !== '')
                                            <li><hr class="dropdown-divider"></li>
                                        @endif

                                        @if ($phone !== '')
                                            <li>
                                                <a href="tel:{{ $phoneDigits }}" class="dropdown-item">
                                                    <i class="bi bi-telephone me-2"></i>
                                                    Ligar
                                                </a>
                                            </li>
                                        @endif

                                        @if ($whatsappUrl !== '')
                                            <li>
                                                <a href="{{ $whatsappUrl }}" target="_blank" rel="noreferrer" class="dropdown-item">
                                                    <i class="bi bi-whatsapp me-2"></i>
                                                    WhatsApp
                                                </a>
                                            </li>
                                        @endif

                                        @if ($email !== '')
                                            <li>
                                                <a href="mailto:{{ $email }}" class="dropdown-item">
                                                    <i class="bi bi-envelope me-2"></i>
                                                    E-mail
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
                'icon' => 'bi-person-x',
                'title' => 'Nenhum cliente encontrado',
                'message' => 'Ajuste a busca, o status ou a ordenação para localizar o cadastro desejado.',
            ])
        @endif
    </section>
@endsection
