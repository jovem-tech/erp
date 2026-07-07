{{--
    Modal de alteração de status da OS.
    Incluir em qualquer view que precise desta funcionalidade.
    Requer orders-status-modal.js e window.__DESKTOP_STATUS_MODAL configurado.
--}}
<style>
    .os-status-context-card {
        background: var(--desktop-surface-soft);
        border: 1px solid var(--desktop-border);
        border-radius: var(--desktop-radius-md);
        padding: 1rem;
        height: 100%;
    }

    .os-status-context-title {
        font-size: 0.68rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        color: var(--desktop-text-muted);
        margin-bottom: 0.5rem;
    }

    .os-status-context-name {
        font-weight: 700;
        color: var(--desktop-text);
        font-size: 0.95rem;
        margin-bottom: 0.25rem;
    }

    .os-status-context-meta {
        font-size: 0.82rem;
        color: var(--desktop-text-soft);
        margin-top: 0.15rem;
    }

    .os-status-modal-panel {
        background: var(--desktop-surface-soft);
        border: 1px solid var(--desktop-border);
        border-radius: var(--desktop-radius-md);
        padding: 1.25rem;
        height: 100%;
    }

    .os-status-modal-section {
        padding-bottom: 1rem;
        margin-bottom: 1rem;
        border-bottom: 1px solid var(--desktop-border);
    }

    .os-status-modal-section:last-child {
        padding-bottom: 0;
        margin-bottom: 0;
        border-bottom: none;
    }

    .os-status-modal-section-title {
        font-size: 0.7rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        color: var(--desktop-text-muted);
        margin-bottom: 0.75rem;
    }

    .os-status-modal-quick-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-bottom: 0.75rem;
    }

    .os-status-modal-flow-hints {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .os-status-modal-workflow {
        display: flex;
        flex-direction: column;
    }

    .os-status-modal-history-list {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        max-height: 340px;
        overflow-y: auto;
    }

    .os-status-history-item {
        padding: 0.75rem;
        background: var(--desktop-surface);
        border: 1px solid var(--desktop-border);
        border-radius: var(--desktop-radius-sm);
        font-size: 0.82rem;
    }

    .os-status-history-item-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.5rem;
        margin-bottom: 0.35rem;
        flex-wrap: wrap;
    }

    .os-status-history-item-badge {
        font-size: 0.72rem;
        font-weight: 600;
        padding: 0.15rem 0.5rem;
        border-radius: 999px;
        background: var(--desktop-primary-soft);
        color: var(--desktop-primary);
        white-space: nowrap;
    }

    .os-status-history-item-date {
        font-size: 0.72rem;
        color: var(--desktop-text-muted);
        flex-shrink: 0;
    }

    .os-status-history-item-obs {
        color: var(--desktop-text-soft);
        margin-top: 0.25rem;
        line-height: 1.45;
    }

    .os-status-history-item-author {
        font-size: 0.72rem;
        color: var(--desktop-text-muted);
        margin-top: 0.25rem;
    }

    .os-status-modal-footer-notify {
        margin-right: auto;
    }
</style>

<div class="modal fade" id="orderStatusModal" tabindex="-1" aria-hidden="true" aria-labelledby="orderStatusModalTitleEl">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content modal-shell">
            <form id="orderStatusModalForm" novalidate>
                <div class="modal-header">
                    <h5 class="modal-title" id="orderStatusModalTitleEl">
                        Alterar status da OS
                        <span id="orderStatusModalNumero" class="text-primary fw-bold ms-1">-</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>

                <div class="modal-body">
                    {{-- Estado de carregamento --}}
                    <div id="orderStatusModalLoading" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="text-muted small mt-3 mb-0">Carregando dados da OS...</p>
                    </div>

                    {{-- Estado de erro --}}
                    <div id="orderStatusModalError" class="d-none">
                        <div class="alert alert-danger mb-0">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <span id="orderStatusModalErrorText">Não foi possível carregar os dados da OS.</span>
                        </div>
                    </div>

                    {{-- Conteúdo principal --}}
                    <div id="orderStatusModalContent" class="d-none">
                        {{-- Cards de contexto: cliente + equipamento --}}
                        <div class="row g-3 mb-4">
                            <div class="col-12 col-lg-6">
                                <div class="os-status-context-card">
                                    <div class="os-status-context-title">Cliente</div>
                                    <div class="os-status-context-name" id="orderStatusModalClientName">-</div>
                                    <div class="os-status-context-meta" id="orderStatusModalClientPhone">Telefone: -</div>
                                    <div class="os-status-context-meta" id="orderStatusModalClientEmail">E-mail: -</div>
                                </div>
                            </div>
                            <div class="col-12 col-lg-6">
                                <div class="os-status-context-card">
                                    <div class="os-status-context-title">Equipamento</div>
                                    <div class="os-status-context-name" id="orderStatusModalEquipName">-</div>
                                    <div class="os-status-context-meta" id="orderStatusModalEquipType">Tipo: -</div>
                                    <div class="os-status-context-meta" id="orderStatusModalEquipSerial">Nº de série: -</div>
                                </div>
                            </div>
                        </div>

                        <ul class="nav nav-tabs mb-3" id="orderStatusModalTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="orderStatusModalTabStatusBtn" data-bs-toggle="tab"
                                    data-bs-target="#orderStatusModalTabStatus" type="button" role="tab"
                                    aria-controls="orderStatusModalTabStatus" aria-selected="true">
                                    Status
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="orderStatusModalTabProceduresBtn" data-bs-toggle="tab"
                                    data-bs-target="#orderStatusModalTabProcedures" type="button" role="tab"
                                    aria-controls="orderStatusModalTabProcedures" aria-selected="false">
                                    Procedimentos
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content" id="orderStatusModalTabsContent">
                            <div class="tab-pane fade show active" id="orderStatusModalTabStatus" role="tabpanel" aria-labelledby="orderStatusModalTabStatusBtn">
                                <div class="row g-4">
                                    {{-- Coluna esquerda: formulário de mudança --}}
                                    <div class="col-12 col-xl-7">
                                        <div class="os-status-modal-panel">
                                            {{-- Ações rápidas --}}
                                            <div class="os-status-modal-section">
                                                <div class="os-status-modal-section-title">Ações rápidas</div>
                                                <div class="os-status-modal-quick-actions">
                                                    <button type="button" class="btn btn-primary btn-sm" id="orderStatusModalQuickNext" disabled>
                                                        <i class="bi bi-arrow-right-circle me-1"></i>Próxima etapa
                                                    </button>
                                                    <button type="button" class="btn btn-outline-danger btn-sm" id="orderStatusModalQuickCancel" disabled>
                                                        <i class="bi bi-x-circle me-1"></i>Cancelar OS
                                                    </button>
                                                </div>
                                                <div class="os-status-modal-flow-hints">
                                                    <div class="small text-muted" id="orderStatusModalCurrentHint">Status atual da OS: aguardando contexto.</div>
                                                    <div class="small text-muted" id="orderStatusModalFlowHint">Fluxo normal sugerido: aguardando contexto.</div>
                                                    <div class="small text-muted" id="orderStatusModalTargetHint">Selecione um fluxo para continuar.</div>
                                                </div>
                                            </div>

                                            {{-- Status de destino --}}
                                            <div class="os-status-modal-section">
                                                <label class="form-label" for="orderStatusModalSelect">Status de destino</label>
                                                <select id="orderStatusModalSelect" name="status" class="form-select" required>
                                                    <option value="">Selecione um status</option>
                                                </select>
                                                <div class="form-text">A lista respeita o fluxo de trabalho configurado para avançar, retornar etapas ou cancelar o atendimento.</div>
                                            </div>

                                            {{-- Observações --}}
                                            <div class="os-status-modal-section">
                                                <label class="form-label" for="orderStatusModalObservacao">Observações</label>
                                                <textarea
                                                    id="orderStatusModalObservacao"
                                                    name="observacao"
                                                    class="form-control"
                                                    rows="4"
                                                    placeholder="Registre contexto da mudança, combinados com o cliente ou justificativa do cancelamento."
                                                ></textarea>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Coluna direita: histórico --}}
                                    <div class="col-12 col-xl-5">
                                        <div class="os-status-modal-panel os-status-modal-workflow">
                                            <div class="os-status-modal-section-title">Histórico e progresso</div>
                                            <p class="small text-muted mb-3">Etapas percorridas e últimas movimentações desta OS.</p>
                                            <div id="orderStatusModalHistory" class="os-status-modal-history-list">
                                                <p class="text-muted small mb-0">Sem histórico recente.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="orderStatusModalTabProcedures" role="tabpanel" aria-labelledby="orderStatusModalTabProceduresBtn">
                                <div class="row g-4">
                                    {{-- Coluna esquerda: registrar procedimento + diagnóstico/solução --}}
                                    <div class="col-12 col-xl-7">
                                        <div class="os-status-modal-panel">
                                            <div class="os-status-modal-section">
                                                <label class="form-label" for="orderStatusModalProcedures">Procedimentos executados</label>
                                                <textarea
                                                    id="orderStatusModalProcedures"
                                                    class="form-control"
                                                    rows="3"
                                                    placeholder="Descreva um procedimento executado para registrar no histórico."
                                                ></textarea>
                                                <div class="text-end mt-2">
                                                    <button type="button" class="btn btn-primary btn-sm" id="orderStatusModalProceduresSave">
                                                        <i class="bi bi-check2-circle me-1"></i>Salvar procedimento
                                                    </button>
                                                </div>
                                            </div>

                                            <div class="os-status-modal-section">
                                                <label class="form-label" for="orderStatusModalDiagnosis">Diagnóstico do problema</label>
                                                <textarea
                                                    id="orderStatusModalDiagnosis"
                                                    name="diagnostico_tecnico"
                                                    class="form-control"
                                                    rows="3"
                                                    placeholder="Descreva o diagnóstico técnico do problema."
                                                ></textarea>
                                            </div>

                                            <div class="os-status-modal-section">
                                                <label class="form-label" for="orderStatusModalSolution">Solução aplicada</label>
                                                <textarea
                                                    id="orderStatusModalSolution"
                                                    name="solucao_aplicada"
                                                    class="form-control"
                                                    rows="3"
                                                    placeholder="Descreva a solução aplicada para resolver o problema."
                                                ></textarea>
                                                <div class="form-text">Diagnóstico e solução são salvos junto com "Salvar status", no rodapé.</div>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Coluna direita: histórico de procedimentos --}}
                                    <div class="col-12 col-xl-5">
                                        <div class="os-status-modal-panel os-status-modal-workflow">
                                            <div class="os-status-modal-section-title">Histórico de procedimentos</div>
                                            <p class="small text-muted mb-3">Procedimentos registrados pelos técnicos nesta OS.</p>
                                            <div id="orderStatusModalProceduresHistory" class="os-status-modal-history-list">
                                                <p class="text-muted small mb-0">Nenhum procedimento registrado ainda.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <div class="form-check form-switch os-status-modal-footer-notify">
                        <input class="form-check-input" type="checkbox" role="switch"
                            id="orderStatusModalNotify" name="comunicar_cliente" value="1">
                        <label class="form-check-label" for="orderStatusModalNotify">
                            Notificar o cliente sobre esta mudança
                        </label>
                    </div>
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="orderStatusModalSubmit" disabled>
                        <i class="bi bi-check2-circle me-1"></i>Salvar status
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
