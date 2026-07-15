{{--
    Modal de cancelamento de título vinculado a OS encerrada — 2 telas dentro
    do mesmo modal (motivo → credenciais de admin), sem round-trip
    intermediário. Reutilizável: cada linha com "Cancelar" (ver
    financeiro/index.blade.php e financeiro/show.blade.php) abre este MESMO
    modal passando data-target-form com o seletor do form real daquela linha
    (que já tem os 3 hidden inputs: motivo, admin_email, admin_password).
    Ao confirmar a etapa 2, os hidden inputs do form-alvo são preenchidos e o
    form real é submetido — o backend não precisa saber que são "duas telas".
--}}
<div class="modal fade" id="financeiroCancelReasonModal" tabindex="-1" aria-hidden="true" aria-labelledby="financeiroCancelReasonModalLabel" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-shell">
            <div class="modal-header">
                <h5 class="modal-title" id="financeiroCancelReasonModalLabel">Cancelar lançamento de OS encerrada</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-warning">
                    Esta OS já está <strong>encerrada</strong>. Cancelar este lançamento exige informar o motivo e
                    confirmar com credenciais de administrador.
                </div>

                {{-- Etapa 1: motivo --}}
                <div data-cancel-reason-step="motivo">
                    <p class="form-text">Qual o motivo do cancelamento?</p>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="radio" name="cancel_reason_motivo" id="cancelReasonSemReparo" value="sem_reparo">
                        <label class="form-check-label" for="cancelReasonSemReparo">
                            <strong>Reparo sem sucesso, entregue ao cliente</strong>
                            <div class="small text-secondary">A OS será reclassificada como "Devolvido Sem Reparo" — esse encerramento nunca gera cobrança.</div>
                        </label>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="radio" name="cancel_reason_motivo" id="cancelReasonErroCobranca" value="erro_cobranca">
                        <label class="form-check-label" for="cancelReasonErroCobranca">
                            <strong>Erro de cobrança</strong>
                            <div class="small text-secondary">A OS volta a "Entregue - Pendência Financeira" e as cobranças automáticas antigas são canceladas.</div>
                        </label>
                    </div>

                    <div class="form-check mb-0">
                        <input class="form-check-input" type="radio" name="cancel_reason_motivo" id="cancelReasonFechamentoIndevido" value="fechamento_indevido">
                        <label class="form-check-label" for="cancelReasonFechamentoIndevido">
                            <strong>Fechamento inadvertido</strong>
                            <div class="small text-secondary">A baixa inteira foi um engano — a OS volta ao status anterior ao fechamento (mesma ação de "Cancelar baixa").</div>
                        </label>
                    </div>

                    <div id="financeiroCancelReasonError" class="alert alert-danger mt-3 d-none"></div>
                </div>

                {{-- Etapa 2: credenciais de admin --}}
                <div data-cancel-reason-step="admin" class="d-none">
                    <p class="form-text">Para concretizar o cancelamento, informe e-mail e senha de um usuário administrador.</p>

                    <div class="mb-3">
                        <label class="form-label" for="financeiroCancelAdminEmail">E-mail do administrador</label>
                        <input type="email" id="financeiroCancelAdminEmail" class="form-control" required autocomplete="off">
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="financeiroCancelAdminPassword">Senha do administrador</label>
                        <input type="password" id="financeiroCancelAdminPassword" class="form-control" required autocomplete="new-password">
                    </div>

                    <div id="financeiroCancelAdminError" class="alert alert-danger mt-3 d-none"></div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-outline-light d-none" id="financeiroCancelReasonBack">Voltar</button>
                <button type="button" class="btn btn-primary" id="financeiroCancelReasonNext">Avançar</button>
                <button type="button" class="btn btn-danger d-none" id="financeiroCancelReasonSubmit">
                    <i class="bi bi-x-circle me-1"></i>Confirmar cancelamento
                </button>
            </div>
        </div>
    </div>
</div>
