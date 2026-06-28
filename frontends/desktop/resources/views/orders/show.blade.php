@extends('layouts.app')

@section('content')
    @php
        $client = $order['cliente'] ?? null;
        $equipment = $order['equipamento'] ?? null;
        $technician = $order['tecnico'] ?? null;
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Ordem de serviço</p>
            <h2 class="surface-title fs-3 mb-2">{{ $order['numero_os'] !== '' ? $order['numero_os'] : '#' . ($order['id'] ?? 0) }}</h2>
            <div class="d-flex flex-wrap gap-2">
                @include('layouts.partials.status-pill', [
                    'label' => $order['status_nome'] !== '' ? $order['status_nome'] : 'Sem status',
                    'color' => $order['status_cor'] ?? '#64748b',
                ])
                <span class="desktop-chip">{{ $order['estado_fluxo'] !== '' ? ucfirst(str_replace('_', ' ', $order['estado_fluxo'])) : 'Fluxo não definido' }}</span>
                <span class="desktop-chip">{{ $order['prioridade'] !== '' ? ucfirst(str_replace('_', ' ', $order['prioridade'])) : 'Prioridade não definida' }}</span>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('orders.index') }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>
                Voltar
            </a>
            @if ($client && \App\Support\DesktopSession::can('clientes', 'visualizar'))
                <a href="{{ route('clients.show', $client['id']) }}" class="btn btn-soft">Abrir cliente</a>
            @endif
            @if ($equipment && \App\Support\DesktopSession::can('equipamentos', 'visualizar'))
                <a href="{{ route('equipments.show', $equipment['id']) }}" class="btn btn-soft">Abrir equipamento</a>
            @endif
        </div>
    </div>

    <section class="desktop-grid desktop-grid-three mb-4">
        <article class="summary-card">
            <span class="summary-card-eyebrow">Cliente</span>
            <div class="summary-card-value">{{ $order['cliente_nome'] !== '' ? $order['cliente_nome'] : 'Não informado' }}</div>
            <div class="summary-card-meta">
                {{ $client['telefone1'] ?? 'Telefone não informado' }}
                @if (($client['email'] ?? '') !== '')
                    · {{ $client['email'] }}
                @endif
            </div>
        </article>

        <article class="summary-card">
            <span class="summary-card-eyebrow">Equipamento</span>
            <div class="summary-card-value">{{ $order['equipamento_resumo_tecnico'] !== '' ? $order['equipamento_resumo_tecnico'] : 'Sem resumo técnico' }}</div>
            <div class="summary-card-meta">
                {{ $order['equipamento_numero_serie'] !== '' ? 'S/N ' . $order['equipamento_numero_serie'] : 'Série não informada' }}
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

    <section class="desktop-grid desktop-grid-two mb-4">
        @if (\App\Support\DesktopSession::can('os', 'editar'))
            <article class="desktop-form-card">
                <div class="surface-card-header">
                    <div>
                        <h2 class="surface-title">Atualizar status</h2>
                        <p class="surface-subtitle">Ação enviada ao backend central com validação RBAC e catálogo de status.</p>
                    </div>
                </div>

                <form method="post" action="{{ route('orders.status.update', $order['id']) }}" class="d-grid gap-3">
                    @csrf
                    <div>
                        <label for="status">Novo status</label>
                        <select name="status" id="status" class="form-select" required>
                            @foreach ($order['status_disponiveis'] ?? [] as $status)
                                <option value="{{ $status['codigo'] }}" @selected(($order['status'] ?? '') === $status['codigo'])>
                                    {{ $status['nome'] }} ({{ $status['codigo'] }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="observacao">Observação da mudança</label>
                        <textarea name="observacao" id="observacao" class="form-control" rows="4" placeholder="Registre o motivo ou contexto da alteração">{{ old('observacao') }}</textarea>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-arrow-repeat me-2"></i>
                            Salvar novo status
                        </button>
                    </div>
                </form>
            </article>
        @endif

        <article class="surface-card">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">Resumo financeiro e datas</h2>
                    <p class="surface-subtitle">Campos já lidos da API, prontos para evoluir sem tocar no banco pelo desktop.</p>
                </div>
            </div>

            <div class="detail-list">
                <div class="detail-item"><strong>Abertura</strong><span>{{ $order['data_abertura'] !== '' ? $order['data_abertura'] : 'Não informada' }}</span></div>
                <div class="detail-item"><strong>Entrada</strong><span>{{ $order['data_entrada'] !== '' ? $order['data_entrada'] : 'Não informada' }}</span></div>
                <div class="detail-item"><strong>Previsão</strong><span>{{ $order['data_previsao'] !== '' ? $order['data_previsao'] : 'Não informada' }}</span></div>
                <div class="detail-item"><strong>Conclusão</strong><span>{{ $order['data_conclusao'] !== '' ? $order['data_conclusao'] : 'Não informada' }}</span></div>
                <div class="detail-item"><strong>Valor final</strong><span>{{ $order['valor_final'] !== '' ? 'R$ ' . $order['valor_final'] : 'Não calculado' }}</span></div>
                <div class="detail-item"><strong>Garantia</strong><span>{{ ($order['garantia_dias'] ?? 0) > 0 ? $order['garantia_dias'] . ' dias' : 'Não definida' }}</span></div>
            </div>
        </article>
    </section>

    <section class="desktop-grid desktop-grid-two mb-4">
        <article class="surface-card">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">Diagnóstico operacional</h2>
                    <p class="surface-subtitle">Conteúdo principal da OS já centralizado no backend.</p>
                </div>
            </div>

            <div class="detail-list">
                <div class="detail-item">
                    <strong>Relato do cliente</strong>
                    <p>{{ $order['relato_cliente'] !== '' ? $order['relato_cliente'] : 'Não informado' }}</p>
                </div>
                <div class="detail-item">
                    <strong>Diagnóstico técnico</strong>
                    <p>{{ $order['diagnostico_tecnico'] !== '' ? $order['diagnostico_tecnico'] : 'Não informado' }}</p>
                </div>
                <div class="detail-item">
                    <strong>Solução aplicada</strong>
                    <p>{{ $order['solucao_aplicada'] !== '' ? $order['solucao_aplicada'] : 'Não informada' }}</p>
                </div>
                <div class="detail-item">
                    <strong>Procedimentos executados</strong>
                    <p>{{ $order['procedimentos_executados'] !== '' ? $order['procedimentos_executados'] : 'Não informados' }}</p>
                </div>
            </div>
        </article>

        <article class="surface-card">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">Dados complementares</h2>
                    <p class="surface-subtitle">Cliente, equipamento e observações derivados do mesmo contrato de API.</p>
                </div>
            </div>

            <div class="detail-list">
                <div class="detail-item"><strong>Acessórios</strong><span>{{ $order['acessorios'] !== '' ? $order['acessorios'] : 'Não informados' }}</span></div>
                <div class="detail-item"><strong>Forma de pagamento</strong><span>{{ $order['forma_pagamento'] !== '' ? $order['forma_pagamento'] : 'Não informada' }}</span></div>
                <div class="detail-item"><strong>Observações internas</strong><p>{{ $order['observacoes_internas'] !== '' ? $order['observacoes_internas'] : 'Sem observações' }}</p></div>
                <div class="detail-item"><strong>Observações do cliente</strong><p>{{ $order['observacoes_cliente'] !== '' ? $order['observacoes_cliente'] : 'Sem observações' }}</p></div>
            </div>
        </article>
    </section>

    <section class="desktop-grid desktop-grid-two mb-4">
        <article class="surface-card">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">Fotos vinculadas</h2>
                    <p class="surface-subtitle">Acesso controlado por endpoint do backend, sem URL pública solta.</p>
                </div>
            </div>

            @if (($order['fotos'] ?? []) !== [])
                <div class="attachment-grid">
                    @foreach ($order['fotos'] as $photo)
                        <article class="attachment-card">
                            <div class="attachment-preview">
                                <i class="bi bi-image"></i>
                            </div>
                            <div>
                                <strong>{{ $photo['tipo_label'] !== '' ? $photo['tipo_label'] : 'Foto' }}</strong>
                                <small>{{ $photo['nome_arquivo'] !== '' ? $photo['nome_arquivo'] : 'Arquivo sem nome' }}</small>
                            </div>
                            <a href="{{ route('orders.photos.show', [$order['id'], $photo['id']]) }}" target="_blank" rel="noreferrer" class="btn btn-outline-light btn-sm">
                                Visualizar
                            </a>
                        </article>
                    @endforeach
                </div>
            @else
                @include('layouts.partials.empty-state', [
                    'icon' => 'bi-images',
                    'title' => 'Sem fotos vinculadas',
                    'message' => 'Quando existirem imagens desta OS no legado, o backend central fará a mediação do acesso.',
                ])
            @endif
        </article>

        <article class="surface-card">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">Documentos e PDFs</h2>
                    <p class="surface-subtitle">Orçamentos, anexos e arquivos operacionais servidos pelo backend.</p>
                </div>
            </div>

            @if (($order['documentos'] ?? []) !== [])
                <div class="attachment-grid">
                    @foreach ($order['documentos'] as $document)
                        <article class="attachment-card">
                            <div class="attachment-preview">
                                <i class="bi bi-file-earmark-pdf"></i>
                            </div>
                            <div>
                                <strong>{{ $document['tipo_label'] !== '' ? $document['tipo_label'] : 'Documento' }}</strong>
                                <small>{{ $document['nome_arquivo'] !== '' ? $document['nome_arquivo'] : 'Arquivo sem nome' }}</small>
                            </div>
                            <a href="{{ route('orders.documents.show', [$order['id'], $document['id']]) }}" target="_blank" rel="noreferrer" class="btn btn-outline-light btn-sm">
                                Abrir documento
                            </a>
                        </article>
                    @endforeach
                </div>
            @else
                @include('layouts.partials.empty-state', [
                    'icon' => 'bi-file-earmark-x',
                    'title' => 'Sem documentos vinculados',
                    'message' => 'Os PDFs desta OS aparecerão aqui assim que existirem no repositório legado integrado.',
                ])
            @endif
        </article>
    </section>

    <section class="surface-card">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Histórico recente da OS</h2>
                <p class="surface-subtitle">Movimentos mais recentes retornados pelo backend com autor e observação.</p>
            </div>
        </div>

        @if (($order['historico'] ?? []) !== [])
            <div class="timeline">
                @foreach ($order['historico'] as $history)
                    <article class="timeline-item">
                        <div class="d-flex flex-wrap justify-content-between gap-2">
                            <strong>{{ $history['status_anterior'] !== '' ? $history['status_anterior'] : 'Sem origem' }} → {{ $history['status_novo'] !== '' ? $history['status_novo'] : 'Sem destino' }}</strong>
                            <small>{{ $history['created_at'] !== '' ? $history['created_at'] : 'Data não informada' }}</small>
                        </div>
                        <p class="mb-2 mt-2">{{ $history['observacao'] !== '' ? $history['observacao'] : 'Sem observação registrada.' }}</p>
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
@endsection
