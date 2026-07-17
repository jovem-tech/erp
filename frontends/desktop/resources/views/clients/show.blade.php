@extends('layouts.app')

@section('content')
    @php
        $client = array_merge([
            'tipo_pessoa' => '',
            'nome_razao' => '',
            'cpf_cnpj' => '',
            'rg_ie' => '',
            'email' => '',
            'telefone1' => '',
            'telefone2' => '',
            'nome_contato' => '',
            'telefone_contato' => '',
            'cep' => '',
            'endereco' => '',
            'numero' => '',
            'complemento' => '',
            'referencia' => '',
            'bairro' => '',
            'cidade' => '',
            'uf' => '',
            'observacoes' => '',
            'status_cadastro' => '',
        ], $client ?? []);
        $clientName = (string) ($client['nome_razao'] ?? '');
        $phone = trim((string) ($client['telefone1'] ?? ''));
        $email = trim((string) ($client['email'] ?? ''));
        $phoneDigits = preg_replace('/\D+/', '', $phone);
        $whatsappUrl = $phoneDigits !== '' ? 'https://wa.me/55' . $phoneDigits : '';
        $clientOrders = $orders ?? [];
        $clientEquipments = $equipments ?? [];
        $ordersTotal = (int) ($ordersPagination['total'] ?? 0);
        $equipmentsTotal = (int) ($equipmentsPagination['total'] ?? 0);
        $canViewOrders = (bool) ($canViewOrders ?? false);
        $canViewEquipments = (bool) ($canViewEquipments ?? false);
        $canViewFinanceiro = (bool) ($canViewFinanceiro ?? false);
        $canCreateOrder = \App\Support\DesktopSession::can('os', 'criar');
        $clientFinanceiro = $financeiro ?? [];
        $financeiroTotal = (int) ($financeiroPagination['total'] ?? 0);
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Cliente</p>
            <h2 class="surface-title fs-3 mb-2">{{ $clientName !== '' ? $clientName : 'Sem nome cadastrado' }}</h2>
            <div class="d-flex flex-wrap gap-2">
                @include('layouts.partials.status-pill', [
                    'label' => $client['status_cadastro'] !== '' ? ucfirst((string) $client['status_cadastro']) : 'Sem status',
                    'color' => '#4da4ff',
                ])
                <span class="desktop-chip">{{ $client['tipo_pessoa'] !== '' ? ucfirst((string) $client['tipo_pessoa']) : 'Tipo não informado' }}</span>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('clients.index') }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>
                Voltar
            </a>
            @if ($canCreateOrder)
                <a href="{{ $newOrderUrl }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-2"></i>
                    Nova OS
                </a>
            @endif

            @if ($canCreateOrder || \App\Support\DesktopSession::can('clientes', 'editar') || $canViewOrders || $canViewEquipments)
                <div class="dropdown os-actions-dropdown">
                    <button type="button"
                        class="btn btn-outline-light dropdown-toggle os-actions-toggle"
                        data-bs-toggle="dropdown"
                        aria-expanded="false">
                        Mais ações
                    </button>

                    <div class="dropdown-menu dropdown-menu-end os-actions-menu">
                        @if ($canCreateOrder)
                            <a href="{{ $newOrderUrl }}" class="dropdown-item" data-new-order-action="client">
                                <i class="bi bi-plus-circle me-2"></i>Nova OS
                            </a>
                        @endif
                        @if (\App\Support\DesktopSession::can('clientes', 'editar'))
                            <a href="{{ $editUrl }}" class="dropdown-item">
                                <i class="bi bi-pencil-square me-2"></i>Editar cliente
                            </a>
                        @endif
                        @if ($canViewOrders)
                            <a href="{{ $ordersIndexUrl }}" class="dropdown-item">
                                <i class="bi bi-clipboard-check me-2"></i>Ver OS do cliente
                            </a>
                        @endif
                        @if ($canViewEquipments)
                            <a href="{{ $equipmentsIndexUrl }}" class="dropdown-item">
                                <i class="bi bi-laptop me-2"></i>Ver equipamentos
                            </a>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

    <section class="surface-card mb-4">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Ações rápidas</h2>
                <p class="surface-subtitle">Contato direto para acelerar atendimento, cobrança e retorno operacional.</p>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2">
            @if ($phone !== '')
                <a href="tel:{{ $phoneDigits }}" class="btn btn-outline-light">
                    <i class="bi bi-telephone me-2"></i>
                    Ligar
                </a>
                @if ($whatsappUrl !== '')
                    <a href="{{ $whatsappUrl }}" target="_blank" rel="noreferrer" class="btn btn-outline-light">
                        <i class="bi bi-whatsapp me-2"></i>
                        WhatsApp
                    </a>
                @endif
            @endif

            @if ($email !== '')
                <a href="mailto:{{ $email }}" class="btn btn-outline-light">
                    <i class="bi bi-envelope me-2"></i>
                    E-mail
                </a>
            @endif

            @if ($phone === '' && $email === '')
                <span class="text-secondary">Nenhum contato direto cadastrado.</span>
            @endif
        </div>
    </section>

    @if ($canViewFinanceiro)
        <section class="surface-card mb-4">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">Financeiro do cliente</h2>
                    <p class="surface-subtitle">
                        Recebimentos e cobranças associados ao cliente, avulsos ou originados por ordem de serviço.
                    </p>
                </div>

                <div class="d-flex align-items-center gap-2">
                    <span class="desktop-chip">{{ number_format($financeiroTotal, 0, ',', '.') }} registros</span>
                    <a href="{{ $financeiroIndexUrl }}" class="btn btn-outline-light btn-sm">Ver todos</a>
                </div>
            </div>

            @if ($clientFinanceiro !== [])
                <div class="table-responsive">
                    <table class="table table-stack align-middle">
                        <thead>
                        <tr>
                            <th>Descrição</th>
                            <th>Origem</th>
                            <th>Valor</th>
                            <th>Vencimento</th>
                            <th>Status</th>
                            <th class="text-end">Ação</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($clientFinanceiro as $lancamento)
                            @php
                                $financeiroId = (int) ($lancamento['id'] ?? 0);
                                $financeiroStatus = (string) ($lancamento['status'] ?? 'pendente');
                                $financeiroStatusColors = [
                                    'pendente' => '#f59e0b',
                                    'parcial' => '#3b82f6',
                                    'pago' => '#29c384',
                                    'cancelado' => '#8b93a7',
                                ];
                            @endphp
                            <tr>
                                <td data-label="Descrição">
                                    <div class="fw-semibold">{{ $lancamento['descricao'] ?? 'Sem descrição' }}</div>
                                    <small class="text-secondary">{{ $lancamento['categoria'] ?? 'Sem categoria' }}</small>
                                </td>
                                <td data-label="Origem">
                                    {{ ! empty($lancamento['os_id']) ? 'OS #' . $lancamento['os_id'] : 'Avulso' }}
                                </td>
                                <td data-label="Valor">R$ {{ number_format((float) ($lancamento['valor'] ?? 0), 2, ',', '.') }}</td>
                                <td data-label="Vencimento">
                                    {{ ! empty($lancamento['data_vencimento']) ? \Illuminate\Support\Carbon::parse($lancamento['data_vencimento'])->format('d/m/Y') : '-' }}
                                </td>
                                <td data-label="Status">
                                    @include('layouts.partials.status-pill', [
                                        'label' => ucfirst($financeiroStatus),
                                        'color' => $financeiroStatusColors[$financeiroStatus] ?? '#8b93a7',
                                        'small' => true,
                                    ])
                                </td>
                                <td data-label="Ação" class="text-end">
                                    @if (\App\Support\DesktopSession::can('financeiro', 'editar'))
                                        <a href="{{ route('financeiro.edit', $financeiroId) }}" class="btn btn-sm btn-outline-light">
                                            <i class="bi bi-eye me-1"></i>
                                            Detalhe
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                @include('layouts.partials.empty-state', [
                    'icon' => 'bi-cash-coin',
                    'title' => 'Nenhum lançamento financeiro',
                    'message' => 'Os recebimentos vinculados a este cliente aparecerão aqui.',
                ])
            @endif
        </section>
    @endif

    <section class="desktop-grid desktop-grid-two mb-4">
        <article class="summary-card">
            <span class="summary-card-eyebrow">Ordens vinculadas</span>
            <div class="summary-card-value">{{ $canViewOrders ? number_format($ordersTotal, 0, ',', '.') : '—' }}</div>
            <div class="summary-card-meta">{{ $canViewOrders ? 'OS relacionadas a este cliente na API central.' : 'Acesso restrito para este módulo.' }}</div>
        </article>

        <article class="summary-card">
            <span class="summary-card-eyebrow">Equipamentos vinculados</span>
            <div class="summary-card-value">{{ $canViewEquipments ? number_format($equipmentsTotal, 0, ',', '.') : '—' }}</div>
            <div class="summary-card-meta">{{ $canViewEquipments ? 'Aparelhos vinculados ao cadastro do cliente.' : 'Acesso restrito para este módulo.' }}</div>
        </article>

        <article class="summary-card">
            <span class="summary-card-eyebrow">Contato principal</span>
            <div class="summary-card-value">{{ $client['nome_contato'] !== '' ? $client['nome_contato'] : 'Não informado' }}</div>
            <div class="summary-card-meta">{{ $phone !== '' ? $phone : ($email !== '' ? $email : 'Sem contato principal') }}</div>
        </article>

        <article class="summary-card">
            <span class="summary-card-eyebrow">Documento</span>
            <div class="summary-card-value">{{ $client['cpf_cnpj'] !== '' ? $client['cpf_cnpj'] : 'Não informado' }}</div>
            <div class="summary-card-meta">{{ $client['rg_ie'] !== '' ? $client['rg_ie'] : 'RG/IE não informado' }}</div>
        </article>
    </section>

    <section class="desktop-grid desktop-grid-two mb-4">
        <article class="surface-card">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">Ordens de serviço do cliente</h2>
                    <p class="surface-subtitle">Primeiras OS relacionadas ao cliente, sem acesso direto ao banco pelo desktop.</p>
                </div>

                @if ($canViewOrders)
                    <a href="{{ $ordersIndexUrl }}" class="btn btn-outline-light btn-sm">Ver todas</a>
                @endif
            </div>

            @if ($canViewOrders && $clientOrders !== [])
                <div class="table-responsive">
                    <table class="table table-stack align-middle">
                        <thead>
                        <tr>
                            <th>OS</th>
                            <th>Status</th>
                            <th>Abertura</th>
                            <th>Previsão</th>
                            <th class="text-end">Ação</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($clientOrders as $order)
                            @php
                                $order = array_merge([
                                    'id' => 0,
                                    'numero_os' => '',
                                    'numero_os_legado' => '',
                                    'status_nome' => '',
                                    'status_cor' => '',
                                    'data_abertura' => '',
                                    'data_previsao' => '',
                                ], is_array($order) ? $order : []);
                            @endphp
                            <tr>
                                <td data-label="OS">
                                    <div class="fw-semibold">{{ $order['numero_os'] !== '' ? $order['numero_os'] : '#' . $order['id'] }}</div>
                                    <small class="text-secondary">{{ $order['numero_os_legado'] !== '' ? 'Legado: ' . $order['numero_os_legado'] : 'ID interno ' . $order['id'] }}</small>
                                </td>
                                <td data-label="Status">
                                    @include('layouts.partials.status-pill', [
                                        'label' => $order['status_nome'] !== '' ? $order['status_nome'] : 'Sem status',
                                        'color' => $order['status_cor'] ?? '#64748b',
                                        'small' => true,
                                    ])
                                </td>
                                <td data-label="Abertura">{{ $order['data_abertura'] !== '' ? $order['data_abertura'] : 'Não informada' }}</td>
                                <td data-label="Previsão">{{ $order['data_previsao'] !== '' ? $order['data_previsao'] : 'Sem previsão' }}</td>
                                <td data-label="Ação" class="text-end">
                                    @if (\App\Support\DesktopSession::can('os', 'visualizar'))
                                        <a href="{{ route('orders.show', $order['id']) }}" class="btn btn-sm btn-outline-light">
                                            <i class="bi bi-eye me-1"></i>
                                            Detalhe
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                @include('layouts.partials.empty-state', [
                    'icon' => 'bi-clipboard2-x',
                    'title' => $canViewOrders ? 'Nenhuma OS vinculada' : 'Acesso restrito a OS',
                    'message' => $canViewOrders
                        ? 'Quando existirem ordens relacionadas a este cliente, elas aparecerão aqui com acesso controlado pela API.'
                        : 'Seu perfil não possui permissão para consultar as OS vinculadas a este cliente.',
                ])
            @endif
        </article>

        <article class="surface-card">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">Equipamentos do cliente</h2>
                    <p class="surface-subtitle">Listagem resumida dos aparelhos já vinculados ao cadastro.</p>
                </div>

                @if ($canViewEquipments)
                    <a href="{{ $equipmentsIndexUrl }}" class="btn btn-outline-light btn-sm">Ver todos</a>
                @endif
            </div>

            @if ($canViewEquipments && $clientEquipments !== [])
                <div class="table-responsive">
                    <table class="table table-stack align-middle">
                        <thead>
                        <tr>
                            <th>Resumo técnico</th>
                            <th>Série / IMEI</th>
                            <th>Modalidade</th>
                            <th>Status</th>
                            <th class="text-end">Ação</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($clientEquipments as $equipment)
                            @php
                                $equipment = array_merge([
                                    'id' => 0,
                                    'resumo_tecnico' => '',
                                    'numero_serie' => '',
                                    'imei' => '',
                                    'desktop_modalidade' => '',
                                    'status_operacional' => '',
                                ], is_array($equipment) ? $equipment : []);
                            @endphp
                            <tr>
                                <td data-label="Resumo técnico">{{ $equipment['resumo_tecnico'] !== '' ? $equipment['resumo_tecnico'] : 'Sem resumo técnico' }}</td>
                                <td data-label="Série / IMEI">
                                    @if (($equipment['numero_serie'] ?? '') !== '')
                                        S/N {{ $equipment['numero_serie'] }}
                                    @elseif (($equipment['imei'] ?? '') !== '')
                                        IMEI {{ $equipment['imei'] }}
                                    @else
                                        Não informado
                                    @endif
                                </td>
                                <td data-label="Modalidade">{{ $equipment['desktop_modalidade'] !== '' ? $equipment['desktop_modalidade'] : 'Não informada' }}</td>
                                <td data-label="Status">
                                    @include('layouts.partials.status-pill', [
                                        'label' => $equipment['status_operacional'] !== '' ? $equipment['status_operacional'] : 'Sem status',
                                        'color' => '#29c384',
                                        'small' => true,
                                    ])
                                </td>
                                <td data-label="Ação" class="text-end">
                                    @if (\App\Support\DesktopSession::can('equipamentos', 'visualizar'))
                                        <a href="{{ route('equipments.show', $equipment['id']) }}" class="btn btn-sm btn-outline-light">
                                            <i class="bi bi-eye me-1"></i>
                                            Detalhe
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                @include('layouts.partials.empty-state', [
                    'icon' => 'bi-laptop',
                    'title' => $canViewEquipments ? 'Nenhum equipamento vinculado' : 'Acesso restrito a equipamentos',
                    'message' => $canViewEquipments
                        ? 'Assim que o cliente tiver aparelhos cadastrados, eles aparecerão nesta área.'
                        : 'Seu perfil não possui permissão para consultar os equipamentos vinculados a este cliente.',
                ])
            @endif
        </article>
    </section>

    <section class="desktop-grid desktop-grid-two mb-4">
        <article class="surface-card">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">Dados principais</h2>
                    <p class="surface-subtitle">Informações cadastrais retornadas pela API central.</p>
                </div>
            </div>

            <div class="detail-list">
                <div class="detail-item"><strong>E-mail</strong><span>{{ $client['email'] !== '' ? $client['email'] : 'Não informado' }}</span></div>
                <div class="detail-item"><strong>Telefone principal</strong><span>{{ $client['telefone1'] !== '' ? $client['telefone1'] : 'Não informado' }}</span></div>
                <div class="detail-item"><strong>Telefone secundário</strong><span>{{ $client['telefone2'] !== '' ? $client['telefone2'] : 'Não informado' }}</span></div>
                <div class="detail-item"><strong>Contato</strong><span>{{ $client['nome_contato'] !== '' ? $client['nome_contato'] : 'Não informado' }}</span></div>
                <div class="detail-item"><strong>Telefone do contato</strong><span>{{ $client['telefone_contato'] !== '' ? $client['telefone_contato'] : 'Não informado' }}</span></div>
            </div>
        </article>

        <article class="surface-card">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">Endereço</h2>
                    <p class="surface-subtitle">Leitura pronta para suporte, atendimento e logística.</p>
                </div>
            </div>

            <div class="detail-list">
                <div class="detail-item"><strong>CEP</strong><span>{{ $client['cep'] !== '' ? $client['cep'] : 'Não informado' }}</span></div>
                <div class="detail-item"><strong>Endereço</strong><span>{{ trim(($client['endereco'] ?? '') . ', ' . ($client['numero'] ?? ''), ', ') !== '' ? trim(($client['endereco'] ?? '') . ', ' . ($client['numero'] ?? ''), ', ') : 'Não informado' }}</span></div>
                <div class="detail-item"><strong>Complemento</strong><span>{{ $client['complemento'] !== '' ? $client['complemento'] : 'Não informado' }}</span></div>
                <div class="detail-item"><strong>Bairro</strong><span>{{ $client['bairro'] !== '' ? $client['bairro'] : 'Não informado' }}</span></div>
                <div class="detail-item"><strong>Cidade / UF</strong><span>{{ trim(($client['cidade'] ?? '') . ' / ' . ($client['uf'] ?? ''), ' /') !== '' ? trim(($client['cidade'] ?? '') . ' / ' . ($client['uf'] ?? ''), ' /') : 'Não informado' }}</span></div>
                <div class="detail-item"><strong>Referência</strong><span>{{ $client['referencia'] !== '' ? $client['referencia'] : 'Não informada' }}</span></div>
            </div>
        </article>
    </section>

    <section class="surface-card">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Observações</h2>
                <p class="surface-subtitle">Campo livre do cadastro legado já disponível para leitura via API.</p>
            </div>
        </div>

        <p class="mb-0">{{ $client['observacoes'] !== '' ? $client['observacoes'] : 'Nenhuma observação registrada para este cliente.' }}</p>
    </section>
@endsection
