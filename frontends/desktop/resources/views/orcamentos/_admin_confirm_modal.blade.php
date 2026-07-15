{{--
    Modal de confirmação de administrador para editar orçamento de uma OS já
    encerrada. Diferente do modal de "Cancelar baixa" (que tem form próprio e
    submete via fetch), este modal NÃO submete nada sozinho: o botão de
    confirmar copia e-mail/senha para os campos ocultos do formulário
    principal do orçamento (evita <form> aninhado) e deixa
    orcamentos-form.js reenviar o form real uma única vez.
--}}
<div class="modal fade" id="orcamentoAdminConfirmModal" tabindex="-1" aria-hidden="true" aria-labelledby="orcamentoAdminConfirmModalLabel" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-shell">
            <div class="modal-header">
                <h5 class="modal-title" id="orcamentoAdminConfirmModalLabel">Confirmação de administrador</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-warning">
                    Esta OS já está <strong>encerrada</strong> — o equipamento foi entregue e os lançamentos
                    financeiros já foram feitos. Editar o orçamento agora exige confirmação de um administrador.
                    Se o valor total mudar, o título financeiro (e os recebimentos já registrados, se necessário)
                    serão corrigidos automaticamente.
                </div>

                <p class="form-text">Informe e-mail e senha de um usuário administrador para salvar esta alteração.</p>

                <div class="mb-3">
                    <label class="form-label" for="orcamentoAdminEmail">E-mail do administrador</label>
                    <input type="email" id="orcamentoAdminEmail" class="form-control" required autocomplete="off">
                </div>
                <div class="mb-0">
                    <label class="form-label" for="orcamentoAdminPassword">Senha do administrador</label>
                    <input type="password" id="orcamentoAdminPassword" class="form-control" required autocomplete="new-password">
                </div>

                <div id="orcamentoAdminConfirmError" class="alert alert-danger mt-3 d-none"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="orcamentoAdminConfirmSubmit">
                    <i class="bi bi-shield-lock me-1"></i>Confirmar e salvar
                </button>
            </div>
        </div>
    </div>
</div>
