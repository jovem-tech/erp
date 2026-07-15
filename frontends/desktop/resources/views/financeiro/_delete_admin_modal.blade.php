{{--
    Modal de confirmação de administrador para excluir um lançamento
    financeiro. Diferente do "Cancelar" (que preserva o histórico), excluir é
    um hard delete irreversível — por isso exige credenciais de admin sempre,
    não só quando a OS vinculada está encerrada (nesse caso a exclusão é
    bloqueada por completo, ver financeiro/index.blade.php e show.blade.php).
    Reutilizável: cada linha com "Excluir" abre este MESMO modal passando
    data-target-form com o seletor do form real daquela linha (que já tem os
    2 hidden inputs: admin_email, admin_password).
--}}
<div class="modal fade" id="financeiroDeleteAdminModal" tabindex="-1" aria-hidden="true" aria-labelledby="financeiroDeleteAdminModalLabel" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-shell">
            <div class="modal-header">
                <h5 class="modal-title" id="financeiroDeleteAdminModalLabel">Excluir lançamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-warning">
                    Esta ação <strong>não pode ser desfeita</strong> — o lançamento é removido por completo (diferente
                    de "Cancelar", que mantém o registro). Confirme com e-mail e senha de um usuário administrador.
                </div>

                <div class="mb-3">
                    <label class="form-label" for="financeiroDeleteAdminEmail">E-mail do administrador</label>
                    <input type="email" id="financeiroDeleteAdminEmail" class="form-control" required autocomplete="off">
                </div>
                <div class="mb-0">
                    <label class="form-label" for="financeiroDeleteAdminPassword">Senha do administrador</label>
                    <input type="password" id="financeiroDeleteAdminPassword" class="form-control" required autocomplete="new-password">
                </div>

                <div id="financeiroDeleteAdminError" class="alert alert-danger mt-3 d-none"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="financeiroDeleteAdminSubmit">
                    <i class="bi bi-trash me-1"></i>Confirmar exclusão
                </button>
            </div>
        </div>
    </div>
</div>
