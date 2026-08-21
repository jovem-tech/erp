{{--
    Confirmação de administrador para cancelar a baixa de uma fatura de cartão.

    Diferente do modal de excluir lançamento (financeiro/_delete_admin_modal),
    aqui o <form> mora DENTRO do modal e é submetido nativamente — não precisa
    de JS. Aquele modal é compartilhado por N linhas de uma tabela, então
    depende de data-target-form + script para preencher os hidden inputs do
    form certo; nesta tela a ação é uma só, da própria fatura aberta.

    Espera $cartaoId e $dataVencimento no escopo.
--}}
<div class="modal fade" id="cancelarBaixaFaturaModal" tabindex="-1" aria-hidden="true"
     aria-labelledby="cancelarBaixaFaturaModalLabel" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-shell">
            <form method="post"
                  action="{{ route('financeiro.cartoes-credito.faturas.cancelar-baixa', [
                      'cartaoCredito' => $cartaoId,
                      'dataVencimento' => $dataVencimento,
                  ]) }}">
                @csrf

                <div class="modal-header">
                    <h5 class="modal-title" id="cancelarBaixaFaturaModalLabel">Cancelar baixa da fatura</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>

                <div class="modal-body">
                    <div class="alert alert-warning">
                        As despesas desta fatura <strong>voltam a ficar pendentes</strong> e o lançamento do
                        pagamento é cancelado. A fatura fica aberta de novo e pode ser paga outra vez.
                        Confirme com e-mail e senha de um usuário administrador.
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="cancelarBaixaFaturaAdminEmail">E-mail do administrador</label>
                        <input type="email"
                               id="cancelarBaixaFaturaAdminEmail"
                               name="admin_email"
                               class="form-control"
                               required
                               autocomplete="off">
                    </div>

                    <div class="mb-0">
                        <label class="form-label" for="cancelarBaixaFaturaAdminPassword">Senha do administrador</label>
                        <input type="password"
                               id="cancelarBaixaFaturaAdminPassword"
                               name="admin_password"
                               class="form-control"
                               required
                               autocomplete="new-password">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Voltar</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Confirmar cancelamento
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
