@extends('layouts.app')

@section('content')
    @php
        $client = $equipment['client'] ?? null;
        $equipmentName = trim((string) ($equipment['resumo_tecnico'] ?? ''));
        $statusLabel = trim((string) ($equipment['status_operacional'] ?? ''));
        $modality = trim((string) ($equipment['desktop_modalidade'] ?? ''));
        $serial = trim((string) ($equipment['numero_serie'] ?? ''));
        $imei = trim((string) ($equipment['imei'] ?? ''));
        $ordersCount = max(0, (int) ($equipment['orders_count'] ?? 0));
        $clientId = (int) ($equipment['cliente_id'] ?? 0);
        $photos = is_array($equipment['photos'] ?? null) ? $equipment['photos'] : [];
        $primaryPhotoUrl = trim((string) ($equipment['primary_photo_url'] ?? ''));
        if ($primaryPhotoUrl === '' && $photos !== []) {
            $primaryPhotoUrl = trim((string) ($photos[0]['url'] ?? ''));
        }
        $photosCount = count($photos);
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Equipamento</p>
            <h2 class="surface-title fs-3 mb-2">{{ $equipmentName !== '' ? $equipmentName : 'Sem resumo tecnico' }}</h2>
            <div class="d-flex flex-wrap gap-2">
                @include('layouts.partials.status-pill', [
                    'label' => $statusLabel !== '' ? $statusLabel : 'Sem status operacional',
                    'color' => '#29c384',
                ])
                <span class="desktop-chip">{{ $modality !== '' ? ucfirst($modality) : 'Modalidade nao informada' }}</span>
                <span class="desktop-chip">{{ number_format($ordersCount, 0, ',', '.') }} {{ $ordersCount === 1 ? 'OS' : 'OS' }}</span>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('equipments.index') }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>
                Voltar
            </a>
            @if (\App\Support\DesktopSession::can('equipamentos', 'editar'))
                <a href="{{ route('equipments.edit', (int) ($equipment['id'] ?? 0)) }}" class="btn btn-soft">
                    <i class="bi bi-pencil-square me-2"></i>
                    Editar
                </a>
            @endif
            @if ($clientId > 0 && \App\Support\DesktopSession::can('clientes', 'visualizar'))
                <a href="{{ route('clients.show', $clientId) }}" class="btn btn-soft">Abrir cliente</a>
            @endif
            @if ($clientId > 0 && \App\Support\DesktopSession::can('os', 'criar'))
                <a href="{{ $newOrderUrl }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-2"></i>
                    Nova OS
                </a>
            @endif
        </div>
    </div>

    <section class="desktop-grid desktop-grid-two equipment-detail-overview mb-4">
        <article class="surface-card">
            <div class="surface-card-header">
                <div>
                <h2 class="surface-title">Identificação do aparelho</h2>
                    <p class="surface-subtitle">Resumo físico e operacional retornado pela API central.</p>
                </div>
            </div>

            <div class="detail-list">
                <div class="detail-item"><strong>ID interno</strong><span>{{ (int) ($equipment['id'] ?? 0) > 0 ? (int) $equipment['id'] : 'Nao informado' }}</span></div>
                <div class="detail-item"><strong>Número de série</strong><span>{{ $serial !== '' ? $serial : 'Não informado' }}</span></div>
                <div class="detail-item"><strong>IMEI</strong><span>{{ $imei !== '' ? $imei : 'Nao informado' }}</span></div>
                <div class="detail-item"><strong>Cor</strong><span>{{ trim((string) ($equipment['cor'] ?? '')) !== '' ? $equipment['cor'] : 'Não informada' }}</span></div>
                <div class="detail-item"><strong>Status interno</strong><span>{{ trim((string) ($equipment['status'] ?? '')) !== '' ? $equipment['status'] : 'Não informado' }}</span></div>
                <div class="detail-item">
                    <strong>Senha de acesso</strong>
                    <span>
                        @if ((bool) ($equipment['senha_acesso_configurada'] ?? false))
                            ••••••
                            <button type="button" class="btn btn-outline-light btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#revealPasswordModal">
                                <i class="bi bi-eye me-1"></i>Revelar
                            </button>
                        @else
                            Não cadastrada
                        @endif
                    </span>
                </div>
                <div class="detail-item"><strong>Fotos do ciclo</strong><span>{{ number_format($photosCount, 0, ',', '.') }} {{ $photosCount === 1 ? 'foto' : 'fotos' }}</span></div>
                <div class="detail-item"><strong>Cadastro</strong><span>{{ trim((string) ($equipment['created_at'] ?? '')) !== '' ? $equipment['created_at'] : 'Não informado' }}</span></div>
                <div class="detail-item"><strong>Última atualização</strong><span>{{ trim((string) ($equipment['updated_at'] ?? '')) !== '' ? $equipment['updated_at'] : 'Não informada' }}</span></div>
            </div>
        </article>

        <div class="equipment-detail-side">
            <article class="surface-card equipment-detail-photo-card">
                <div class="surface-card-header">
                    <div>
                        <h2 class="surface-title">Foto principal do equipamento</h2>
                        <p class="surface-subtitle">Imagem operacional exibida no detalhe, na listagem e no histórico futuro do ativo.</p>
                    </div>
                </div>

                @if ($primaryPhotoUrl !== '')
                    <a href="{{ $primaryPhotoUrl }}" target="_blank" rel="noreferrer" class="equipment-detail-photo-link">
                        <img src="{{ $primaryPhotoUrl }}" alt="Foto principal do equipamento {{ $equipmentName !== '' ? $equipmentName : 'sem resumo técnico' }}" class="equipment-detail-photo-image" data-photo-fallback>
                    </a>
                    <div class="equipment-detail-photo-placeholder d-none">
                        <i class="bi bi-camera"></i>
                        <strong>Foto indisponível</strong>
                        <span>O arquivo desta foto não foi encontrado no servidor.</span>
                    </div>

                    <div class="equipment-detail-photo-meta">
                        <span class="desktop-chip">Foto principal</span>
                        <small>{{ number_format($photosCount, 0, ',', '.') }} {{ $photosCount === 1 ? 'arquivo vinculado' : 'arquivos vinculados' }}</small>
                    </div>
                @else
                    <div class="equipment-detail-photo-placeholder">
                        <i class="bi bi-camera"></i>
                        <strong>Sem foto principal disponível</strong>
                        <span>Equipamentos legados sem imagem continuam acessíveis, mas novos cadastros exigem foto obrigatória.</span>
                    </div>
                @endif
            </article>

            <article class="surface-card">
                <div class="surface-card-header">
                    <div>
                    <h2 class="surface-title">Cliente vinculado</h2>
                        <p class="surface-subtitle">Relacionamento entregue pela API sem acesso direto do desktop ao banco.</p>
                    </div>
                </div>

                @if ($client)
                    <div class="detail-list">
                        <div class="detail-item"><strong>Nome</strong><span>{{ $client['nome_razao'] !== '' ? $client['nome_razao'] : 'Não informado' }}</span></div>
                        <div class="detail-item"><strong>CPF/CNPJ</strong><span>{{ $client['cpf_cnpj'] !== '' ? $client['cpf_cnpj'] : 'Não informado' }}</span></div>
                        <div class="detail-item"><strong>Telefone</strong><span>{{ $client['telefone1'] !== '' ? $client['telefone1'] : 'Não informado' }}</span></div>
                        <div class="detail-item"><strong>E-mail</strong><span>{{ $client['email'] !== '' ? $client['email'] : 'Não informado' }}</span></div>
                        <div class="detail-item"><strong>Cidade</strong><span>{{ ($client['cidade'] ?? '') !== '' ? trim(($client['cidade'] ?? '') . (($client['uf'] ?? '') !== '' ? ' / ' . $client['uf'] : '')) : 'Não informada' }}</span></div>
                    </div>
                @else
                    @include('layouts.partials.empty-state', [
                        'icon' => 'bi-person-slash',
                        'title' => 'Equipamento sem cliente vinculado',
                        'message' => 'O backend não retornou relacionamento de cliente para este aparelho.',
                    ])
                @endif
            </article>
        </div>
    </section>

    <section class="surface-card mb-4">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Ordens de serviço vinculadas</h2>
                <p class="surface-subtitle">Linha operacional do equipamento no mesmo padrão de leitura do legado.</p>
            </div>

            <div class="d-flex flex-wrap gap-2">
                @if (\App\Support\DesktopSession::can('os', 'visualizar'))
                    <a href="{{ $ordersIndexUrl }}" class="btn btn-outline-light btn-sm">Ver todas</a>
                @endif
            </div>
        </div>

        @if ($canViewOrders)
            @if ($orders !== [])
                <div class="table-responsive">
                    <table class="table table-stack align-middle">
                        <thead>
                        <tr>
                            <th>OS</th>
                            <th>Cliente</th>
                            <th>Status</th>
                        <th>Previsão</th>
                        <th class="text-end">Ação</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($orders as $order)
                            @php
                                $order = array_merge([
                                    'id' => 0,
                                    'numero_os' => '',
                                    'numero_os_legado' => '',
                                    'cliente_nome' => '',
                                    'status_nome' => '',
                                    'status_cor' => '#64748b',
                                    'data_previsao' => '',
                                ], is_array($order) ? $order : []);
                            @endphp
                            <tr>
                                <td data-label="OS">
                                    <div class="fw-semibold">{{ $order['numero_os'] !== '' ? $order['numero_os'] : '#' . $order['id'] }}</div>
                                    <small class="text-secondary">{{ $order['numero_os_legado'] !== '' ? 'Legado: ' . $order['numero_os_legado'] : 'ID interno ' . $order['id'] }}</small>
                                </td>
                                <td data-label="Cliente">{{ $order['cliente_nome'] !== '' ? $order['cliente_nome'] : 'Não informado' }}</td>
                                <td data-label="Status">
                                    @include('layouts.partials.status-pill', [
                                        'label' => $order['status_nome'] !== '' ? $order['status_nome'] : 'Sem status',
                                        'color' => $order['status_cor'] ?? '#64748b',
                                    ])
                                </td>
                                <td data-label="Previsão">{{ $order['data_previsao'] !== '' ? $order['data_previsao'] : 'Sem previsão' }}</td>
                                <td data-label="Ação" class="text-end">
                                    <a href="{{ route('orders.show', $order['id']) }}" class="btn btn-sm btn-outline-light">
                                        <i class="bi bi-eye me-1"></i>
                                        Abrir
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                @include('layouts.partials.pagination', ['pagination' => $ordersPagination, 'filters' => ['equipment_id' => $equipment['id'] ?? 0]])
            @else
                @include('layouts.partials.empty-state', [
                    'icon' => 'bi-clipboard2-x',
                    'title' => 'Nenhuma OS vinculada encontrada',
                    'message' => 'Este equipamento ainda não possui ordens retornadas pela API central.',
                ])
            @endif
        @else
            @include('layouts.partials.empty-state', [
                'icon' => 'bi-lock',
                'title' => 'Acesso restrito às OS',
                'message' => 'O seu perfil não possui permissão para visualizar ordens de serviço.',
            ])
        @endif
    </section>

    <section class="surface-card">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Observações</h2>
                <p class="surface-subtitle">Notas técnicas e observações gerais do equipamento.</p>
            </div>
        </div>

        <p class="mb-0">{{ $equipment['observacoes'] !== '' ? $equipment['observacoes'] : 'Nenhuma observação registrada para este equipamento.' }}</p>
    </section>
@endsection

@push('modals')
    @include('equipments._reveal_password_modal')
@endpush

@section('scripts')
    <script>
        window.__DESKTOP_REVEAL_PASSWORD_MODAL = {
            revealUrl: '{{ route('equipments.reveal-password', (int) ($equipment['id'] ?? 0)) }}',
            csrfToken: '{{ csrf_token() }}',
        };
    </script>
    <script src="{{ asset('assets/js/equipments-reveal-password-modal.js') }}?v={{ filemtime(public_path('assets/js/equipments-reveal-password-modal.js')) }}"></script>
@endsection
