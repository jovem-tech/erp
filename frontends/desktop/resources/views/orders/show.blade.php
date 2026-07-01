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

        // Catálogo completo (para o histórico/progresso) e próximas etapas reais (transições válidas).
        $statusOptions = $order['status_disponiveis'] ?? [];
        $nextSteps = $order['proximas_etapas'] ?? [];
        $currentCode = $order['status'] ?? '';
        $currentOrdem = null;
        foreach ($statusOptions as $option) {
            if (($option['codigo'] ?? '') === $currentCode) {
                $currentOrdem = (int) ($option['ordem_fluxo'] ?? 0);
            }
        }

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

        $orcamento = $order['orcamento'] ?? null;
        $hasOrcamento = $orcamento !== null;
        $checklist = $order['checklist'] ?? null;

        // Trilha de progresso enxuta: concluídas (limitadas) + etapa atual + próximas reais.
        $concluded = [];
        foreach ($statusOptions as $option) {
            if ($currentOrdem !== null && (int) ($option['ordem_fluxo'] ?? 0) < $currentOrdem) {
                $concluded[] = $option;
            }
        }
        $hiddenConcluded = max(0, count($concluded) - 3);
        $concluded = array_slice($concluded, -3);

        $progressSteps = [];
        foreach ($concluded as $option) {
            $progressSteps[] = ['opt' => $option, 'state' => 'concluido'];
        }
        if ($currentOption !== null) {
            $progressSteps[] = ['opt' => $currentOption, 'state' => 'atual'];
        }
        foreach ($nextSteps as $option) {
            $progressSteps[] = ['opt' => $option, 'state' => 'proximo'];
        }
    @endphp

    {{-- Cabeçalho + ações principais --}}
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4 os-detail-header">
        <div>
            <p class="desktop-eyebrow">Ordem de serviço</p>
            <h2 class="surface-title fs-3 mb-2">{{ ($order['numero_os'] ?? '') !== '' ? $order['numero_os'] : '#' . ($order['id'] ?? 0) }}</h2>
            <div class="d-flex flex-wrap gap-2">
                @include('layouts.partials.status-pill', [
                    'label' => ($order['status_nome'] ?? '') !== '' ? $order['status_nome'] : 'Sem status',
                    'color' => $order['status_cor'] ?? '#64748b',
                ])
                <span class="desktop-chip">{{ ($order['estado_fluxo'] ?? '') !== '' ? ucfirst(str_replace('_', ' ', $order['estado_fluxo'])) : 'Fluxo não definido' }}</span>
                <span class="desktop-chip">{{ ($order['prioridade'] ?? '') !== '' ? ucfirst(str_replace('_', ' ', $order['prioridade'])) : 'Prioridade não definida' }}</span>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 align-items-start os-header-actions">
            <a href="{{ route('orders.index') }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>Voltar
            </a>
            @if (\App\Support\DesktopSession::can('os', 'editar'))
                <a href="{{ route('orders.edit', $order['id']) }}" class="btn btn-soft">
                    <i class="bi bi-pencil me-2"></i>Editar
                </a>
            @endif
            @if (! $hasOrcamento && \App\Support\DesktopSession::can('orcamentos', 'criar'))
                <a href="{{ route('orcamentos.create', ['os_id' => $order['id']]) }}" class="btn btn-soft">
                    <i class="bi bi-receipt me-2"></i>Gerar orçamento
                </a>
            @endif
            <a href="{{ route('orders.preview', $order['id']) }}" target="_blank" rel="noreferrer" class="btn btn-primary">
                <i class="bi bi-printer me-2"></i>Imprimir
            </a>
        </div>
    </div>

    <div class="os-detail-layout">
        {{-- Coluna lateral: foto do equipamento + progresso do fluxo --}}
        <aside class="os-detail-aside">
            <article class="surface-card os-photo-card">
                <span class="summary-card-eyebrow"><i class="bi bi-image me-1"></i>Fotos do equipamento</span>
                <div class="os-photo-frame">
                    @if ($equipmentPhoto && ($equipmentPhoto['id'] ?? 0) > 0)
                        <img src="{{ route('equipments.photos.show', [$equipmentPhoto['equipamento_id'], $equipmentPhoto['id']]) }}" alt="Foto do equipamento">
                    @else
                        <div class="os-photo-empty">
                            <i class="bi bi-camera"></i>
                            <span>Sem foto</span>
                        </div>
                    @endif
                </div>
                <div class="os-photo-serial">
                    <i class="bi bi-upc-scan"></i>
                    SN: {{ ($order['equipamento_numero_serie'] ?? '') !== '' ? $order['equipamento_numero_serie'] : '—' }}
                </div>
            </article>

            <details class="surface-card os-progress-card" data-os-progress open>
                <summary class="os-progress-summary">
                    <div>
                        <h2 class="surface-title fs-6"><i class="bi bi-diagram-3 me-1"></i>Histórico e Progresso</h2>
                        <p class="surface-subtitle mb-0">
                            Etapa atual:
                            <strong>{{ ($order['status_nome'] ?? '') !== '' ? $order['status_nome'] : 'Sem status' }}</strong>
                        </p>
                    </div>
                    <i class="bi bi-chevron-down os-progress-chevron"></i>
                </summary>

                @if ($progressSteps !== [])
                    <ol class="os-progress">
                        @if ($hiddenConcluded > 0)
                            <li class="os-progress-more">+{{ $hiddenConcluded }} etapa(s) anterior(es)</li>
                        @endif
                        @foreach ($progressSteps as $step)
                            <li class="os-progress-step is-{{ $step['state'] }}">
                                <span class="os-progress-dot"></span>
                                <div>
                                    <strong>{{ $step['opt']['nome'] ?? $step['opt']['codigo'] ?? 'Etapa' }}</strong>
                                    <small>
                                        @if ($step['state'] === 'atual') Etapa atual
                                        @elseif ($step['state'] === 'concluido') Concluída
                                        @else Próxima provável
                                        @endif
                                    </small>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @else
                    <p class="surface-subtitle mb-0">Nenhuma etapa de fluxo cadastrada no catálogo de status.</p>
                @endif
            </details>
        </aside>

        {{-- Painel principal: resumo + abas --}}
        <div class="os-detail-main">
            <section class="desktop-grid desktop-grid-three mb-4 os-summary-section">
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
                    <div class="summary-card-value">{{ ($order['equipamento_resumo_tecnico'] ?? '') !== '' ? $order['equipamento_resumo_tecnico'] : 'Sem resumo técnico' }}</div>
                    <div class="summary-card-meta">
                        {{ ($order['equipamento_numero_serie'] ?? '') !== '' ? 'S/N ' . $order['equipamento_numero_serie'] : 'Série não informada' }}
                    </div>
                </article>

                <article class="summary-card">
                    <span class="summary-card-eyebrow">Técnico responsável</span>
                    <div class="summary-card-value">{{ $technician['nome'] ?? 'Não atribuído' }}</div>
                    <div class="summary-card-meta">
                        {{ $technician['email'] ?? ($technician['perfil'] ?? 'Sem perfil informado') }}
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

                    @if (\App\Support\DesktopSession::can('os', 'editar'))
                        <div class="os-panel-block">
                            <h3 class="os-panel-title"><i class="bi bi-arrow-repeat me-1"></i>Atualizar status</h3>
                            <p class="surface-subtitle">Ação enviada ao backend central com validação RBAC e catálogo de status.</p>
                            <form method="post" action="{{ route('orders.status.update', $order['id']) }}" class="d-grid gap-3">
                                @csrf
                                <div>
                                    <label for="status">Novo status</label>
                                    <select name="status" id="status" class="form-select" required>
                                        @foreach ($selectableStatuses as $status)
                                            <option value="{{ $status['codigo'] }}" @selected(($order['status'] ?? '') === $status['codigo'])>
                                                {{ $status['nome'] }}{{ ($order['status'] ?? '') === $status['codigo'] ? ' (atual)' : '' }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @if (count($selectableStatuses) <= 1)
                                        <small class="os-hint">Não há transições de status disponíveis a partir da etapa atual.</small>
                                    @endif
                                </div>
                                <div>
                                    <label for="observacao">Observação da mudança</label>
                                    <textarea name="observacao" id="observacao" class="form-control" rows="3" placeholder="Registre o motivo ou contexto da alteração">{{ old('observacao') }}</textarea>
                                </div>
                                <div class="d-flex justify-content-end">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check2-circle me-2"></i>Salvar novo status
                                    </button>
                                </div>
                            </form>
                        </div>
                    @endif

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
                                            <a href="{{ route('orders.photos.show', [$order['id'], $photo['id']]) }}" target="_blank" rel="noreferrer" class="os-photo-thumb" title="{{ $photo['tipo_label'] ?? 'Foto' }}">
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
                    <div class="desktop-grid desktop-grid-two">
                        <div>
                            <h3 class="os-panel-title"><i class="bi bi-cash-stack me-1"></i>Resumo financeiro</h3>
                            <div class="detail-list">
                                <div class="detail-item"><strong>Mão de obra</strong><span>{{ ($order['valor_mao_obra'] ?? '') !== '' ? 'R$ ' . $order['valor_mao_obra'] : 'R$ 0,00' }}</span></div>
                                <div class="detail-item"><strong>Peças</strong><span>{{ ($order['valor_pecas'] ?? '') !== '' ? 'R$ ' . $order['valor_pecas'] : 'R$ 0,00' }}</span></div>
                                <div class="detail-item"><strong>Total</strong><span>{{ ($order['valor_total'] ?? '') !== '' ? 'R$ ' . $order['valor_total'] : 'R$ 0,00' }}</span></div>
                                <div class="detail-item"><strong>Desconto</strong><span>{{ ($order['desconto'] ?? '') !== '' ? 'R$ ' . $order['desconto'] : 'R$ 0,00' }}</span></div>
                                <div class="detail-item"><strong>Valor final</strong><span>{{ ($order['valor_final'] ?? '') !== '' ? 'R$ ' . $order['valor_final'] : 'Não calculado' }}</span></div>
                                <div class="detail-item"><strong>Forma de pagamento</strong><span>{{ ($order['forma_pagamento'] ?? '') !== '' ? $order['forma_pagamento'] : 'Não informada' }}</span></div>
                            </div>
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

            {{-- Histórico real de movimentações --}}
            <section class="surface-card mt-4 os-history-section">
                <div class="surface-card-header">
                    <div>
                        <h2 class="surface-title fs-6"><i class="bi bi-clock-history me-1"></i>Histórico recente da OS</h2>
                        <p class="surface-subtitle">Movimentos mais recentes retornados pelo backend com autor e observação.</p>
                    </div>
                </div>

                @if (($order['historico'] ?? []) !== [])
                    <div class="timeline">
                        @foreach ($order['historico'] as $history)
                            <article class="timeline-item">
                                <div class="d-flex flex-wrap justify-content-between gap-2">
                                    <strong>{{ ($history['status_anterior'] ?? '') !== '' ? $history['status_anterior'] : 'Sem origem' }} → {{ ($history['status_novo'] ?? '') !== '' ? $history['status_novo'] : 'Sem destino' }}</strong>
                                    <small>{{ ($history['created_at'] ?? '') !== '' ? $history['created_at'] : 'Data não informada' }}</small>
                                </div>
                                <p class="mb-2 mt-2">{{ ($history['observacao'] ?? '') !== '' ? $history['observacao'] : 'Sem observação registrada.' }}</p>
                                <small>Responsável: {{ $history['usuario']['nome'] ?? 'Usuário não identificado' }}</small>
                            </article>
                        @endforeach
                    </div>
                @else
                    @include('layouts.partials.empty-state', [
                        'icon' => 'bi-clock-history',
                        'title' => 'Sem histórico registrado',
                        'message' => 'Nenhuma movimentação recente foi retornada para esta ordem de serviço.',
                    ])
                @endif
            </section>
        </div>
    </div>
@endsection

@section('scripts')
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

        // Progresso: aberto no desktop, recolhido no mobile (o usuário pode expandir).
        (function () {
            const progress = document.querySelector('[data-os-progress]');
            if (!progress) {
                return;
            }
            const mq = window.matchMedia('(max-width: 992px)');
            const sync = (event) => {
                progress.open = !event.matches;
            };
            sync(mq);
            mq.addEventListener('change', sync);
        })();
    </script>
@endsection
