{{--
    Modal de baixa em lote de OS (Mais ações > Dar baixa em lote, na
    listagem de OS). Incluir junto com orders-batch-closure.js e
    window.__DESKTOP_BATCH_CLOSURE_MODAL.
--}}
<div class="modal fade" id="batchClosureModal" tabindex="-1" aria-hidden="true" aria-labelledby="batchClosureModalTitleEl">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content modal-shell">
            <form id="batchClosureForm" novalidate>
                <div class="modal-header">
                    <h5 class="modal-title" id="batchClosureModalTitleEl">Dar baixa em lote</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>

                <div class="modal-body">
                    <div class="alert alert-warning">
                        Todas as OS selecionadas serão encerradas com o <strong>mesmo status</strong> e a
                        <strong>mesma data</strong>. Não é possível registrar pagamento nesta tela — por isso
                        "Entregue - Reparado e Pago" não aparece aqui; use a baixa individual da OS para
                        encerramentos com cobrança.
                    </div>

                    <p class="form-text mb-1">
                        <span id="batchClosureCount">0</span> OS selecionada(s):
                    </p>
                    <div id="batchClosureOrderList" class="list-group batch-closure-order-list mb-3"></div>

                    <div class="mb-3">
                        <label class="form-label" for="batchClosureStatus">Encerrar como</label>
                        <select id="batchClosureStatus" name="encerrar_como" class="form-select" required>
                            <option value="">Selecione...</option>
                            <option value="entregue_reparado_sem_custo">Entregue - Reparado Sem Custo</option>
                            <option value="entregue_reparado_garantia">Entregue - Reparado em Garantia</option>
                            <option value="devolvido_sem_reparo">Devolvido Sem Reparo</option>
                            <option value="descartado">Equipamento Descartado</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="batchClosureDate">Data de entrega</label>
                        <input type="date" id="batchClosureDate" name="data_entrega" class="form-control" value="{{ now()->toDateString() }}" required>
                    </div>

                    <div class="mb-0">
                        <label class="form-label" for="batchClosureObservacao">Observação (opcional, aplicada a todas)</label>
                        <textarea id="batchClosureObservacao" name="observacao" class="form-control" maxlength="2000" rows="2"></textarea>
                    </div>

                    <div id="batchClosureError" class="alert alert-danger mt-3 d-none"></div>
                    <div id="batchClosureOrderIds"></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="batchClosureSubmit">
                        <i class="bi bi-box-seam me-1"></i>Confirmar baixa em lote
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
