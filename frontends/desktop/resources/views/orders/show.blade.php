@extends('layouts.app')

@section('content')
    @php
        $client = $order['cliente'] ?? null;
        $equipment = $order['equipamento'] ?? null;
        $technician = $order['tecnico'] ?? null;

        $photos = $order['fotos'] ?? [];
        $documents = $order['documentos'] ?? [];

        // Foto de perfil = foto principal do equipamento (equipamentos_fotos.is_principal).
        $equipmentPhoto = $order['equipamento_foto'] ?? null;

        // Fotos da OS agrupadas pelo tipo real do catálogo (recepcao/diagnostico/entrega).
        $photosByTipo = static function (array $tipos) use ($photos): array {
            return array_values(array_filter(
                $photos,
                static fn ($photo): bool => in_array($photo['tipo'] ?? '', $tipos, true)
            ));
        };
        $photosRecepcao = $photosByTipo(['recepcao']);
        $photosDiagnostico = $photosByTipo(['diagnostico']);
        $photosEntrega = $photosByTipo(['entrega']);

        // Catálogo de status e próximas etapas reais (transições válidas).
        $statusOptions = $order['status_disponiveis'] ?? [];
        $nextSteps = $order['proximas_etapas'] ?? [];
        $currentCode = $order['status'] ?? '';

        // Status selecionáveis no formulário = etapa atual + transições permitidas.
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

        // OS encerrada (skill sistema-erp-os-fluxo-fechamento): equipamento não
        // está mais de posse da assistência — mudança de status fica bloqueada,
        // só "Cancelar baixa" pode tirar a OS desse estado.
        $isEncerrada = (bool) ($order['is_encerrada'] ?? false);
        $canEditOrder = \App\Support\DesktopSession::can('os', 'editar');
        $canCreateBudget = \App\Support\DesktopSession::can('orcamentos', 'criar');
        $canCloseOrder = $canEditOrder && ! $isEncerrada;

        $orcamento = $order['orcamento'] ?? null;
        $hasOrcamento = $orcamento !== null;

        // Lançamento financeiro mais recente vinculado à OS (título "a
        // receber" não cancelado) — mesmo dado já usado na aba Valores.
        $financeiroTituloId = (int) ($order['financeiro_resumo']['titulo_id'] ?? 0);
        $canViewFinanceiro = \App\Support\DesktopSession::can('financeiro', 'visualizar');

        $checklist = $order['checklist'] ?? null;
        $photoViewerGroup = 'order-' . (int) ($order['id'] ?? 0) . '-photos';

        // Mesma paleta de estados de prazo usada no card da listagem
        // (orders/index.blade.php) — mantém a leitura visual consistente entre
        // as duas telas.
        $deadlineColors = [
            'atrasado' => '#dc2626',
            'critico' => '#f59e0b',
            'vence_hoje' => '#f97316',
            'no_prazo' => '#16a34a',
            'concluido_no_prazo' => '#16a34a',
            'concluido_atrasado' => '#dc2626',
            'sem_previsao' => '#64748b',
        ];
        $deadline = is_array($order['prazo'] ?? null) ? $order['prazo'] : [];
        $deadlineColor = $deadlineColors[$deadline['estado'] ?? 'sem_previsao'] ?? '#64748b';

        $dataPrevisaoFormatada = null;
        if (($order['data_previsao'] ?? '') !== '') {
            try {
                $dataPrevisaoFormatada = \Illuminate\Support\Carbon::parse($order['data_previsao'])->format('d/m/Y');
            } catch (\Throwable) {
                $dataPrevisaoFormatada = null;
            }
        }

        // Duração da OS: dias desde a abertura até a conclusão/entrega (se já
        // encerrada) ou até agora (se ainda aberta) — resumo de relance no
        // cabeçalho, sem precisar abrir a aba Valores para ver as datas cruas.
        $duracaoLabel = null;
        if (($order['data_abertura'] ?? '') !== '') {
            try {
                $aberturaCarbon = \Illuminate\Support\Carbon::parse($order['data_abertura']);
                $referenciaFim = ($order['data_conclusao'] ?? '') !== ''
                    ? $order['data_conclusao']
                    : (($order['data_entrega'] ?? '') !== '' ? $order['data_entrega'] : null);
                $fimCarbon = $referenciaFim !== null ? \Illuminate\Support\Carbon::parse($referenciaFim) : now();
                $dias = $aberturaCarbon->diffInDays($fimCarbon);

                if ($dias === 0) {
                    $duracaoLabel = $isEncerrada ? 'Concluída hoje' : 'Aberta hoje';
                } else {
                    $duracaoLabel = ($isEncerrada ? 'Concluída em ' : 'Aberta há ') . $dias . ' dia' . ($dias === 1 ? '' : 's');
                }
            } catch (\Throwable) {
                $duracaoLabel = null;
            }
        }
    @endphp

    {{-- Cabeçalho + ações principais --}}
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4 os-detail-header">
        <div>
            <p class="desktop-eyebrow">Ordem de serviço</p>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <h2 class="surface-title fs-3 mb-0">{{ ($order['numero_os'] ?? '') !== '' ? $order['numero_os'] : '#' . ($order['id'] ?? 0) }}</h2>
                @include('layouts.partials.status-pill', [
                    'label' => ($order['status_nome'] ?? '') !== '' ? $order['status_nome'] : 'Sem status',
                    'color' => $order['status_cor'] ?? '#64748b',
                ])
            </div>

            <div class="os-header-meta">
                @if ($duracaoLabel !== null)
                    <span class="os-header-meta-item"><i class="bi bi-clock-history"></i>{{ $duracaoLabel }}</span>
                @endif
                @if ($dataPrevisaoFormatada !== null)
                    <span class="os-header-meta-item"><i class="bi bi-calendar-event"></i>Previsão: {{ $dataPrevisaoFormatada }}</span>
                @endif
                @if ($deadline !== [] && ($deadline['estado'] ?? 'sem_previsao') !== 'sem_previsao')
                    @include('layouts.partials.status-pill', [
                        'label' => ($deadline['label'] ?? '') . (($deadline['dias'] ?? null) !== null ? ' (' . $deadline['dias'] . 'd)' : ''),
                        'color' => $deadlineColor,
                        'small' => true,
                    ])
                @endif
                <span class="os-header-meta-item">
                    <i class="bi bi-person-badge"></i>{{ ($technician['nome'] ?? '') !== '' ? $technician['nome'] : 'Técnico não atribuído' }}
                </span>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 align-items-start os-header-actions">
            
            <div class="dropdown os-actions-dropdown">
                <button type="button"
                    class="btn btn-outline-light dropdown-toggle os-actions-toggle"
                    data-bs-toggle="dropdown"
                    aria-expanded="false">
                    Mais ações
                </button>

                <div class="dropdown-menu dropdown-menu-end os-actions-menu">
                    <a href="{{ route('orders.documents.center', $order['id']) }}" class="dropdown-item">
                        <i class="bi bi-folder-symlink me-2"></i>Documentos da OS
                    </a>

                    @if ($canEditOrder)
                        <a href="{{ route('orders.edit', $order['id']) }}" class="dropdown-item">
                            <i class="bi bi-pencil me-2"></i>Editar
                        </a>
                    @endif

                    @if ($canEditOrder && ! $isEncerrada && ($selectableStatuses ?? []) !== [])
                        <button type="button"
                            class="dropdown-item"
                            data-bs-toggle="modal"
                            data-bs-target="#orderStatusModal"
                            data-order-id="{{ $order['id'] }}"
                            data-order-numero="{{ $order['numero_os'] ?? ('#' . $order['id']) }}">
                            <i class="bi bi-arrow-left-right me-2"></i>Alterar status
                        </button>
                    @endif

                    @if ($canCloseOrder)
                        <a href="{{ route('orders.closure.show', $order['id']) }}" class="dropdown-item">
                            <i class="bi bi-cash-coin me-2"></i>Baixa / Adiantamento
                        </a>
                    @endif

                    @if ($hasOrcamento)
                        <a href="{{ route('orcamentos.show', $orcamento['id']) }}" class="dropdown-item">
                            <i class="bi bi-receipt me-2"></i>Ver orçamento
                        </a>
                    @elseif ($canCreateBudget)
                        <a href="{{ route('orcamentos.create', ['os_id' => $order['id']]) }}" class="dropdown-item">
                            <i class="bi bi-receipt me-2"></i>Gerar orçamento
                        </a>
                    @endif

                    @if ($canViewFinanceiro && $financeiroTituloId > 0)
                        <a href="{{ route('financeiro.show', $financeiroTituloId) }}" class="dropdown-item">
                            <i class="bi bi-cash-coin me-2"></i>Ver lançamento financeiro
                        </a>
                    @endif

                    <a href="{{ route('orders.preview', $order['id']) }}" target="_blank" rel="noreferrer" class="dropdown-item">
                        <i class="bi bi-printer me-2"></i>Imprimir
                    </a>

                    @if ($isEncerrada)
                        <div class="dropdown-divider"></div>
                        {{-- Visível para qualquer usuário com acesso ao painel da OS — a
                             autorização real é a verificação de credenciais de
                             administrador feita no submit do modal. --}}
                        <button type="button"
                            class="dropdown-item text-danger"
                            data-bs-toggle="modal"
                            data-bs-target="#cancelClosureModal"
                            data-order-id="{{ $order['id'] }}"
                            data-order-numero="{{ $order['numero_os'] ?? ('#' . $order['id']) }}">
                            <i class="bi bi-arrow-counterclockwise me-2"></i>Cancelar baixa
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="os-detail-layout">
        {{-- Coluna lateral: foto do equipamento + progresso do fluxo --}}
        <aside class="os-detail-aside">
            <article class="surface-card os-photo-card">
                <span class="summary-card-eyebrow"><i class="bi bi-image me-1"></i>Fotos do equipamento</span>
                @if ($equipmentPhoto && ($equipmentPhoto['id'] ?? 0) > 0)
                    <a href="{{ route('equipments.photos.show', [$equipmentPhoto['equipamento_id'], $equipmentPhoto['id']]) }}"
                        class="os-photo-frame"
                        target="_blank"
                        rel="noreferrer"
                        data-photo-viewer-trigger
                        data-photo-viewer-group="{{ $photoViewerGroup }}"
                        data-photo-viewer-title="Foto principal do equipamento">
                        <img src="{{ route('equipments.photos.show', [$equipmentPhoto['equipamento_id'], $equipmentPhoto['id']]) }}" alt="Foto principal do equipamento">
                    </a>
                @else
                    <div class="os-photo-frame">
                        <div class="os-photo-empty">
                            <i class="bi bi-camera"></i>
                            <span>Sem foto</span>
                        </div>
                    </div>
                @endif
                <div class="os-photo-serial">
                    <i class="bi bi-upc-scan"></i>
                    SN: {{ ($order['equipamento_numero_serie'] ?? '') !== '' ? $order['equipamento_numero_serie'] : '—' }}
                </div>
            </article>

            {{-- Histórico unificado e categorizado de movimentações (os_eventos) --}}
            @include('orders._event_timeline')
        </aside>

        {{-- Painel principal: resumo + abas --}}
        <div class="os-detail-main">
            <section class="desktop-grid desktop-grid-two mb-4 os-summary-section">
                <article class="summary-card">
                    <span class="summary-card-eyebrow">Cliente</span>
                    <div class="summary-card-value">{{ ($order['cliente_nome'] ?? '') !== '' ? $order['cliente_nome'] : 'Não informado' }}</div>
                    <div class="summary-card-meta">
                        {{ $client['telefone1'] ?? 'Telefone não informado' }}
                        @if (($client['email'] ?? '') !== '')
                            · {{ $client['email'] }}
                        @endif
                    </div>
                </article>

                <article class="summary-card">
                    <span class="summary-card-eyebrow">Equipamento</span>
                    <div class="summary-card-value">{{ ($order['equipamento_resumo_curto'] ?? '') !== '' ? $order['equipamento_resumo_curto'] : 'Sem equipamento informado' }}</div>
                    <div class="summary-card-meta">
                        {{ ($order['equipamento_numero_serie'] ?? '') !== '' ? 'S/N ' . $order['equipamento_numero_serie'] : 'Série não informada' }}
                        @if(($order['equipamento_resumo_tecnico'] ?? '') !== '')
                            · {{ $order['equipamento_resumo_tecnico'] }}
                        @endif
                    </div>
                </article>
            </section>

            <article class="surface-card os-tabs-card" data-os-tabs>
                <div class="equipment-tabs" role="tablist" aria-label="Detalhes da ordem de serviço">
                    <button type="button" class="equipment-tab is-active" data-os-tab="informacoes" aria-pressed="true">
                        <i class="bi bi-info-circle"></i>Informações
                    </button>
                    <button type="button" class="equipment-tab" data-os-tab="orcamento" aria-pressed="false">
                        <i class="bi bi-receipt"></i>Orçamento
                    </button>
                    <button type="button" class="equipment-tab" data-os-tab="diagnostico" aria-pressed="false">
                        <i class="bi bi-clipboard2-pulse"></i>Diagnóstico
                    </button>
                    <button type="button" class="equipment-tab" data-os-tab="fotos" aria-pressed="false">
                        <i class="bi bi-images"></i>Fotos
                    </button>
                    <button type="button" class="equipment-tab" data-os-tab="documentos" aria-pressed="false">
                        <i class="bi bi-file-earmark-text"></i>Documentos
                    </button>
                    <button type="button" class="equipment-tab" data-os-tab="valores" aria-pressed="false">
                        <i class="bi bi-cash-coin"></i>Valores
                    </button>
                </div>

                {{-- Aba: Informações --}}
                <div class="equipment-tab-panel is-active" data-os-panel="informacoes">
                    @if ($nextSteps !== [])
                        <div class="os-panel-block">
                            <h3 class="os-panel-title"><i class="bi bi-signpost-split me-1"></i>Próximas etapas prováveis</h3>
                            <div class="d-flex flex-wrap gap-2">
                                @foreach ($nextSteps as $step)
                                    <span class="os-next-step">{{ $step['nome'] ?? $step['codigo'] }}</span>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="os-panel-block">
                        <h3 class="os-panel-title"><i class="bi bi-arrow-repeat me-1"></i>Status atual</h3>
                        <p class="surface-subtitle">A mudança de status deve ser feita pelo fluxo apropriado da OS.</p>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            @include('layouts.partials.status-pill', [
                                'label' => ($order['status_nome'] ?? '') !== '' ? $order['status_nome'] : 'Sem status',
                                'color' => $order['status_cor'] ?? '#64748b',
                            ])
                        </div>
                    </div>
                    <div class="os-panel-block">
                        <h3 class="os-panel-title"><i class="bi bi-chat-left-text me-1"></i>Relato do cliente</h3>
                        <p class="mb-0">{{ ($order['relato_cliente'] ?? '') !== '' ? $order['relato_cliente'] : 'Nenhum relato registrado.' }}</p>
                    </div>

                    <div class="os-panel-block">
                        <h3 class="os-panel-title"><i class="bi bi-ui-checks me-1"></i>Checklist de entrada</h3>
                        @if ($checklist)
                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <span class="desktop-chip">{{ ucfirst(str_replace('_', ' ', $checklist['status'] ?? 'rascunho')) }}</span>
                                <span class="desktop-chip">{{ $checklist['total_itens'] ?? 0 }} itens</span>
                                @if (($checklist['total_discrepancias'] ?? 0) > 0)
                                    <span class="os-next-step">{{ $checklist['total_discrepancias'] }} discrepância(s)</span>
                                @else
                                    <span class="desktop-chip">Sem discrepâncias</span>
                                @endif
                            </div>
                            <p class="mb-0">{{ ($checklist['resumo_texto'] ?? '') !== '' ? $checklist['resumo_texto'] : 'Checklist registrado, sem observações adicionais.' }}</p>
                        @else
                            <p class="surface-subtitle mb-0"><span class="desktop-chip">Checklist não preenchido</span></p>
                        @endif
                    </div>
                </div>

                {{-- Aba: Orçamento --}}
                <div class="equipment-tab-panel" data-os-panel="orcamento">
                    @if ($hasOrcamento)
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                            <div>
                                <h3 class="os-panel-title mb-1">
                                    <i class="bi bi-receipt me-1"></i>Orçamento
                                    {{ ($orcamento['numero'] ?? '') !== '' ? $orcamento['numero'] : '#' . ($orcamento['id'] ?? 0) }}
                                    <span class="os-count">v{{ $orcamento['versao'] ?? 1 }}</span>
                                </h3>
                                <span class="desktop-chip">{{ $orcamento['status_label'] ?? 'Sem status' }}</span>
                                @if ($orcamento['aprovado'] ?? false)
                                    <span class="os-next-step">Aprovado</span>
                                @endif
                            </div>
                            @if (\App\Support\DesktopSession::can('orcamentos', 'visualizar'))
                                <a href="{{ route('orcamentos.show', $orcamento['id']) }}" class="btn btn-soft btn-sm">
                                    <i class="bi bi-box-arrow-up-right me-2"></i>Abrir orçamento
                                </a>
                            @endif
                        </div>
                        <div class="detail-list">
                            <div class="detail-item"><strong>Subtotal</strong><span>{{ ($orcamento['subtotal'] ?? null) !== null ? 'R$ ' . $orcamento['subtotal'] : 'R$ 0,00' }}</span></div>
                            <div class="detail-item"><strong>Desconto</strong><span>{{ ($orcamento['desconto'] ?? null) !== null ? 'R$ ' . $orcamento['desconto'] : 'R$ 0,00' }}</span></div>
                            <div class="detail-item"><strong>Total</strong><span>{{ ($orcamento['total'] ?? null) !== null ? 'R$ ' . $orcamento['total'] : 'R$ 0,00' }}</span></div>
                            <div class="detail-item"><strong>Validade</strong><span>{{ ($orcamento['validade_data'] ?? '') !== '' ? $orcamento['validade_data'] : 'Não definida' }}</span></div>
                            <div class="detail-item"><strong>Enviado em</strong><span>{{ ($orcamento['enviado_em'] ?? '') !== '' ? $orcamento['enviado_em'] : 'Não enviado' }}</span></div>
                            <div class="detail-item"><strong>Aprovado em</strong><span>{{ ($orcamento['aprovado_em'] ?? '') !== '' ? $orcamento['aprovado_em'] : 'Não aprovado' }}</span></div>
                        </div>
                    @else
                        @include('layouts.partials.empty-state', [
                            'icon' => 'bi-receipt',
                            'title' => 'Sem orçamento vinculado',
                            'message' => 'Esta OS ainda não possui um orçamento vinculado.',
                        ])
                        @if (\App\Support\DesktopSession::can('orcamentos', 'criar'))
                            <div class="d-flex justify-content-center">
                                <a href="{{ route('orcamentos.create', ['os_id' => $order['id']]) }}" class="btn btn-primary">
                                    <i class="bi bi-plus-lg me-2"></i>Gerar orçamento
                                </a>
                            </div>
                        @endif
                    @endif
                </div>

                {{-- Aba: Diagnóstico --}}
                <div class="equipment-tab-panel" data-os-panel="diagnostico">
                    <div class="detail-list">
                        <div class="detail-item">
                            <strong>Técnico responsável</strong>
                            <span>
                                {{ ($technician['nome'] ?? '') !== '' ? $technician['nome'] : 'Não atribuído' }}
                                @php $tecnicoMeta = $technician['email'] ?? ($technician['perfil'] ?? ''); @endphp
                                @if (($tecnicoMeta ?? '') !== '')
                                    · {{ $tecnicoMeta }}
                                @endif
                            </span>
                        </div>
                        <div class="detail-item">
                            <strong>Defeito relatado pelo cliente</strong>
                            <p>{{ ($order['relato_cliente'] ?? '') !== '' ? $order['relato_cliente'] : 'Nenhum relato registrado.' }}</p>
                        </div>
                        <div class="detail-item">
                            <strong>Diagnóstico técnico</strong>
                            <p>{{ ($order['diagnostico_tecnico'] ?? '') !== '' ? $order['diagnostico_tecnico'] : 'Não informado' }}</p>
                        </div>
                        <div class="detail-item">
                            <strong>Solução aplicada</strong>
                            <p>{{ ($order['solucao_aplicada'] ?? '') !== '' ? $order['solucao_aplicada'] : 'Não informada' }}</p>
                        </div>
                        <div class="detail-item">
                            <strong>Procedimentos executados</strong>
                            <p>{{ ($order['procedimentos_executados'] ?? '') !== '' ? $order['procedimentos_executados'] : 'Não informados' }}</p>
                        </div>
                        <div class="detail-item"><strong>Acessórios</strong><span>{{ ($order['acessorios'] ?? '') !== '' ? $order['acessorios'] : 'Não informados' }}</span></div>
                        <div class="detail-item"><strong>Observações internas</strong><p>{{ ($order['observacoes_internas'] ?? '') !== '' ? $order['observacoes_internas'] : 'Sem observações' }}</p></div>
                        <div class="detail-item"><strong>Observações do cliente</strong><p>{{ ($order['observacoes_cliente'] ?? '') !== '' ? $order['observacoes_cliente'] : 'Sem observações' }}</p></div>
                    </div>
                </div>

                {{-- Aba: Fotos --}}
                <div class="equipment-tab-panel" data-os-panel="fotos">
                    @if ($photos !== [])
                        @foreach (['Fotos de recepção' => $photosRecepcao, 'Fotos de diagnóstico' => $photosDiagnostico, 'Fotos de entrega' => $photosEntrega] as $groupLabel => $groupPhotos)
                            @if ($groupPhotos !== [])
                                <div class="os-panel-block">
                                    <h3 class="os-panel-title">{{ $groupLabel }} <span class="os-count">{{ count($groupPhotos) }}</span></h3>
                                    <div class="os-photo-grid">
                                        @foreach ($groupPhotos as $photo)
                                            <a href="{{ route('orders.photos.show', [$order['id'], $photo['id']]) }}"
                                                class="os-photo-thumb"
                                                target="_blank"
                                                rel="noreferrer"
                                                title="{{ $photo['tipo_label'] ?? 'Foto' }}"
                                                data-photo-viewer-trigger
                                                data-photo-viewer-group="{{ $photoViewerGroup }}"
                                                data-photo-viewer-title="{{ $photo['tipo_label'] ?? 'Foto' }}">
                                                <img src="{{ route('orders.photos.show', [$order['id'], $photo['id']]) }}" alt="{{ $photo['tipo_label'] ?? 'Foto' }}">
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    @else
                        @include('layouts.partials.empty-state', [
                            'icon' => 'bi-images',
                            'title' => 'Sem fotos vinculadas',
                            'message' => 'Quando existirem imagens desta OS, o backend central fará a mediação do acesso.',
                        ])
                    @endif
                </div>

                {{-- Aba: Documentos --}}
                <div class="equipment-tab-panel" data-os-panel="documentos">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                        <div>
                            <h3 class="os-panel-title mb-1"><i class="bi bi-file-earmark-text me-1"></i>Acervo documental da OS</h3>
                            <p class="surface-subtitle mb-0">Abra a central para gerar, versionar, compartilhar, reenviar e imprimir os documentos do cliente.</p>
                        </div>
                        <a href="{{ route('orders.documents.center', $order['id']) }}" class="btn btn-soft btn-sm">
                            <i class="bi bi-folder-symlink me-2"></i>Abrir central de documentos
                        </a>
                    </div>

                    @if ($documents !== [])
                        <div class="attachment-grid">
                            @foreach ($documents as $document)
                                <article class="attachment-card">
                                    <div class="attachment-preview"><i class="bi bi-file-earmark-pdf"></i></div>
                                    <div>
                                        <strong>{{ ($document['tipo_label'] ?? '') !== '' ? $document['tipo_label'] : 'Documento' }}</strong>
                                        <small>{{ ($document['nome_arquivo'] ?? '') !== '' ? $document['nome_arquivo'] : 'Arquivo sem nome' }}</small>
                                    </div>
                                    <a href="{{ route('orders.documents.show', [$order['id'], $document['id']]) }}" target="_blank" rel="noreferrer" class="btn btn-outline-light btn-sm">Abrir documento</a>
                                </article>
                            @endforeach
                        </div>
                    @else
                        @include('layouts.partials.empty-state', [
                            'icon' => 'bi-file-earmark-x',
                            'title' => 'Sem documentos vinculados',
                            'message' => 'Os PDFs desta OS aparecerão aqui assim que existirem no repositório integrado.',
                        ])
                    @endif

                    <div class="os-panel-block mt-3">
                        <a href="{{ route('orders.preview', $order['id']) }}" target="_blank" rel="noreferrer" class="btn btn-soft">
                            <i class="bi bi-printer me-2"></i>Gerar impressão consolidada (A4)
                        </a>
                    </div>
                </div>

                {{-- Aba: Valores --}}
                <div class="equipment-tab-panel" data-os-panel="valores">
                    @php
                        $financeiroResumo = is_array($order['financeiro_resumo'] ?? null) ? $order['financeiro_resumo'] : [];
                        $formaPagamentoResolvida = trim((string) ($order['forma_pagamento_resolvida'] ?? ''));
                        $formaPagamentoLegada = trim((string) ($order['forma_pagamento'] ?? ''));
                        $formaPagamentoExibicao = $formaPagamentoResolvida !== '' ? $formaPagamentoResolvida : $formaPagamentoLegada;
                        $custoAuditoria = is_array($order['custo_auditoria'] ?? null) ? $order['custo_auditoria'] : [];
                        $formatMoney = static fn ($value): string => 'R$ ' . number_format((float) ($value ?? 0), 2, ',', '.');
                    @endphp
                    <div class="desktop-grid desktop-grid-two">
                        <div>
                            <h3 class="os-panel-title"><i class="bi bi-cash-stack me-1"></i>Resumo financeiro</h3>
                            <div class="detail-list">
                                <div class="detail-item"><strong>Mão de obra</strong><span>{{ ($order['valor_mao_obra'] ?? '') !== '' ? 'R$ ' . $order['valor_mao_obra'] : 'R$ 0,00' }}</span></div>
                                <div class="detail-item"><strong>Peças</strong><span>{{ ($order['valor_pecas'] ?? '') !== '' ? 'R$ ' . $order['valor_pecas'] : 'R$ 0,00' }}</span></div>
                                <div class="detail-item"><strong>Total</strong><span>{{ ($order['valor_total'] ?? '') !== '' ? 'R$ ' . $order['valor_total'] : 'R$ 0,00' }}</span></div>
                                <div class="detail-item"><strong>Desconto</strong><span>{{ ($order['desconto'] ?? '') !== '' ? 'R$ ' . $order['desconto'] : 'R$ 0,00' }}</span></div>
                                <div class="detail-item"><strong>Valor final</strong><span>{{ ($order['valor_final'] ?? '') !== '' ? 'R$ ' . $order['valor_final'] : 'Não calculado' }}</span></div>
                                <div class="detail-item"><strong>Forma de pagamento</strong><span>{{ $formaPagamentoExibicao !== '' ? $formaPagamentoExibicao : 'Não informada' }}</span></div>
                            </div>

                            @if(($financeiroResumo['titulo_id'] ?? null) !== null)
                                <div class="text-muted small mt-2">
                                    Título financeiro #{{ $financeiroResumo['titulo_id'] }} ·
                                    recebido {{ $formatMoney($financeiroResumo['valor_recebido'] ?? 0) }} de {{ $formatMoney($financeiroResumo['valor_titulo'] ?? 0) }} ·
                                    saldo {{ $formatMoney($financeiroResumo['saldo_aberto'] ?? 0) }}
                                </div>
                            @endif

                            @if(($custoAuditoria['orcamento_id'] ?? null) !== null && (int) ($custoAuditoria['pecas_orcadas'] ?? 0) > 0)
                                <div class="alert {{ ($custoAuditoria['pendencia_baixa_estoque'] ?? false) ? 'alert-warning' : 'alert-light' }} border mt-3 mb-0">
                                    <strong>Auditoria de peças</strong>
                                    <div class="small mt-1">
                                        Orçamento {{ $custoAuditoria['orcamento_numero'] ?? ('#' . $custoAuditoria['orcamento_id']) }} ·
                                        peças orçadas {{ $formatMoney($custoAuditoria['valor_pecas_orcado'] ?? 0) }} ·
                                        custo previsto {{ $formatMoney($custoAuditoria['custo_pecas_previsto'] ?? 0) }} ·
                                        custo real em estoque {{ $formatMoney($custoAuditoria['custo_pecas_real'] ?? 0) }}
                                    </div>
                                    @if(($custoAuditoria['pendencia_baixa_estoque'] ?? false) && ($custoAuditoria['mensagem'] ?? '') !== '')
                                        <div class="small mt-2">{{ $custoAuditoria['mensagem'] }}</div>
                                    @endif
                                </div>
                            @endif
                        </div>
                        <div>
                            <h3 class="os-panel-title"><i class="bi bi-calendar-event me-1"></i>Datas e garantia</h3>
                            <div class="detail-list">
                                <div class="detail-item"><strong>Abertura</strong><span>{{ ($order['data_abertura'] ?? '') !== '' ? $order['data_abertura'] : 'Não informada' }}</span></div>
                                <div class="detail-item"><strong>Entrada</strong><span>{{ ($order['data_entrada'] ?? '') !== '' ? $order['data_entrada'] : 'Não informada' }}</span></div>
                                <div class="detail-item"><strong>Previsão</strong><span>{{ ($order['data_previsao'] ?? '') !== '' ? $order['data_previsao'] : 'Não informada' }}</span></div>
                                <div class="detail-item"><strong>Conclusão</strong><span>{{ ($order['data_conclusao'] ?? '') !== '' ? $order['data_conclusao'] : 'Não informada' }}</span></div>
                                <div class="detail-item"><strong>Garantia</strong><span>{{ ($order['garantia_dias'] ?? 0) > 0 ? $order['garantia_dias'] . ' dias' : 'Não definida' }}</span></div>
                            </div>
                        </div>
                    </div>
                </div>
            </article>
        </div>
    </div>
@endsection

@push('modals')
    @include('orders._status_modal')
    @include('orders._cancel_closure_modal')
    @include('layouts.partials.photo-viewer-modal')
@endpush

@section('scripts')
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
    <script>
        (function () {
            const root = document.querySelector('[data-os-tabs]');
            if (!root) {
                return;
            }
            const tabs = Array.from(root.querySelectorAll('[data-os-tab]'));
            const panels = Array.from(root.querySelectorAll('[data-os-panel]'));

            function activate(name) {
                tabs.forEach((tab) => {
                    const active = tab.dataset.osTab === name;
                    tab.classList.toggle('is-active', active);
                    tab.setAttribute('aria-pressed', active ? 'true' : 'false');
                });
                panels.forEach((panel) => {
                    panel.classList.toggle('is-active', panel.dataset.osPanel === name);
                });
                if (history.replaceState) {
                    history.replaceState(null, '', '#tab-' + name);
                }
            }

            tabs.forEach((tab) => {
                tab.addEventListener('click', () => activate(tab.dataset.osTab));
            });

            const initial = (window.location.hash || '').replace('#tab-', '');
            if (initial && tabs.some((tab) => tab.dataset.osTab === initial)) {
                activate(initial);
            }

            // Mantém a aba ativa visível na faixa rolável ao trocar (sem rolar a página).
            tabs.forEach((tab) => tab.addEventListener('click', () => {
                tab.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
            }));
        })();

        // Filtro por categoria da timeline de eventos da OS (client-side,
        // mesmo padrão dos toggles [data-os-tab] acima — sem reload).
        (function () {
            const root = document.querySelector('[data-event-timeline]');
            if (!root) {
                return;
            }
            const chips = Array.from(root.querySelectorAll('[data-event-filter]'));
            const items = Array.from(root.querySelectorAll('[data-event-category]'));
            const emptyState = root.querySelector('[data-event-empty]');
            const list = root.querySelector('[data-event-list]');

            chips.forEach((chip) => chip.addEventListener('click', () => {
                const filter = chip.dataset.eventFilter;
                chips.forEach((c) => c.classList.toggle('is-active', c === chip));

                let visible = 0;
                items.forEach((item) => {
                    const show = filter === 'all' || item.dataset.eventCategory === filter;
                    item.classList.toggle('d-none', !show);
                    if (show) visible++;
                });

                if (emptyState) emptyState.classList.toggle('d-none', visible > 0);
                if (list) list.classList.toggle('d-none', visible === 0);
            }));
        })();
    </script>
@endsection
