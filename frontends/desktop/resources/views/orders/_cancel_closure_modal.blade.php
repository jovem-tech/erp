{{--
    Modal de cancelamento de baixa da OS.
    Incluir em qualquer view que precise desta funcionalidade (junto com
    orders-cancel-closure-modal.js e window.__DESKTOP_CANCEL_CLOSURE_MODAL).
    Visível para qualquer usuário com acesso ao painel da OS — a autorização
    real é a verificação de credenciais de administrador no submit.
--}}
<div class="modal fade" id="cancelClosureModal" tabindex="-1" aria-hidden="true" aria-labelledby="cancelClosureModalTitleEl">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-shell">
            <form id="cancelClosureForm" novalidate>
                <div class="modal-header">
                    <h5 class="modal-title" id="cancelClosureModalTitleEl">
                        Cancelar baixa da OS
                        <span id="cancelClosureNumero" class="text-primary fw-bold ms-1">-</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>

                <div class="modal-body">
                    <div class="alert alert-warning">
                        Isso reverte o status desta OS para a etapa anterior à baixa e <strong>exclui</strong> os
                        lançamentos financeiros (título, movimentos, taxas) criados na ocasião da baixa. Use apenas
                        se a baixa foi feita por engano — se o equipamento realmente foi entregue/descartado e
                        retornou à assistência, abra uma nova OS em vez de cancelar esta baixa.
                    </div>

                    <p class="form-text">Para concretizar o cancelamento, informe e-mail e senha de um usuário administrador.</p>

                    <div class="mb-3">
                        <label class="form-label" for="cancelClosureAdminEmail">E-mail do administrador</label>
                        <input type="email" id="cancelClosureAdminEmail" name="admin_email" class="form-control" required autocomplete="off">
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="cancelClosureAdminPassword">Senha do administrador</label>
                        <input type="password" id="cancelClosureAdminPassword" name="admin_password" class="form-control" required autocomplete="new-password">
                    </div>

                    <div id="cancelClosureError" class="alert alert-danger mt-3 d-none"></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger" id="cancelClosureSubmit">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Confirmar cancelamento da baixa
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
