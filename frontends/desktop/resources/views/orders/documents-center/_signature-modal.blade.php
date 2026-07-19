<div class="modal fade" id="documentSignatureModal" tabindex="-1" aria-labelledby="documentSignatureModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5" id="documentSignatureModalLabel">Responsabilidade e assinatura</h2>
                    <p class="text-secondary small mb-0">A pessoa que assinar ficará registrada separadamente do usuário da sessão.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="d-grid gap-3">
                    <label class="signature-choice">
                        <input type="radio" name="document_signature_mode" value="self" checked>
                        <span><strong>Assinar como eu</strong><small>Usa sua assinatura cadastrada e registra a sessão atual.</small></span>
                    </label>
                    <label class="signature-choice">
                        <input type="radio" name="document_signature_mode" value="client">
                        <span><strong>Enviar para o cliente assinar</strong><small>Cria um link seguro para o cliente rubricar pelo celular, tablet ou iPad.</small></span>
                    </label>
                    <label class="signature-choice">
                        <input type="radio" name="document_signature_mode" value="reauth">
                        <span><strong>Assinar como outro usuário agora</strong><small>Exige e-mail e senha do signatário, sem trocar a sessão aberta.</small></span>
                    </label>
                    <label class="signature-choice">
                        <input type="radio" name="document_signature_mode" value="pending">
                        <span><strong>Encaminhar para assinatura</strong><small>O documento só será emitido quando o responsável assinar.</small></span>
                    </label>

                    <div class="d-none" data-signature-user-fields>
                        <label for="documentSignatureUser">Usuário responsável</label>
                        <select id="documentSignatureUser" class="form-select" data-select2="false" data-signature-user>
                            <option value="">Selecione</option>
                            @foreach ($signatureUsers as $signatureUser)
                                <option value="{{ (int) ($signatureUser['id'] ?? 0) }}"
                                    data-email="{{ $signatureUser['email'] ?? '' }}"
                                    data-registered="{{ ($signatureUser['signature_registered'] ?? false) ? '1' : '0' }}"
                                    {{ ($signatureUser['signature_registered'] ?? false) ? '' : 'disabled' }}>
                                    {{ $signatureUser['name'] ?? 'Usuário' }}{{ ($signatureUser['signature_registered'] ?? false) ? '' : ' — sem assinatura' }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="d-none" data-signature-credentials>
                        <div class="mb-3">
                            <label for="documentSignatureEmail">E-mail do signatário</label>
                            <input type="email" id="documentSignatureEmail" class="form-control" autocomplete="username" data-signature-email>
                        </div>
                        <div>
                            <label for="documentSignaturePassword">Senha do signatário</label>
                            <input type="password" id="documentSignaturePassword" class="form-control" maxlength="200" autocomplete="current-password" data-signature-password>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" data-signature-confirm><i class="bi bi-shield-check me-2"></i>Continuar</button>
            </div>
        </div>
    </div>
</div>

<style>
    .signature-choice { display: grid; grid-template-columns: auto 1fr; gap: 12px; padding: 14px; border: 1px solid #d7e2f2; border-radius: 14px; cursor: pointer; }
    .signature-choice input { margin-top: 4px; }
    .signature-choice span, .signature-choice small { display: block; }
    .signature-choice small { color: #71819b; margin-top: 2px; }
</style>
