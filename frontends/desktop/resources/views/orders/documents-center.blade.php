@extends('layouts.app')

@section('content')
    @php
        $orderId = (int) ($order['id'] ?? 0);
        $orderNumber = (string) ($order['numero_os'] ?? ('#' . $orderId));
        $clientName = (string) ($order['cliente_nome'] ?? 'Não informado');
        $equipmentLabel = (string) (($order['equipamento_resumo_curto'] ?? '') !== '' ? $order['equipamento_resumo_curto'] : ($order['equipamento_resumo_tecnico'] ?? 'Não informado'));
        $maxAttachments = (int) ($limits['max_attachments'] ?? 10);
        $maxMb = (int) floor(((int) ($limits['max_total_bytes'] ?? (20 * 1024 * 1024))) / 1024 / 1024);
        $shareExpirationOptions = is_array($limits['share_expirations'] ?? null) ? $limits['share_expirations'] : ['24h', '7d', '30d'];
        $dispatchDefaults = is_array($dispatchDefaults ?? null) ? $dispatchDefaults : [];
        $dispatchDestinations = is_array($dispatchDefaults['destinations'] ?? null) ? $dispatchDefaults['destinations'] : [];
        $dispatchDefaultChannel = trim((string) ($dispatchDefaults['channel'] ?? 'whatsapp'));
        $dispatchDefaultWhatsapp = trim((string) ($dispatchDestinations['whatsapp'] ?? ''));
        $dispatchDefaultEmail = trim((string) ($dispatchDestinations['email'] ?? ''));
        $dispatchDefaultMessage = trim((string) ($dispatchDefaults['message'] ?? ''));
        $dispatchDefaultTemplateCode = trim((string) ($dispatchDefaults['template_code'] ?? ''));
        $availableWhatsappTemplates = is_array($whatsappTemplates ?? null) ? $whatsappTemplates : [];
        $pendingSends = count(array_filter(
            $sendHistory,
            static fn (array $send): bool => (string) ($send['status'] ?? '') === 'na_fila'
        ));

        // Mesmas condições de orders/show.blade.php — $order aqui vem do
        // mesmo mapper completo (OrderWorkflowService::showForUser), então
        // já traz status/orçamento/is_encerrada sem precisar de outra chamada.
        $isEncerrada = (bool) ($order['is_encerrada'] ?? false);
        $canEditOrder = \App\Support\DesktopSession::can('os', 'editar');
        $canCreateBudget = \App\Support\DesktopSession::can('orcamentos', 'criar');
        $canCloseOrder = $canEditOrder && ! $isEncerrada;
        $orcamento = $order['orcamento'] ?? null;
        $hasOrcamento = $orcamento !== null;
        $statusOptions = $order['status_disponiveis'] ?? [];
        $nextSteps = $order['proximas_etapas'] ?? [];
        $currentCode = $order['status'] ?? '';
        $currentOption = null;
        foreach ($statusOptions as $option) {
            if (($option['codigo'] ?? '') === $currentCode) {
                $currentOption = $option;
            }
        }
        $selectableStatuses = $nextSteps;
        if ($currentOption !== null) {
            array_unshift($selectableStatuses, $currentOption);
        }
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Central documental da OS</p>
            <h2 class="surface-title fs-3 mb-1">{{ $orderNumber }}</h2>
            <p class="surface-subtitle mb-0">{{ $clientName }} · {{ $equipmentLabel }}</p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-items-start">
            <a href="{{ route('orders.show', $orderId) }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>Voltar para a OS
            </a>

            <div class="dropdown os-actions-dropdown">
                <button type="button"
                    class="btn btn-outline-light dropdown-toggle os-actions-toggle"
                    data-bs-toggle="dropdown"
                    aria-expanded="false">
                    Mais ações
                </button>

                <div class="dropdown-menu dropdown-menu-end os-actions-menu">
                    @if ($canEditOrder)
                        <a href="{{ route('orders.edit', $orderId) }}" class="dropdown-item">
                            <i class="bi bi-pencil me-2"></i>Editar
                        </a>
                    @endif

                    @if ($canEditOrder && ! $isEncerrada && $selectableStatuses !== [])
                        <button type="button"
                            class="dropdown-item"
                            data-bs-toggle="modal"
                            data-bs-target="#orderStatusModal"
                            data-order-id="{{ $orderId }}"
                            data-order-numero="{{ $orderNumber }}">
                            <i class="bi bi-arrow-left-right me-2"></i>Alterar status
                        </button>
                    @endif

                    @if ($canCloseOrder)
                        <a href="{{ route('orders.closure.show', $orderId) }}" class="dropdown-item">
                            <i class="bi bi-cash-coin me-2"></i>Baixa / Adiantamento
                        </a>
                    @endif

                    @if ($hasOrcamento)
                        <a href="{{ route('orcamentos.show', $orcamento['id']) }}" class="dropdown-item">
                            <i class="bi bi-receipt me-2"></i>Ver orçamento
                        </a>
                    @elseif ($canCreateBudget)
                        <a href="{{ route('orcamentos.create', ['os_id' => $orderId]) }}" class="dropdown-item">
                            <i class="bi bi-receipt me-2"></i>Gerar orçamento
                        </a>
                    @endif

                    <a href="{{ route('orders.preview', $orderId) }}" target="_blank" rel="noreferrer" class="dropdown-item">
                        <i class="bi bi-printer me-2"></i>Imprimir
                    </a>

                    @if ($isEncerrada)
                        <div class="dropdown-divider"></div>
                        <button type="button"
                            class="dropdown-item text-danger"
                            data-bs-toggle="modal"
                            data-bs-target="#cancelClosureModal"
                            data-order-id="{{ $orderId }}"
                            data-order-numero="{{ $orderNumber }}">
                            <i class="bi bi-arrow-counterclockwise me-2"></i>Cancelar baixa
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <section class="desktop-grid desktop-grid-three mb-4">
        <article class="summary-card">
            <span class="summary-card-eyebrow">Cliente</span>
            <div class="summary-card-value">{{ $clientName }}</div>
            <div class="summary-card-meta">Histórico centralizado por OS atual.</div>
        </article>
        <article class="summary-card">
            <span class="summary-card-eyebrow">Limite por envio</span>
            <div class="summary-card-value">{{ $maxAttachments }} anexos</div>
            <div class="summary-card-meta">Até {{ $maxMb }} MB por lote, depois disso prefira link.</div>
        </article>
        <article class="summary-card">
            <span class="summary-card-eyebrow">Formatos</span>
            <div class="summary-card-value">A4 e 80mm</div>
            <div class="summary-card-meta">A4 segue como padrão do acervo; 80mm é renderização complementar.</div>
        </article>
    </section>

    <article class="surface-card mb-4" data-doc-catalog-root>
        <div class="d-flex flex-wrap justify-content-between gap-3 align-items-center mb-3">
            <div>
                <h3 class="surface-title fs-5 mb-1">Tipos documentais disponíveis</h3>
                <p class="surface-subtitle mb-0">A geração manual respeita pré-requisitos e os marcos automáticos do fluxo da OS.</p>
            </div>
            <button type="button" class="btn btn-primary" data-doc-generate-batch disabled>
                <i class="bi bi-file-earmark-plus me-2"></i>Gerar selecionados (0)
            </button>
        </div>

        <div data-fragment="catalog">
            @include('orders.documents-center._catalog', ['catalog' => $catalog, 'orderId' => $orderId])
        </div>
    </article>

    <article class="surface-card mb-4" data-doc-center-root>
        <div class="d-flex flex-wrap justify-content-between gap-3 align-items-center mb-3">
            <div>
                <h3 class="surface-title fs-5 mb-1">Acervo versionado do cliente</h3>
                <p class="surface-subtitle mb-0">Selecione versões específicas para enviar, compartilhar, baixar ZIP ou imprimir.</p>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <button type="button" class="btn btn-outline-light" data-doc-select-all>
                    <i class="bi bi-check2-square me-2"></i>Selecionar tudo
                </button>
                <button type="button" class="btn btn-outline-light" data-doc-clear-all>
                    <i class="bi bi-eraser me-2"></i>Limpar seleção
                </button>
            </div>
        </div>

        <div class="doc-action-bar mb-3">
            <span class="doc-selection-count" data-doc-selection-count>Nenhum documento selecionado</span>
            <select class="form-select" id="docActionFormat" data-select2="false">
                <option value="a4">Formato: A4</option>
                <option value="80mm">Formato: 80mm</option>
            </select>
            <button type="button" class="btn btn-outline-light" data-doc-action-zip disabled>
                <i class="bi bi-file-earmark-zip me-2"></i>Baixar ZIP
            </button>
            <button type="button" class="btn btn-outline-light" data-doc-action-print disabled>
                <i class="bi bi-printer me-2"></i>Imprimir
            </button>
            <button type="button" class="btn btn-outline-light" data-doc-action-share disabled>
                <i class="bi bi-link-45deg me-2"></i>Gerar link
            </button>
            <button type="button" class="btn btn-primary" data-doc-action-send disabled>
                <i class="bi bi-send me-2"></i>Enviar
            </button>
        </div>

        <div data-fragment="documents">
            @include('orders.documents-center._documents-table', ['documents' => $documents, 'orderId' => $orderId])
        </div>
    </article>

    <section class="desktop-grid desktop-grid-two">
        <article class="surface-card">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                <h3 class="surface-title fs-5 mb-0">Histórico de envios</h3>
                <span class="text-secondary small d-none" data-doc-sends-live>
                    <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Atualizando…
                </span>
            </div>
            <p class="surface-subtitle">Auditoria resumida de canais, destinos mascarados e status.</p>

            <div data-fragment="sends">
                @include('orders.documents-center._send-history', ['sendHistory' => $sendHistory])
            </div>
        </article>

        <article class="surface-card">
            <h3 class="surface-title fs-5 mb-1">Histórico de links</h3>
            <p class="surface-subtitle">O token bruto não é persistido; o histórico mantém apenas auditoria segura e controle de revogação.</p>

            <div data-fragment="links">
                @include('orders.documents-center._share-links', ['shareLinks' => $shareLinks, 'orderId' => $orderId])
            </div>
        </article>
    </section>
@endsection

@push('modals')
    @include('orders.documents-center._send-modal', [
        'whatsappTemplates' => $availableWhatsappTemplates,
    ])
    @include('orders.documents-center._share-modal', [
        'shareExpirationOptions' => $shareExpirationOptions,
    ])
    @include('orders._status_modal')
    @include('orders._cancel_closure_modal')
@endpush

@section('scripts')
    <script>
        window.__ORDER_DOCUMENTS_CENTER = {!! \Illuminate\Support\Js::from([
            'orderId' => $orderId,
            'orderNumber' => $orderNumber,
            'pendingSends' => $pendingSends,
            'dispatchDefaults' => [
                'channel' => $dispatchDefaultChannel !== '' ? $dispatchDefaultChannel : 'whatsapp',
                'whatsapp' => $dispatchDefaultWhatsapp,
                'email' => $dispatchDefaultEmail,
                'message' => $dispatchDefaultMessage,
                'templateCode' => $dispatchDefaultTemplateCode,
            ],
            'routes' => [
                'state' => route('orders.documents.state', $orderId),
                'generate' => route('orders.documents.generate', $orderId),
                'send' => route('orders.documents.send', $orderId),
                'share' => route('orders.documents.share', $orderId),
                'revokeTemplate' => route('orders.documents.share.revoke', ['order' => $orderId, 'link' => '__LINK__']),
                'archiveTemplate' => route('orders.documents.archive', ['order' => $orderId, 'document' => '__DOC__']),
                'unarchiveTemplate' => route('orders.documents.unarchive', ['order' => $orderId, 'document' => '__DOC__']),
                'download' => route('orders.documents.download', $orderId),
                'print' => route('orders.documents.print', $orderId),
            ],
        ]) !!};
    </script>
    <script src="{{ asset('assets/js/orders-documents-center.js') }}?v={{ filemtime(public_path('assets/js/orders-documents-center.js')) }}"></script>

    {{-- Dropdown "Mais ações" reaproveita os mesmos modais/JS de orders/show.blade.php e orders/index.blade.php --}}
    <script>
        window.__DESKTOP_STATUS_MODAL = {
            statusContextUrlTemplate: '{{ route('orders.status.context', ['order' => '__ORDER__']) }}',
            statusUpdateUrlTemplate: '{{ route('orders.status.update', ['order' => '__ORDER__']) }}',
            proceduresUrlTemplate: '{{ route('orders.procedures.store', ['order' => '__ORDER__']) }}',
            csrfToken: '{{ csrf_token() }}',
        };
        window.__DESKTOP_CANCEL_CLOSURE_MODAL = {
            cancelUrlTemplate: '{{ route('orders.closure.cancel', ['order' => '__ORDER__']) }}',
            csrfToken: '{{ csrf_token() }}',
        };
    </script>
    <script src="{{ asset('assets/js/orders-status-modal.js') }}"></script>
    <script src="{{ asset('assets/js/orders-cancel-closure-modal.js') }}"></script>
@endsection
