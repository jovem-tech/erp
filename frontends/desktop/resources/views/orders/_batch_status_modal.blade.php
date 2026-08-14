{{--
    Modal de alteração de status em lote de OS (Mais ações > Alterar status
    em lote, na listagem de OS). Incluir junto com orders-status-batch.js e
    window.__DESKTOP_BATCH_STATUS_MODAL. Reaproveita $statusOptions/
    $macroGroupOptions já carregados pela própria orders/index.blade.php
    (usados hoje pelos filtros de status/macrofase) — nenhuma chamada nova
    ao backend é feita pra montar este formulário.
--}}
<div class="modal fade" id="batchStatusModal" tabindex="-1" aria-hidden="true" aria-labelledby="batchStatusModalTitleEl">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content modal-shell">
            <form id="batchStatusForm" novalidate>
                <div class="modal-header">
                    <h5 class="modal-title" id="batchStatusModalTitleEl">Alterar status em lote</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>

                <div class="modal-body">
                    <div class="alert alert-warning">
                        Todas as OS selecionadas serão movidas para o <strong>mesmo status</strong>. Status de
                        encerramento (Entregue, Devolvido Sem Reparo, Descartado) não aparecem aqui — use a baixa
                        em lote ou a baixa individual da OS para encerramentos.
                    </div>

                    <p class="form-text mb-1">
                        <span id="batchStatusCount">0</span> OS selecionada(s):
                    </p>
                    <div id="batchStatusOrderList" class="list-group batch-closure-order-list mb-3"></div>

                    <div class="mb-3">
                        <label class="form-label" for="batchStatusTarget">Novo status</label>
                        <select id="batchStatusTarget" name="status" class="form-select" required>
                            <option value="">Selecione...</option>
                            @foreach ($macroGroupOptions ?? [] as $macroGroup)
                                @continue($macroGroup === 'encerrado')
                                <optgroup label="{{ ucfirst(str_replace('_', ' ', $macroGroup)) }}">
                                    @foreach (($statusOptions ?? []) as $statusOption)
                                        @continue(($statusOption['grupo_macro'] ?? '') !== $macroGroup)
                                        <option value="{{ $statusOption['codigo'] ?? '' }}">{{ $statusOption['nome'] ?? ($statusOption['codigo'] ?? '') }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="batchStatusObservacao">Observação (opcional, aplicada a todas)</label>
                        <textarea id="batchStatusObservacao" name="observacao" class="form-control" maxlength="2000" rows="2"></textarea>
                    </div>

                    <div class="form-check mb-0">
                        <input type="checkbox" class="form-check-input" id="batchStatusNotifyClient" name="comunicar_cliente">
                        <label class="form-check-label" for="batchStatusNotifyClient">Notificar clientes via WhatsApp</label>
                        <div class="form-text">
                            Limitado a 5 notificações por execução do lote, para evitar bloqueio pela Meta/WhatsApp.
                            Se mais de 5 OS forem selecionadas com esta opção marcada, as excedentes têm o status
                            alterado normalmente, só sem notificar o cliente.
                        </div>
                    </div>

                    <div id="batchStatusError" class="alert alert-danger mt-3 d-none"></div>
                    <div id="batchStatusOrderIds"></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="batchStatusSubmit">
                        <i class="bi bi-arrow-left-right me-1"></i>Confirmar alteração em lote
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
