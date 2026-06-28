@extends('layouts.app')

@section('content')
    <section class="desktop-form-card mb-4">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Serviços</h2>
                <p class="surface-subtitle">
                    Cadastro operacional de serviços espelhado do legado, com controle de status, exportação e importação em lote.
                </p>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('servicos.help') }}" class="btn btn-outline-info">
                    <i class="bi bi-question-circle me-2"></i>
                    Ajuda
                </a>

                <a href="{{ route('servicos.export.csv') }}" class="btn btn-outline-primary">
                    <i class="bi bi-download me-2"></i>
                    Exportar CSV
                </a>

                <a href="{{ route('servicos.download-template') }}" class="btn btn-outline-primary">
                    <i class="bi bi-filetype-csv me-2"></i>
                    Modelo CSV
                </a>

                @if (\App\Support\DesktopSession::can('servicos', 'criar'))
                    <a href="{{ route('servicos.create') }}" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-2"></i>
                        Novo serviço
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
                    placeholder="Nome, descrição, equipamento ou status"
                >
            </div>

            <div>
                <label for="status">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="" @selected(($filters['status'] ?? '') === '')>Todos</option>
                    <option value="ativo" @selected((string) ($filters['status'] ?? '') === 'ativo')>Ativo</option>
                    <option value="encerrado" @selected((string) ($filters['status'] ?? '') === 'encerrado')>Encerrado</option>
                    <option value="inativo" @selected((string) ($filters['status'] ?? '') === 'inativo')>Inativo</option>
                </select>
            </div>

            <div>
                <label for="tipo_equipamento">Tipo de equipamento</label>
                <input
                    type="text"
                    id="tipo_equipamento"
                    name="tipo_equipamento"
                    class="form-control"
                    value="{{ $filters['tipo_equipamento'] ?? '' }}"
                    placeholder="Notebook, Desktop, Smartphone..."
                >
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
                <a href="{{ route('servicos.index') }}" class="btn btn-outline-light">Limpar</a>
            </div>
        </form>
    </section>

    <section class="desktop-form-card mb-4">
        <div class="surface-card-header">
            <div>
                <h3 class="surface-title">Importação em lote</h3>
                <p class="surface-subtitle">Envie um CSV com a mesma estrutura do modelo para carregar vários serviços de uma vez.</p>
            </div>
        </div>

        <form method="post" action="{{ route('servicos.import') }}" enctype="multipart/form-data" class="desktop-filter-grid">
            @csrf
            <div class="col-span-full">
                <label for="arquivo">Arquivo CSV</label>
                <input type="file" id="arquivo" name="arquivo" class="form-control" accept=".csv,.txt">
            </div>

            <div class="field-actions" style="grid-column: span 2;">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="bi bi-upload me-2"></i>
                    Importar lote
                </button>
            </div>
        </form>
    </section>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Cadastro de serviços</h2>
                <p class="surface-subtitle">
                    {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} serviços retornados pela API central.
                </p>
            </div>

            <span class="desktop-chip">
                <i class="bi bi-gear-fill"></i>
                {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} registros
            </span>
        </div>

        @if ($services !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome</th>
                        <th>Descrição</th>
                        <th>Tipo de equipamento</th>
                        <th>Valor</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($services as $service)
                        @php
                            $service = array_merge([
                                'id' => 0,
                                'nome' => '',
                                'descricao' => '',
                                'tipo_equipamento' => '',
                                'valor' => 0,
                                'status' => 'ativo',
                            ], is_array($service) ? $service : []);
                            $serviceId = (int) ($service['id'] ?? 0);
                            $isActive = (string) ($service['status'] ?? 'ativo') === 'ativo';
                        @endphp
                        <tr>
                            <td data-label="ID">{{ $serviceId > 0 ? $serviceId : '-' }}</td>
                            <td data-label="Nome">
                                <div class="fw-semibold">{{ trim((string) ($service['nome'] ?? '')) !== '' ? $service['nome'] : 'Sem nome' }}</div>
                                @if (trim((string) ($service['descricao'] ?? '')) !== '')
                                    <small class="text-secondary d-block mt-1">{{ $service['descricao'] }}</small>
                                @endif
                            </td>
                            <td data-label="Descrição">{{ trim((string) ($service['descricao'] ?? '')) !== '' ? $service['descricao'] : '-' }}</td>
                            <td data-label="Tipo de equipamento">{{ trim((string) ($service['tipo_equipamento'] ?? '')) !== '' ? $service['tipo_equipamento'] : '-' }}</td>
                            <td data-label="Valor">R$ {{ number_format((float) ($service['valor'] ?? 0), 2, ',', '.') }}</td>
                            <td data-label="Status">
                                @include('layouts.partials.status-pill', [
                                    'label' => $isActive ? 'Ativo' : 'Encerrado',
                                    'color' => $isActive ? '#29c384' : '#8b93a7',
                                    'small' => true,
                                ])
                            </td>
                            <td data-label="Ações" class="text-end">
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-outline-light dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                        Ações
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        @if (\App\Support\DesktopSession::can('servicos', 'editar'))
                                            <li>
                                                <a href="{{ route('servicos.edit', $serviceId) }}" class="dropdown-item">
                                                    <i class="bi bi-pencil me-2"></i>Editar
                                                </a>
                                            </li>
                                        @endif

                                        @if ($isActive && \App\Support\DesktopSession::can('servicos', 'encerrar'))
                                            <li>
                                                <form method="post" action="{{ route('servicos.close', $serviceId) }}" data-confirm="Deseja encerrar este serviço?" data-confirm-title="Encerrar serviço" data-confirm-button="Sim, encerrar">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="dropdown-item text-warning">
                                                        <i class="bi bi-archive me-2"></i>Encerrar
                                                    </button>
                                                </form>
                                            </li>
                                        @endif

                                        @if (\App\Support\DesktopSession::can('servicos', 'excluir'))
                                            <li>
                                                <form method="post" action="{{ route('servicos.destroy', $serviceId) }}" data-confirm="Deseja excluir este serviço? Esta ação não pode ser desfeita." data-confirm-title="Excluir serviço" data-confirm-button="Sim, excluir">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="dropdown-item text-danger">
                                                        <i class="bi bi-trash me-2"></i>Excluir
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
                'icon' => 'bi-gear-fill',
                'title' => 'Nenhum serviço encontrado',
                'message' => 'Ajuste os filtros ou cadastre o primeiro serviço para começar o acompanhamento operacional.',
            ])
        @endif
    </section>
@endsection
