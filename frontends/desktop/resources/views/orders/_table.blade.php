@php
    // Fragmento reaproveitado tanto no carregamento normal da pagina (index.blade.php)
    // quanto na resposta AJAX de OrderController::index() (busca dinamica, paginacao e
    // troca de itens por pagina sem recarregar a pagina toda). Por isso e autossuficiente:
    // recalcula os proprios helpers de exibicao em vez de depender do @php do pai.
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

    $canEditOrder = \App\Support\DesktopSession::can('os', 'editar');
@endphp

@if ($orders !== [])
    @include('layouts.partials.pagination', ['pagination' => $pagination, 'filters' => $filters])
@endif

        @if ($orders !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        @if ($canEditOrder)
                            <th class="os-select-column">
                                <input type="checkbox" class="form-check-input" id="osSelectAll" aria-label="Selecionar todas as OS elegíveis">
                            </th>
                        @endif
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

                            // Bug corrigido em 2026-07-08: usava estado_fluxo='encerrado' pra
                            // esconder "Baixa" — mas Irreparável/Reparo Recusado tambem usam
                            // esse estado_fluxo_padrao sem serem de fato um dos 3 status que
                            // encerram a OS. is_encerrada (grupo_macro='encerrado') e a fonte certa.
                            $isEncerrada = (bool) ($order['is_encerrada'] ?? false);
                            $canEditOrder = \App\Support\DesktopSession::can('os', 'editar');
                            $canCloseOrder = $canEditOrder && ! $isEncerrada;
                            $canCreateBudget = \App\Support\DesktopSession::can('orcamentos', 'criar');
                            $canViewBudget = \App\Support\DesktopSession::can('orcamentos', 'visualizar');
                            $nextStatusOptions = is_array($order['proximas_etapas'] ?? null) ? $order['proximas_etapas'] : [];

                            $financeiroTituloId = (int) ($order['financeiro_titulo_id'] ?? 0);
                            $canViewFinanceiro = \App\Support\DesktopSession::can('financeiro', 'visualizar');
                            $budgetActionUrl = '';
                            $budgetActionLabel = '';

                            if ($budget !== null && $canViewBudget) {
                                $budgetActionUrl = route('orcamentos.show', (int) ($budget['id'] ?? 0));
                                $budgetActionLabel = 'Abrir orçamento';
                            } elseif ($canCreateBudget) {
                                $budgetActionUrl = route('orcamentos.create', ['os_id' => $orderId]);
                                $budgetActionLabel = 'Gerar orçamento';
                            }
                        @endphp
                        <tr data-order-id="{{ $orderId }}">
                            @if ($canEditOrder)
                                <td data-label="Selecionar">
                                    @if ($canCloseOrder)
                                        <input
                                            type="checkbox"
                                            class="form-check-input order-select"
                                            value="{{ $orderId }}"
                                            data-order-numero="{{ $numeroOs !== '' ? $numeroOs : ('#' . $orderId) }}"
                                            data-order-cliente="{{ $clientName !== '' ? $clientName : 'Cliente não informado' }}"
                                            data-order-equipamento="{{ $equipmentSummary !== '' ? $equipmentSummary : 'Sem resumo técnico' }}"
                                            data-order-status="{{ $order['status_nome'] !== '' ? $order['status_nome'] : 'Sem status' }}"
                                            data-order-valor="{{ $valorFinal !== null ? 'R$ ' . number_format((float) $valorFinal, 2, ',', '.') : 'Valor não informado' }}"
                                            aria-label="Selecionar OS {{ $numeroOs !== '' ? $numeroOs : $orderId }} para ações em lote"
                                        >
                                    @endif
                                </td>
                            @endif
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

                                        <li>
                                            <a href="{{ route('orders.documents.center', $orderId) }}" class="dropdown-item">
                                                <i class="bi bi-folder-symlink me-2"></i>
                                                Documentos da OS
                                            </a>
                                        </li>

                                        <li>
                                            <a href="{{ route('orders.map', $orderId) }}" class="dropdown-item">
                                                <i class="bi bi-map me-2"></i>
                                                Mapa da OS
                                            </a>
                                        </li>

                                        @if ($budgetActionUrl !== '')
                                            <li>
                                                <a href="{{ $budgetActionUrl }}" class="dropdown-item">
                                                    <i class="bi bi-receipt me-2"></i>
                                                    {{ $budgetActionLabel }}
                                                </a>
                                            </li>
                                        @endif

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

                                        @if ($canViewFinanceiro && $financeiroTituloId > 0)
                                            <li>
                                                <a href="{{ route('financeiro.show', $financeiroTituloId) }}" class="dropdown-item">
                                                    <i class="bi bi-cash-coin me-2"></i>
                                                    Ver lançamento financeiro
                                                </a>
                                            </li>
                                        @endif

                                        @if ($canEditOrder && ! $isEncerrada && $nextStatusOptions !== [])
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <button type="button" class="dropdown-item"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#orderStatusModal"
                                                    data-order-id="{{ $orderId }}"
                                                    data-order-numero="{{ $order['numero_os'] ?? ('#' . $orderId) }}">
                                                    <i class="bi bi-arrow-left-right me-2"></i>
                                                    Alterar status
                                                </button>
                                            </li>
                                        @endif

                                        @if ($isEncerrada)
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                {{-- Visível para qualquer usuário com acesso ao painel da OS — a
                                                     autorização real é a verificação de credenciais de
                                                     administrador feita no submit do modal. --}}
                                                <button type="button" class="dropdown-item text-danger"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#cancelClosureModal"
                                                    data-order-id="{{ $orderId }}"
                                                    data-order-numero="{{ $order['numero_os'] ?? ('#' . $orderId) }}">
                                                    <i class="bi bi-arrow-counterclockwise me-2"></i>
                                                    Cancelar baixa
                                                </button>
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
