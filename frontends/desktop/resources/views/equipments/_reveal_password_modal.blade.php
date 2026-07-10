{{--
    Modal de revelação da senha de acesso do equipamento.
    Step-up de administrador (skill sistema-erp-autenticacao-step-up): o botão
    fica visível a quem visualiza equipamentos, mas a senha só é retornada
    mediante e-mail e senha válidos de um usuário administrador.
    Incluir junto com equipments-reveal-password-modal.js e
    window.__DESKTOP_REVEAL_PASSWORD_MODAL.
--}}
<div class="modal fade" id="revealPasswordModal" tabindex="-1" aria-hidden="true" aria-labelledby="revealPasswordModalTitleEl">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-shell">
            <form id="revealPasswordForm" novalidate>
                <div class="modal-header">
                    <h5 class="modal-title" id="revealPasswordModalTitleEl">
                        Revelar senha do equipamento
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>

                <div class="modal-body">
                    <p class="form-text mb-3">
                        A senha de acesso do equipamento é protegida. Para visualizá-la, informe e-mail e senha
                        de um usuário administrador — a consulta fica registrada no log de auditoria.
                    </p>

                    <div class="mb-3">
                        <label class="form-label" for="revealPasswordAdminEmail">E-mail do administrador</label>
                        <input type="email" id="revealPasswordAdminEmail" name="admin_email" class="form-control" required autocomplete="off">
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="revealPasswordAdminPassword">Senha do administrador</label>
                        <input type="password" id="revealPasswordAdminPassword" name="admin_password" class="form-control" required autocomplete="new-password">
                    </div>

                    <div id="revealPasswordError" class="alert alert-danger mt-3 d-none"></div>

                    <div id="revealPasswordResult" class="alert alert-success mt-3 d-none">
                        <strong>Senha de acesso:</strong>
                        <code id="revealPasswordValue" class="fs-5 ms-1"></code>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Fechar</button>
                    <button type="submit" class="btn btn-primary" id="revealPasswordSubmit">
                        <i class="bi bi-eye me-1"></i>Revelar senha
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
