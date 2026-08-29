{{--
    Aplicação de peças na OS — specs/038.

    O modal vem PRÉ-PREENCHIDO com o que falta aplicar do orçamento aprovado.
    Esse detalhe decide a adoção: se o técnico tiver que digitar tudo de novo,
    ele não usa, e o CMV continua zero.
--}}
<div class="modal fade" id="osAplicarPecasModal" tabindex="-1" aria-labelledby="osAplicarPecasModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="osAplicarPecasModalLabel">Aplicar peças do orçamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <p class="surface-subtitle">
                    Confirme o que saiu do estoque para esta OS. É isso que faz o custo real
                    da peça aparecer na margem e no encerramento.
                </p>

                <div class="alert alert-danger d-none" id="osAplicarPecasErro"></div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                        <tr>
                            <th>Peça</th>
                            <th class="text-end">Orçada</th>
                            <th class="text-end">Já baixada</th>
                            <th class="text-end">Saldo</th>
                            <th class="text-end" style="width: 8rem;">Aplicar</th>
                        </tr>
                        </thead>
                        <tbody id="osAplicarPecasCorpo"></tbody>
                    </table>
                </div>

                <div class="form-check mt-2 d-none" id="osAplicarPecasConfirmarBox">
                    <input class="form-check-input" type="checkbox" id="osAplicarPecasConfirmar">
                    <label class="form-check-label small" for="osAplicarPecasConfirmar">
                        Aplicar mesmo sem saldo suficiente. O estoque fica negativo — é o sinal
                        de que o inventário precisa de acerto.
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="osAplicarPecasSalvar">Aplicar peças</button>
            </div>
        </div>
    </div>
</div>
