<div class="modal fade" id="docShareModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-shell">
            <div class="modal-header">
                <h5 class="modal-title">Gerar link público</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div id="docShareModalFormWrap">
                    <p class="text-secondary small mb-3" id="docShareModalCount">Nenhum documento selecionado.</p>

                    <div class="alert-shell alert-shell-danger d-none mb-3" id="docShareModalErrors"></div>

                    <form id="docShareModalForm">
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label class="form-label">Formato</label>
                                <select name="format" class="form-select" data-select2="false">
                                    <option value="a4">A4</option>
                                    <option value="80mm">80mm</option>
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">Expiração</label>
                                <select name="expiracao" class="form-select" data-select2="false">
                                    @foreach ($shareExpirationOptions as $expiration)
                                        <option value="{{ $expiration }}">{{ $expiration }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </form>
                </div>

                <div id="docShareModalResult" class="d-none">
                    <div class="alert-shell mb-3">
                        Guarde este link agora — por segurança ele não será exibido novamente.
                    </div>
                    <label class="form-label">URL pública</label>
                    <div class="d-flex gap-2">
                        <input type="text" class="form-control" id="docShareModalUrl" readonly onclick="this.select()">
                        <button type="button" class="btn btn-outline-light" data-doc-share-copy>
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                    <p class="text-secondary small mb-0 mt-2" id="docShareModalExpires"></p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal" id="docShareModalCancel">Cancelar</button>
                <button type="submit" form="docShareModalForm" class="btn btn-primary" id="docShareModalSubmit">
                    Gerar link
                </button>
            </div>
        </div>
    </div>
</div>
