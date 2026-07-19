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
        $canCreateOrder = \App\Support\DesktopSession::can('os', 'criar');
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
                // diffInDays() retorna float fracionário nesta versão do Carbon
                // (ex.: 4.170775462963) — sem o cast, o rótulo exibia o valor cru
                // e as comparações ===0/===1 abaixo nunca batiam para dias
                // fracionários. (int) trunca para o número de dias completos.
                $dias = (int) $aberturaCarbon->diffInDays($fimCarbon);

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
            <a href="{{ route('orders.map', $order['id']) }}" class="btn btn-outline-light">
                <i class="bi bi-map me-1"></i>Mapa da OS
            </a>

            <div class="dropdown os-actions-dropdown">
                <button type="button"
                    class="btn btn-outline-light dropdown-toggle os-actions-toggle"
                    data-bs-toggle="dropdown"
                    aria-expanded="false">
                    Mais ações
                </button>

                <div class="dropdown-menu dropdown-menu-end os-actions-menu">
                    @if ($canCreateOrder && $newOrderClientUrl !== null)
                        @if ($newOrderSameEquipmentUrl !== null)
                            <button type="button"
                                class="dropdown-item"
                                data-bs-toggle="modal"
                                data-bs-target="#newOrderFromOrderModal"
                                data-new-order-context-trigger>
                                <i class="bi bi-plus-circle me-2"></i>Nova OS
                            </button>
                        @else
                            <a href="{{ $newOrderClientUrl }}" class="dropdown-item" data-new-order-action="order-client">
                                <i class="bi bi-plus-circle me-2"></i>Nova OS
                            </a>
                        @endif
                    @endif

                    <a href="{{ route('orders.audit', $order['id']) }}" class="dropdown-item">
                        <i class="bi bi-shield-check me-2"></i>Auditoria completa
                    </a>

                    <a href="{{ route('orders.map', $order['id']) }}" class="dropdown-item">
                        <i class="bi bi-map me-2"></i>Mapa da OS
                    </a>

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
        {{-- Resumo superior: foto, cliente e equipamento na mesma linha/altura --}}
        <div class="os-detail-top-grid" data-os-top-grid>
            <article class="surface-card os-photo-card" data-os-summary-card="photo">
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

            <article class="surface-card" data-os-summary-card="client">
                        <h3 class="os-info-card-title">
                            <span><i class="bi bi-person me-1"></i>Cliente</span>
                            @if (($client['id'] ?? 0) > 0 && \App\Support\DesktopSession::can('clientes', 'visualizar'))
                                <a href="{{ route('clients.show', $client['id']) }}" class="btn btn-soft btn-sm">Ver cliente</a>
                            @endif
                        </h3>
                        @php
                            $enderecoPartes = array_filter([
                                trim(($client['endereco'] ?? '') . (($client['numero'] ?? '') !== '' ? ', ' . $client['numero'] : '')),
                                $client['complemento'] ?? '',
                                $client['bairro'] ?? '',
                                trim(($client['cidade'] ?? '') . (($client['uf'] ?? '') !== '' ? '/' . $client['uf'] : '')),
                                $client['cep'] ?? '',
                            ], fn ($p) => trim((string) $p) !== '');
                            $contato = trim(($client['nome_contato'] ?? '') . (($client['telefone_contato'] ?? '') !== '' ? ' · ' . $client['telefone_contato'] : ''));
                            $clienteRows = array_filter([
                                'Nome' => $client['nome_razao'] ?? ($order['cliente_nome'] ?? ''),
                                'Telefone' => $client['telefone1'] ?? '',
                                'Telefone 2' => $client['telefone2'] ?? '',
                                'Contato' => $contato,
                                'E-mail' => $client['email'] ?? '',
                                'CPF/CNPJ' => $client['cpf_cnpj'] ?? '',
                                'RG/IE' => $client['rg_ie'] ?? '',
                                'Endereço' => implode(' · ', $enderecoPartes),
                                'Observações' => $client['observacoes'] ?? '',
                            ], fn ($v) => trim((string) $v) !== '');
                        @endphp
                        <table class="os-info-table">
                            <tbody>
                            @forelse ($clienteRows as $label => $value)
                                <tr><th>{{ $label }}</th><td>{{ $value }}</td></tr>
                            @empty
                                <tr><td class="os-info-table-empty">Nenhum dado de cliente disponível.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                </article>
            <article class="surface-card" data-os-summary-card="equipment">
                        <h3 class="os-info-card-title">
                            <span><i class="bi bi-laptop me-1"></i>Equipamento</span>
                            @if (($equipment['id'] ?? 0) > 0 && \App\Support\DesktopSession::can('equipamentos', 'visualizar'))
                                <a href="{{ route('equipments.show', $equipment['id']) }}" class="btn btn-soft btn-sm">Ver equipamento</a>
                            @endif
                        </h3>
                        @php
                            $equipamentoRows = array_filter([
                                'Tipo' => $equipment['tipo_nome'] ?? ($order['equipamento_tipo_nome'] ?? ''),
                                'Marca' => $equipment['marca_nome'] ?? '',
                                'Modelo' => $equipment['modelo_nome'] ?? '',
                                'Cor' => $equipment['cor'] ?? '',
                                'N° de série' => $order['equipamento_numero_serie'] ?? ($equipment['numero_serie'] ?? ''),
                                'IMEI' => $equipment['imei'] ?? '',
                                'Observações' => $equipment['observacoes'] ?? '',
                            ], fn ($v) => trim((string) $v) !== '');
                        @endphp
                        <table class="os-info-table">
                            <tbody>
                            @forelse ($equipamentoRows as $label => $value)
                                <tr><th>{{ $label }}</th><td>{{ $value }}</td></tr>
                            @empty
                                <tr><td class="os-info-table-empty">Nenhum dado de equipamento disponível.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
            </article>
        </div>

        {{-- Cards operacionais em coluna única e largura total --}}
        <div class="os-detail-main" data-os-full-width-cards>

            {{-- Card: Defeito e Solução --}}
            <article class="surface-card mb-4">
                <h3 class="os-info-card-title"><span><i class="bi bi-clipboard2-pulse me-1"></i>Defeito e Solução</span></h3>
                @php
                    $tecnicoMeta = $technician['email'] ?? ($technician['perfil'] ?? '');
                    $tecnicoValor = trim(($technician['nome'] ?? '') . (($tecnicoMeta ?? '') !== '' ? ' · ' . $tecnicoMeta : ''));
                    $checklistResumo = '';
                    if ($checklist) {
                        $checklistResumo = ucfirst(str_replace('_', ' ', $checklist['status'] ?? 'rascunho'))
                            . ' · ' . ($checklist['total_itens'] ?? 0) . ' itens';
                        if (($checklist['total_discrepancias'] ?? 0) > 0) {
                            $checklistResumo .= ' · ' . $checklist['total_discrepancias'] . ' discrepância(s)';
                        }
                    }
                    $defeitoRows = array_filter([
                        'Técnico responsável' => $tecnicoValor,
                        'Defeito relatado pelo cliente' => $order['relato_cliente'] ?? '',
                        'Diagnóstico técnico' => $order['diagnostico_tecnico'] ?? '',
                        'Solução aplicada' => $order['solucao_aplicada'] ?? '',
                        'Procedimentos executados' => $order['procedimentos_executados'] ?? '',
                        'Acessórios' => $order['acessorios'] ?? '',
                        'Observações internas' => $order['observacoes_internas'] ?? '',
                        'Observações do cliente' => $order['observacoes_cliente'] ?? '',
                    ], fn ($v) => trim((string) $v) !== '');
                @endphp
                <table class="os-info-table">
                    <tbody>
                    @if ($checklist)
                        <tr>
                            <th>Checklist</th>
                            <td>
                                {{ $checklistResumo }}
                                <button type="button" class="btn btn-soft btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#checklistDetailModal">
                                    <i class="bi bi-eye me-1"></i>Ver checklist
                                </button>
                            </td>
                        </tr>
                    @endif
                    @forelse ($defeitoRows as $label => $value)
                        <tr><th>{{ $label }}</th><td>{{ $value }}</td></tr>
                    @empty
                        @if (! $checklist)
                            <tr><td class="os-info-table-empty">Nenhum dado de diagnóstico registrado ainda.</td></tr>
                        @endif
                    @endforelse
                    </tbody>
                </table>
            </article>

            {{-- Card: Valores e Orçamento --}}
            <article class="surface-card mb-4">
                @php
                    $financeiroResumo = is_array($order['financeiro_resumo'] ?? null) ? $order['financeiro_resumo'] : [];
                    $formaPagamentoResolvida = trim((string) ($order['forma_pagamento_resolvida'] ?? ''));
                    $formaPagamentoLegada = trim((string) ($order['forma_pagamento'] ?? ''));
                    $formaPagamentoExibicao = $formaPagamentoResolvida !== '' ? $formaPagamentoResolvida : $formaPagamentoLegada;
                    $custoAuditoria = is_array($order['custo_auditoria'] ?? null) ? $order['custo_auditoria'] : [];
                    $formatMoney = static fn ($value): string => 'R$ ' . number_format((float) ($value ?? 0), 2, ',', '.');

                    $datasRows = array_filter([
                        'Abertura' => $order['data_abertura'] ?? '',
                        'Entrada' => $order['data_entrada'] ?? '',
                        'Previsão' => $order['data_previsao'] ?? '',
                        'Conclusão' => $order['data_conclusao'] ?? '',
                        'Garantia' => ($order['garantia_dias'] ?? 0) > 0 ? $order['garantia_dias'] . ' dias' : '',
                        'Forma de pagamento' => $formaPagamentoExibicao,
                    ], fn ($v) => trim((string) $v) !== '');

                    $orcamentoRows = $hasOrcamento ? array_filter([
                        'Status' => $orcamento['status_label'] ?? '',
                        'Subtotal' => ($orcamento['subtotal'] ?? null) !== null ? 'R$ ' . $orcamento['subtotal'] : '',
                        'Desconto' => ($orcamento['desconto'] ?? null) !== null ? 'R$ ' . $orcamento['desconto'] : '',
                        'Total' => ($orcamento['total'] ?? null) !== null ? 'R$ ' . $orcamento['total'] : '',
                        'Validade' => $orcamento['validade_data'] ?? '',
                        'Enviado em' => $orcamento['enviado_em'] ?? '',
                        'Aprovado em' => ($orcamento['aprovado_em'] ?? '') !== '' ? $orcamento['aprovado_em'] : 'Não aprovado',
                    ], fn ($v) => trim((string) $v) !== '') : [];

                    $orcamentoItens = $hasOrcamento ? ($orcamento['itens'] ?? []) : [];
                @endphp

                <h3 class="os-info-card-title"><span><i class="bi bi-cash-coin me-1"></i>Valores e Orçamento</span></h3>

                <div class="desktop-grid desktop-grid-two">
                    <div class="os-subcard">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                            <h4 class="os-panel-title mb-0">
                                Orçamento
                                @if ($hasOrcamento)
                                    {{ ($orcamento['numero'] ?? '') !== '' ? $orcamento['numero'] : '#' . ($orcamento['id'] ?? 0) }}
                                    <span class="os-count">v{{ $orcamento['versao'] ?? 1 }}</span>
                                @endif
                            </h4>
                            @if ($hasOrcamento && \App\Support\DesktopSession::can('orcamentos', 'visualizar'))
                                <a href="{{ route('orcamentos.show', $orcamento['id']) }}" class="btn btn-soft btn-sm">
                                    <i class="bi bi-box-arrow-up-right me-2"></i>Abrir orçamento
                                </a>
                            @elseif (! $hasOrcamento && $canCreateBudget)
                                <a href="{{ route('orcamentos.create', ['os_id' => $order['id']]) }}" class="btn btn-soft btn-sm">
                                    <i class="bi bi-plus-lg me-2"></i>Gerar orçamento
                                </a>
                            @endif
                        </div>

                        @if ($hasOrcamento)
                            <table class="os-info-table">
                                <tbody>
                                @foreach ($orcamentoRows as $label => $value)
                                    <tr><th>{{ $label }}</th><td>{{ $value }}</td></tr>
                                @endforeach
                                </tbody>
                            </table>
                        @else
                            <p class="os-info-table-empty mb-0">Esta OS ainda não possui um orçamento vinculado.</p>
                        @endif

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
                    <div class="os-subcard">
                        <h4 class="os-panel-title">Datas e garantia</h4>
                        <table class="os-info-table">
                            <tbody>
                            @forelse ($datasRows as $label => $value)
                                <tr><th>{{ $label }}</th><td>{{ $value }}</td></tr>
                            @empty
                                <tr><td class="os-info-table-empty">Sem datas registradas.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @if ($hasOrcamento && $orcamentoItens !== [])
                    <div class="os-panel-block">
                        <h4 class="os-panel-title">Peças e serviços do orçamento</h4>
                        <div class="table-responsive">
                            <table class="table table-stack align-middle">
                                <thead>
                                <tr>
                                    <th>Tipo</th>
                                    <th>Descrição</th>
                                    <th>Qtd</th>
                                    <th>Valor unit.</th>
                                    <th>Total</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($orcamentoItens as $item)
                                    <tr>
                                        <td data-label="Tipo">{{ ucfirst((string) ($item['tipo_item'] ?? 'servico')) }}</td>
                                        <td data-label="Descrição">{{ ($item['descricao'] ?? '') !== '' ? $item['descricao'] : 'Sem descrição' }}</td>
                                        <td data-label="Qtd">{{ number_format((float) ($item['quantidade'] ?? 0), 2, ',', '.') }}</td>
                                        <td data-label="Valor unit.">R$ {{ number_format((float) ($item['valor_unitario'] ?? 0), 2, ',', '.') }}</td>
                                        <td data-label="Total" class="fw-bold">R$ {{ number_format((float) ($item['total'] ?? 0), 2, ',', '.') }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </article>

            {{-- Card: Documentos --}}
            <article class="surface-card mb-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <h3 class="os-info-card-title mb-0"><span><i class="bi bi-file-earmark-text me-1"></i>Documentos</span></h3>
                    <a href="{{ route('orders.documents.center', $order['id']) }}" class="btn btn-soft btn-sm">
                        <i class="bi bi-folder-symlink me-2"></i>Abrir central de documentos
                    </a>
                </div>

                @if ($documents !== [])
                    <div class="table-responsive">
                        <table class="table table-stack align-middle">
                            <thead>
                            <tr>
                                <th>Tipo</th>
                                <th>Arquivo</th>
                                <th>Versão</th>
                                <th>Gerado em</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($documents as $document)
                                <tr>
                                    <td data-label="Tipo">{{ ($document['tipo_label'] ?? '') !== '' ? $document['tipo_label'] : 'Documento' }}</td>
                                    <td data-label="Arquivo">{{ ($document['nome_arquivo'] ?? '') !== '' ? $document['nome_arquivo'] : 'Arquivo sem nome' }}</td>
                                    <td data-label="Versão">v{{ $document['versao'] ?? 1 }}</td>
                                    <td data-label="Gerado em">{{ ($document['created_at'] ?? '') !== '' ? $document['created_at'] : '—' }}</td>
                                    <td data-label="">
                                        <a href="{{ route('orders.documents.show', [$order['id'], $document['id']]) }}" target="_blank" rel="noreferrer" class="btn btn-outline-light btn-sm">
                                            <i class="bi bi-eye me-1"></i>Visualizar
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    @include('layouts.partials.empty-state', [
                        'icon' => 'bi-file-earmark-x',
                        'title' => 'Sem documentos vinculados',
                        'message' => 'Os PDFs desta OS aparecerão aqui assim que existirem no repositório integrado.',
                    ])
                @endif
            </article>

            {{-- Seção final: Fotos --}}
            <article class="surface-card">
                <h3 class="os-info-card-title mb-3"><span><i class="bi bi-images me-1"></i>Fotos</span></h3>
                @if ($photos !== [])
                    @foreach (['Recepção' => $photosRecepcao, 'Diagnóstico' => $photosDiagnostico, 'Entrega' => $photosEntrega] as $groupLabel => $groupPhotos)
                        @if ($groupPhotos !== [])
                            <div class="os-panel-block">
                                <h4 class="os-panel-title">{{ $groupLabel }} <span class="os-count">{{ count($groupPhotos) }}</span></h4>
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
            </article>

            {{-- Histórico unificado e categorizado de movimentações (os_eventos) --}}
            @include('orders._event_timeline')
        </div>
    </div>
@endsection

@push('modals')
    @if ($canCreateOrder && $newOrderClientUrl !== null && $newOrderSameEquipmentUrl !== null)
        @include('orders._new_order_context_modal')
    @endif
    @include('orders._status_modal')
    @include('orders._cancel_closure_modal')
    @include('layouts.partials.photo-viewer-modal')
    @if ($checklist)
        @include('orders._checklist_detail_modal')
    @endif
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
        // Filtro por categoria da timeline de eventos da OS (client-side, sem reload).
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
