@extends('layouts.app')

@section('content')
    <section class="desktop-form-card mb-4">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Fornecedores</h2>
                <p class="surface-subtitle">
                    Cadastro operacional espelhado do legado, com consulta por CNPJ, controle de status e vínculos para o fluxo comercial.
                </p>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('suppliers.help') }}" class="btn btn-outline-info">
                    <i class="bi bi-question-circle me-2"></i>
                    Ajuda
                </a>

                @if (\App\Support\DesktopSession::can('fornecedores', 'criar'))
                    <a href="{{ route('suppliers.create') }}" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-2"></i>
                        Novo fornecedor
                    </a>
                @endif
            </div>
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
                    placeholder="Nome, documento, telefone, cidade ou observações"
                >
            </div>

            <div>
                <label for="active">Status</label>
                <select id="active" name="active" class="form-select">
                    <option value="" @selected(($filters['active'] ?? '') === '' )>Todos</option>
                    <option value="1" @selected((string) ($filters['active'] ?? '') === '1')>Ativo</option>
                    <option value="0" @selected((string) ($filters['active'] ?? '') === '0')>Inativo</option>
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
                <a href="{{ route('suppliers.index') }}" class="btn btn-outline-light">Limpar</a>
            </div>
        </form>
    </section>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Cadastro de fornecedores</h2>
                <p class="surface-subtitle">
                    {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} fornecedores retornados pela API central.
                </p>
            </div>

            <span class="desktop-chip">
                <i class="bi bi-truck"></i>
                {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} registros
            </span>
        </div>

        @if ($suppliers !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle supplier-list-table">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome fantasia / Razão social</th>
                        <th>Documento</th>
                        <th>Telefone</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($suppliers as $supplier)
                        @php
                            $supplier = array_merge([
                                'id' => 0,
                                'tipo_pessoa' => 'juridica',
                                'nome_fantasia' => '',
                                'razao_social' => '',
                                'cnpj_cpf' => '',
                                'ie_rg' => '',
                                'email' => '',
                                'telefone1' => '',
                                'telefone2' => '',
                                'cidade' => '',
                                'uf' => '',
                                'ativo' => true,
                            ], is_array($supplier) ? $supplier : []);

                            $supplierId = (int) ($supplier['id'] ?? 0);
                            $fantasyName = trim((string) ($supplier['nome_fantasia'] ?? ''));
                            $legalName = trim((string) ($supplier['razao_social'] ?? ''));
                            $document = trim((string) ($supplier['cnpj_cpf'] ?? ''));
                            $phone = trim((string) ($supplier['telefone1'] ?? ''));
                            $active = (bool) ($supplier['ativo'] ?? false);
                        @endphp
                        <tr>
                            <td data-label="ID">{{ $supplierId > 0 ? $supplierId : '-' }}</td>
                            <td data-label="Nome fantasia / Razão social">
                                <div class="fw-semibold">
                                    {{ $fantasyName !== '' ? $fantasyName : ($legalName !== '' ? $legalName : 'Sem nome') }}
                                </div>
                                @if ($legalName !== '' && $legalName !== $fantasyName)
                                    <small class="text-secondary d-block">{{ $legalName }}</small>
                                @endif
                                <small class="text-secondary d-block mt-1">
                                    Tipo: {{ ucfirst((string) ($supplier['tipo_pessoa'] ?? 'juridica')) }}
                                </small>
                            </td>
                            <td data-label="Documento">
                                <div>{{ $document !== '' ? $document : 'Não informado' }}</div>
                                @if (trim((string) ($supplier['ie_rg'] ?? '')) !== '')
                                    <small class="text-secondary d-block">IE/RG: {{ $supplier['ie_rg'] }}</small>
                                @endif
                            </td>
                            <td data-label="Telefone">
                                <div>{{ $phone !== '' ? $phone : 'Não informado' }}</div>
                                @if (trim((string) ($supplier['email'] ?? '')) !== '')
                                    <small class="text-secondary d-block">{{ $supplier['email'] }}</small>
                                @endif
                            </td>
                            <td data-label="Status">
                                @include('layouts.partials.status-pill', [
                                    'label' => $active ? 'Ativo' : 'Inativo',
                                    'color' => $active ? '#29c384' : '#8b93a7',
                                    'small' => true,
                                ])
                            </td>
                            <td data-label="Ações" class="text-end">
                                <div class="dropdown supplier-actions-dropdown">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-light dropdown-toggle supplier-actions-toggle"
                                        data-bs-toggle="dropdown"
                                        aria-expanded="false"
                                    >
                                        <span>Ações</span>
                                        <i class="bi bi-chevron-down"></i>
                                    </button>

                                    <ul class="dropdown-menu dropdown-menu-end supplier-actions-menu">
                                        @if (\App\Support\DesktopSession::can('fornecedores', 'editar'))
                                            <li>
                                                <a href="{{ route('suppliers.edit', $supplierId) }}" class="dropdown-item">
                                                    <i class="bi bi-pencil me-2"></i>
                                                    Editar
                                                </a>
                                            </li>
                                        @endif

                                        @if ($active && \App\Support\DesktopSession::can('fornecedores', 'encerrar'))
                                            <li>
                                                <form
                                                    method="post"
                                                    action="{{ route('suppliers.close', $supplierId) }}"
                                                    data-confirm="Deseja encerrar este fornecedor?"
                                                    data-confirm-title="Encerrar fornecedor"
                                                    data-confirm-button="Sim, encerrar"
                                                >
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="dropdown-item text-warning">
                                                        <i class="bi bi-archive me-2"></i>
                                                        Encerrar
                                                    </button>
                                                </form>
                                            </li>
                                        @endif

                                        @if (\App\Support\DesktopSession::can('fornecedores', 'excluir'))
                                            <li>
                                                <form
                                                    method="post"
                                                    action="{{ route('suppliers.destroy', $supplierId) }}"
                                                    data-confirm="Deseja excluir este fornecedor? Esta ação não pode ser desfeita."
                                                    data-confirm-title="Excluir fornecedor"
                                                    data-confirm-button="Sim, excluir"
                                                >
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="dropdown-item text-danger">
                                                        <i class="bi bi-trash me-2"></i>
                                                        Excluir
                                                    </button>
                                                </form>
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
                'icon' => 'bi-truck',
                'title' => 'Nenhum fornecedor encontrado',
                'message' => 'Ajuste os filtros ou cadastre o primeiro fornecedor para começar o acompanhamento comercial.',
            ])
        @endif
    </section>
@endsection
