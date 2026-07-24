@extends('layouts.app')

@section('content')
    @php
        $budget = is_array($budget ?? null) ? $budget : [];
        $client = is_array($budget['cliente'] ?? null) ? $budget['cliente'] : [];
        $equipment = is_array($budget['equipamento'] ?? null) ? $budget['equipamento'] : [];
        $order = is_array($budget['os'] ?? null) ? $budget['os'] : [];
        $numeroOs = trim((string) ($budget['numero_os'] ?? ($order['numero_os'] ?? '')));
        $items = is_array($budget['itens'] ?? null) ? $budget['itens'] : [];
        $histories = is_array($budget['historico'] ?? null) ? $budget['historico'] : [];
        $sends = is_array($budget['envios'] ?? null) ? $budget['envios'] : [];
        $approvals = is_array($budget['aprovacoes'] ?? null) ? $budget['aprovacoes'] : [];
        $budgetId = (int) ($budget['id'] ?? 0);
        $publicLink = trim((string) ($budget['link_publico'] ?? ''));
        $itemsDescontoTotal = array_sum(array_map(static fn ($item) => (float) ($item['desconto'] ?? 0), $items));
        $itemsAcrescimoTotal = array_sum(array_map(static fn ($item) => (float) ($item['acrescimo'] ?? 0), $items));
        $itemsTotalGeral = array_sum(array_map(static fn ($item) => (float) ($item['total'] ?? 0), $items));

        // `??` só cai no fallback quando o valor é null — campos vindos da API que
        // existem mas estão salvos como string vazia (ex.: equipamento sem
        // "resumo técnico" preenchido) passavam direto e renderizavam o card em
        // branco. Os candidatos são avaliados em ordem e o primeiro não-vazio vence.
        $firstNonEmpty = static function (array $candidates, string $fallback): string {
            foreach ($candidates as $candidate) {
                $value = trim((string) ($candidate ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }

            return $fallback;
        };

        $orderId = (int) ($order['id'] ?? ($budget['os_id'] ?? 0));

        // Marca + modelo do equipamento — registrado (da OS/cadastro) ou eventual.
        $equipmentBrandModel = trim(implode(' ', array_filter([
            trim((string) ($equipment['marca_nome'] ?? '')),
            trim((string) ($equipment['modelo_nome'] ?? '')),
        ])));
        $equipmentEventualBrandModel = trim(implode(' ', array_filter([
            trim((string) ($budget['equipamento_marca_avulso'] ?? '')),
            trim((string) ($budget['equipamento_modelo_avulso'] ?? '')),
        ])));
        $equipmentEventualMeta = trim(implode(' · ', array_filter([
            trim((string) ($budget['equipamento_tipo_avulso'] ?? '')),
            trim((string) ($budget['equipamento_cor'] ?? '')),
        ])));
    @endphp

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Orçamento</p>
            <h2 class="surface-title fs-3 mb-2">{{ $budget['numero'] !== '' ? $budget['numero'] : ('#' . $budgetId) }}</h2>
            <div class="d-flex flex-wrap gap-2">
                @include('layouts.partials.status-pill', [
                    'label' => $budget['status_label'] ?? 'Rascunho',
                    'color' => $budget['status_color'] ?? '#64748b',
                ])
                <span class="desktop-chip">{{ $budget['tipo_label'] ?? 'Orçamento prévio' }}</span>
                <span class="desktop-chip">{{ $budget['origem_label'] ?? 'Manual' }}</span>
                <span class="desktop-chip">Versão {{ (int) ($budget['versao'] ?? 1) }}</span>
            </div>
        </div>

        <div class="dropdown os-actions-dropdown align-self-start">
            <button type="button"
                class="btn btn-outline-light dropdown-toggle os-actions-toggle"
                data-bs-toggle="dropdown"
                aria-expanded="false">
                Mais ações
            </button>

            <div class="dropdown-menu dropdown-menu-end os-actions-menu">
                <a href="{{ route('orcamentos.index') }}" class="dropdown-item">
                    <i class="bi bi-arrow-left me-2"></i>Voltar
                </a>

                @if ($orderId > 0)
                    <a href="{{ route('orders.show', $orderId) }}" class="dropdown-item">
                        <i class="bi bi-eye me-2"></i>Ver OS
                    </a>

                    <a href="{{ route('orders.documents.center', $orderId) }}" class="dropdown-item">
                        <i class="bi bi-folder-symlink me-2"></i>Documentos da OS
                    </a>
                @endif

                @if ($publicLink !== '')
                    <button type="button" class="dropdown-item" data-copy-link="{{ $publicLink }}">
                        <i class="bi bi-clipboard me-2"></i>Copiar link
                    </button>
                @endif

                @if (! empty($budget['can_edit']))
                    <a href="{{ route('orcamentos.edit', $budgetId) }}" class="dropdown-item">
                        <i class="bi bi-pencil me-2"></i>Editar
                    </a>
                @endif

                @if (! empty($budget['can_send_approval']))
                    <form method="post" action="{{ route('orcamentos.send_approval', $budgetId) }}" data-confirm="O PDF da proposta será gerado e enviado ao cliente pelo WhatsApp. Deseja continuar?" data-confirm-title="{{ $sends !== [] ? 'Reenviar proposta' : 'Enviar proposta' }}" data-confirm-button="Sim, enviar">
                        @csrf
                        <button type="submit" class="dropdown-item">
                            <i class="bi bi-send me-2"></i>{{ $sends !== [] ? 'Reenviar para aprovação' : 'Enviar para aprovação' }}
                        </button>
                    </form>
                @endif

                @if (! empty($budget['can_generate_os']))
                    <div class="dropdown-divider"></div>
                    <a href="{{ route('orders.create', ['orcamento_id' => $budgetId]) }}" class="dropdown-item text-primary">
                        <i class="bi bi-wrench-adjustable me-2"></i>Gerar OS a partir deste orçamento
                    </a>
                @endif

                @if (! empty($budget['can_approve']) || ! empty($budget['can_reject']) || ! empty($budget['can_cancel']))
                    <div class="dropdown-divider"></div>
                @endif

                @if (! empty($budget['can_approve']))
                    <form method="post" action="{{ route('orcamentos.approve', $budgetId) }}" data-confirm="Registrar que o cliente aprovou este orçamento por outros meios (telefone, presencial, etc.)?" data-confirm-title="Aprovar orçamento" data-confirm-button="Sim, registrar aprovação" data-confirm-icon="question">
                        @csrf
                        <button type="submit" class="dropdown-item text-success">
                            <i class="bi bi-check2-circle me-2"></i>Aprovar (outros meios)
                        </button>
                    </form>
                @endif

                @if (! empty($budget['can_reject']))
                    <form method="post" action="{{ route('orcamentos.reject', $budgetId) }}" data-confirm="Confirmar que o cliente recusou explicitamente este orçamento?" data-confirm-title="Rejeitar orçamento" data-confirm-button="Sim, registrar recusa" data-confirm-input="textarea" data-confirm-input-placeholder="Motivo da recusa (opcional)">
                        @csrf
                        <input type="hidden" name="motivo" data-confirm-reason>
                        <button type="submit" class="dropdown-item text-warning">
                            <i class="bi bi-x-circle me-2"></i>Rejeitar (em nome do cliente)
                        </button>
                    </form>
                @endif

                @if (! empty($budget['can_cancel']))
                    <form method="post" action="{{ route('orcamentos.cancel', $budgetId) }}" data-confirm="Cancelar este orçamento por falta de resposta do cliente?" data-confirm-title="Cancelar orçamento" data-confirm-button="Sim, cancelar" data-confirm-input="textarea" data-confirm-input-placeholder="Motivo do cancelamento (opcional)">
                        @csrf
                        <input type="hidden" name="motivo" data-confirm-reason>
                        <button type="submit" class="dropdown-item text-secondary">
                            <i class="bi bi-slash-circle me-2"></i>Cancelar (sem resposta)
                        </button>
                    </form>
                @endif

                @if (! empty($budget['can_delete']))
                    <div class="dropdown-divider"></div>
                    <form method="post" action="{{ route('orcamentos.destroy', $budgetId) }}" data-confirm="Deseja excluir este orçamento? Esta ação não poderá ser desfeita." data-confirm-title="Excluir orçamento" data-confirm-button="Sim, excluir">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="dropdown-item text-danger">
                            <i class="bi bi-trash me-2"></i>Excluir
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <section class="desktop-grid desktop-grid-four mb-4">
        <article class="summary-card">
            <span class="summary-card-eyebrow">Cliente</span>
            <div class="summary-card-value">{{ $firstNonEmpty([$client['nome_razao'] ?? null, $budget['cliente_nome_avulso'] ?? null], 'Não informado') }}</div>
            <div class="summary-card-meta">{{ $firstNonEmpty([$client['cpf_cnpj'] ?? null, $budget['telefone_contato'] ?? null], 'Sem documento') }}</div>
        </article>

        <article class="summary-card">
            <span class="summary-card-eyebrow">Equipamento / OS</span>
            <div class="summary-card-value">{{ $firstNonEmpty([$equipmentBrandModel, $equipmentEventualBrandModel, $equipment['resumo_tecnico'] ?? null, $numeroOs], 'Sem vínculo') }}</div>
            <div class="summary-card-meta">{{ $firstNonEmpty([$equipment['numero_serie'] ?? null, $equipmentEventualMeta, $order['status'] ?? null], 'Sem série informada') }}</div>
        </article>

        <article class="summary-card">
            <span class="summary-card-eyebrow">Validade</span>
            <div class="summary-card-value">{{ $firstNonEmpty([$budget['validade_data'] ?? null], 'Sem data') }}</div>
            <div class="summary-card-meta">{{ (int) ($budget['validade_dias'] ?? 0) > 0 ? (int) ($budget['validade_dias'] ?? 0) . ' dias' : 'Prazo não definido' }}</div>
        </article>

        <article class="summary-card is-highlight">
            <span class="summary-card-eyebrow">Total</span>
            <div class="summary-card-value">R$ {{ $budget['total_formatado'] ?? number_format((float) ($budget['total'] ?? 0), 2, ',', '.') }}</div>
            <div class="summary-card-meta">Subtotal: R$ {{ number_format((float) ($budget['subtotal'] ?? 0), 2, ',', '.') }}</div>
        </article>
    </section>

    <section class="desktop-grid desktop-grid-two mb-4">
        <article class="surface-card">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">Dados principais</h2>
                    <p class="surface-subtitle">Cliente, equipamento, OS e origem do orçamento.</p>
                </div>
            </div>

            <div class="detail-list">
                <div class="detail-item"><strong>Cliente</strong><span>{{ $client['nome_razao'] ?? ($budget['cliente_nome_avulso'] ?? 'Não informado') }}</span></div>
                <div class="detail-item"><strong>Documento</strong><span>{{ $client['cpf_cnpj'] ?? 'Não informado' }}</span></div>
                <div class="detail-item"><strong>Contato</strong><span>{{ $budget['telefone_contato'] !== '' ? $budget['telefone_contato'] : 'Não informado' }}</span></div>
                <div class="detail-item"><strong>E-mail</strong><span>{{ $budget['email_contato'] !== '' ? $budget['email_contato'] : 'Não informado' }}</span></div>
                <div class="detail-item"><strong>Tipo</strong><span>{{ $budget['tipo_label'] ?? 'Orçamento prévio' }}</span></div>
                <div class="detail-item"><strong>Origem</strong><span>{{ $budget['origem_label'] ?? 'Manual' }}</span></div>
                <div class="detail-item"><strong>Equipamento</strong><span>{{ $firstNonEmpty([$equipmentBrandModel, $equipmentEventualBrandModel, $equipment['resumo_tecnico'] ?? null, $budget['equipamento_eventual_label'] ?? null], 'Não informado') }}</span></div>
                <div class="detail-item"><strong>OS vinculada</strong><span>{{ $numeroOs !== '' ? $numeroOs : 'Sem OS' }}</span></div>
                <div class="detail-item"><strong>Responsável</strong><span>{{ $budget['responsavel']['nome'] ?? 'Não informado' }}</span></div>
            </div>
        </article>

        <article class="surface-card">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">Status e vigência</h2>
                    <p class="surface-subtitle">Controle operacional da proposta no fluxo comercial.</p>
                </div>
            </div>

            <div class="detail-list">
                <div class="detail-item"><strong>Status</strong><span>{{ $budget['status_label'] ?? 'Rascunho' }}</span></div>
                <div class="detail-item"><strong>Validade até</strong><span>{{ $budget['validade_data'] !== '' ? $budget['validade_data'] : 'Não definida' }}</span></div>
                <div class="detail-item"><strong>Prazo de execução</strong><span>{{ $budget['prazo_execucao'] !== '' ? $budget['prazo_execucao'] : 'Não informado' }}</span></div>
                <div class="detail-item"><strong>Atualizado em</strong><span>{{ $budget['updated_at'] !== '' ? $budget['updated_at'] : 'Sem atualização' }}</span></div>
                <div class="detail-item"><strong>Criado em</strong><span>{{ $budget['created_at'] !== '' ? $budget['created_at'] : 'Sem informação' }}</span></div>
                <div class="detail-item"><strong>Condições</strong><p class="mb-0">{{ $budget['condicoes'] !== '' ? $budget['condicoes'] : 'Sem condições registradas' }}</p></div>
            </div>
        </article>
    </section>

    <section class="surface-card mb-4">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Itens do orçamento</h2>
                <p class="surface-subtitle">Serviços e peças com custo, margem e observações por linha.</p>
            </div>
        </div>

        @if ($items !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Descrição</th>
                        <th>Qtd</th>
                        <th>Valor unit.</th>
                        <th>Desconto</th>
                        <th>Acréscimo</th>
                        <th>Total</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td data-label="Tipo">{{ ucfirst((string) ($item['tipo_item'] ?? 'servico')) }}</td>
                            <td data-label="Descrição">
                                <div class="fw-semibold">{{ $item['descricao'] !== '' ? $item['descricao'] : 'Sem descrição' }}</div>
                                @if (($item['observacoes'] ?? '') !== '')
                                    <small class="text-secondary d-block">{{ $item['observacoes'] }}</small>
                                @endif
                            </td>
                            <td data-label="Qtd">{{ number_format((float) ($item['quantidade'] ?? 0), 2, ',', '.') }}</td>
                            <td data-label="Valor unit.">R$ {{ number_format((float) ($item['valor_unitario'] ?? 0), 2, ',', '.') }}</td>
                            <td data-label="Desconto">R$ {{ number_format((float) ($item['desconto'] ?? 0), 2, ',', '.') }}</td>
                            <td data-label="Acréscimo">R$ {{ number_format((float) ($item['acrescimo'] ?? 0), 2, ',', '.') }}</td>
                            <td data-label="Total" class="fw-bold">R$ {{ number_format((float) ($item['total'] ?? 0), 2, ',', '.') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot class="table-group-divider">
                    <tr class="fw-semibold">
                        <td colspan="4" class="text-end">Totais dos itens</td>
                        <td data-label="Desconto">R$ {{ number_format($itemsDescontoTotal, 2, ',', '.') }}</td>
                        <td data-label="Acréscimo">R$ {{ number_format($itemsAcrescimoTotal, 2, ',', '.') }}</td>
                        <td data-label="Total" class="fw-bold">R$ {{ number_format($itemsTotalGeral, 2, ',', '.') }}</td>
                    </tr>
                    </tfoot>
                </table>
            </div>
        @else
            @include('layouts.partials.empty-state', [
                'icon' => 'bi-card-list',
                'title' => 'Sem itens cadastrados',
                'message' => 'Este orçamento ainda não possui pacotes ou serviços adicionados.',
            ])
        @endif
    </section>

    <section class="desktop-grid desktop-grid-two mb-4">
        <article class="surface-card">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">Histórico</h2>
                    <p class="surface-subtitle">Eventos de status e alterações mais recentes.</p>
                </div>
            </div>

            @if ($histories !== [])
                <div class="timeline">
                    @foreach ($histories as $history)
                        <article class="timeline-item">
                            <div class="d-flex flex-wrap justify-content-between gap-2">
                                <strong>{{ $history['status_anterior'] !== '' ? $history['status_anterior'] : 'Sem origem' }} → {{ $history['status_novo'] !== '' ? $history['status_novo'] : 'Sem destino' }}</strong>
                                <small>{{ $history['created_at'] !== '' ? $history['created_at'] : 'Data não informada' }}</small>
                            </div>
                            <p class="mb-2 mt-2">{{ $history['observacao'] !== '' ? $history['observacao'] : 'Sem observação registrada.' }}</p>
                            <small>Responsável: {{ $history['alterado_por_nome'] !== '' ? $history['alterado_por_nome'] : 'Sistema' }}</small>
                        </article>
                    @endforeach
                </div>
            @else
                @include('layouts.partials.empty-state', [
                    'icon' => 'bi-clock-history',
                    'title' => 'Sem histórico',
                    'message' => 'Os próximos movimentos deste orçamento aparecerão aqui automaticamente.',
                ])
            @endif
        </article>

        <article class="surface-card">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">Envios e aprovações</h2>
                    <p class="surface-subtitle">Controle de comunicação e decisão do cliente.</p>
                </div>
            </div>

            <div class="detail-list">
                @if ($publicLink !== '')
                    <div class="detail-item">
                        <strong>Link de aprovação</strong>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <input type="text" class="form-control form-control-sm" value="{{ $publicLink }}" readonly style="max-width: 320px;">
                            <button type="button" class="btn btn-sm btn-outline-primary" data-copy-link="{{ $publicLink }}">
                                <i class="bi bi-clipboard me-1"></i>
                                Copiar
                            </button>
                        </div>
                        <small class="text-secondary d-block mt-1">Use este link para envio avulso (WhatsApp, e-mail ou outro canal).</small>
                    </div>
                @endif

                <div class="detail-item">
                    <strong>Envios recentes</strong>
                    @if ($sends !== [])
                        <div class="timeline">
                            @foreach ($sends as $send)
                                <article class="timeline-item">
                                    <div class="d-flex flex-wrap justify-content-between gap-2">
                                        <strong>{{ $send['canal'] !== '' ? ucfirst($send['canal']) : 'Canal não informado' }}</strong>
                                        <small>{{ $send['status'] !== '' ? $send['status'] : 'Status não informado' }}</small>
                                    </div>
                                    <div class="mt-2">{{ $send['destino'] !== '' ? $send['destino'] : 'Destino não informado' }}</div>
                                </article>
                            @endforeach
                        </div>
                    @else
                        <span>Sem envios registrados.</span>
                    @endif
                </div>

                <div class="detail-item">
                    <strong>Aprovações recentes</strong>
                    @if ($approvals !== [])
                        <div class="timeline">
                            @foreach ($approvals as $approval)
                                <article class="timeline-item">
                                    <div class="d-flex flex-wrap justify-content-between gap-2">
                                        <strong>{{ $approval['acao'] !== '' ? ucfirst($approval['acao']) : 'Ação' }}</strong>
                                        <small>{{ $approval['usuario_nome'] !== '' ? $approval['usuario_nome'] : 'Usuário não identificado' }}</small>
                                    </div>
                                    <div class="mt-2">{{ $approval['resposta_cliente'] !== '' ? $approval['resposta_cliente'] : 'Sem resposta' }}</div>
                                </article>
                            @endforeach
                        </div>
                    @else
                        <span>Sem aprovações registradas.</span>
                    @endif
                </div>
            </div>
        </article>
    </section>
@endsection

@section('scripts')
    <script>
        (function () {
            'use strict';

            function copyText(text) {
                if (navigator.clipboard && window.isSecureContext) {
                    return navigator.clipboard.writeText(text);
                }

                return new Promise(function (resolve, reject) {
                    var helper = document.createElement('textarea');
                    helper.value = text;
                    helper.setAttribute('readonly', 'readonly');
                    helper.style.position = 'fixed';
                    helper.style.opacity = '0';
                    document.body.appendChild(helper);
                    helper.select();

                    try {
                        document.execCommand('copy') ? resolve() : reject(new Error('copy-failed'));
                    } catch (error) {
                        reject(error);
                    } finally {
                        document.body.removeChild(helper);
                    }
                });
            }

            document.querySelectorAll('[data-copy-link]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var originalHtml = button.innerHTML;

                    copyText(button.getAttribute('data-copy-link') || '').then(function () {
                        button.innerHTML = '<i class="bi bi-check2 me-2"></i>Link copiado!';
                    }).catch(function () {
                        button.innerHTML = '<i class="bi bi-x-circle me-2"></i>Não foi possível copiar';
                    }).finally(function () {
                        window.setTimeout(function () {
                            button.innerHTML = originalHtml;
                        }, 2000);
                    });
                });
            });
        })();
    </script>
@endsection
