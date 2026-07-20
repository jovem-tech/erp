<div class="modal fade file-preview-modal" id="filePreviewModal" tabindex="-1" aria-labelledby="filePreviewModalLabel" aria-hidden="true" data-file-preview-modal>
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-lg-down">
        <div class="modal-content border-0 shadow-lg" data-file-preview-fullscreen-target>
            <div class="modal-header file-preview-modal-header">
                <div class="min-w-0">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="badge text-bg-primary" data-file-preview-type>Arquivo</span>
                        <span class="small text-secondary" data-file-preview-mime></span>
                    </div>
                    <h2 class="modal-title fs-5 text-truncate" id="filePreviewModalLabel" data-file-preview-title>Visualizar arquivo</h2>
                </div>
                <button type="button" class="btn-close ms-3" data-bs-dismiss="modal" aria-label="Fechar visualizador"></button>
            </div>

            <div class="file-preview-toolbar" role="toolbar" aria-label="Ferramentas de visualizaÃ§Ã£o">
                <div class="btn-group btn-group-sm d-none" role="group" aria-label="Controles da foto" data-file-preview-image-tools>
                    <button type="button" class="btn btn-soft" data-file-preview-action="zoom-out" title="Diminuir zoom" aria-label="Diminuir zoom"><i class="bi bi-zoom-out"></i></button>
                    <button type="button" class="btn btn-soft file-preview-zoom-label" data-file-preview-action="reset" title="Tamanho original" aria-label="Restaurar tamanho original"><span data-file-preview-zoom>100%</span></button>
                    <button type="button" class="btn btn-soft" data-file-preview-action="zoom-in" title="Aumentar zoom" aria-label="Aumentar zoom"><i class="bi bi-zoom-in"></i></button>
                    <button type="button" class="btn btn-soft" data-file-preview-action="fit" title="Ajustar foto Ã  janela" aria-label="Ajustar foto Ã  janela"><i class="bi bi-arrows-angle-contract"></i></button>
                    <button type="button" class="btn btn-soft" data-file-preview-action="rotate-left" title="Girar para a esquerda" aria-label="Girar para a esquerda"><i class="bi bi-arrow-counterclockwise"></i></button>
                    <button type="button" class="btn btn-soft" data-file-preview-action="rotate-right" title="Girar para a direita" aria-label="Girar para a direita"><i class="bi bi-arrow-clockwise"></i></button>
                </div>

                <div class="file-preview-pdf-help d-none" data-file-preview-pdf-tools>
                    <i class="bi bi-file-earmark-pdf text-danger" aria-hidden="true"></i>
                    <span>Use a barra do leitor para pÃ¡ginas, zoom, rotaÃ§Ã£o e impressÃ£o.</span>
                    <button type="button" class="btn btn-soft btn-sm" data-file-preview-action="reload" title="Recarregar PDF"><i class="bi bi-arrow-repeat me-1"></i>Recarregar</button>
                </div>

                <div class="d-flex align-items-center gap-1 ms-auto">
                    <button type="button" class="btn btn-soft btn-sm" data-file-preview-action="fullscreen" title="Tela cheia" aria-label="Abrir em tela cheia"><i class="bi bi-arrows-fullscreen"></i></button>
                    <a href="#" class="btn btn-primary btn-sm" data-file-preview-download><i class="bi bi-download me-1"></i>Baixar</a>
                </div>
            </div>

            <div class="modal-body file-preview-modal-body p-0">
                <div class="file-preview-loading" data-file-preview-loading role="status" aria-live="polite">
                    <span class="spinner-border text-primary" aria-hidden="true"></span>
                    <span>Carregando visualizaÃ§Ã£o...</span>
                </div>
                <div class="file-preview-error d-none" data-file-preview-error role="alert">
                    <i class="bi bi-exclamation-triangle fs-2" aria-hidden="true"></i>
                    <strong>NÃ£o foi possÃ­vel exibir este arquivo.</strong>
                    <span>VocÃª ainda pode tentar baixÃ¡-lo.</span>
                </div>
                <div class="file-preview-image-stage d-none" data-file-preview-image-stage tabindex="0" aria-label="Visualizador da foto">
                    <div class="file-preview-image-canvas" data-file-preview-image-canvas>
                        <img src="" alt="" draggable="false" data-file-preview-image>
                    </div>
                </div>
                <iframe
                    id="filePreviewFrame"
                    class="file-preview-frame d-none"
                    src="about:blank"
                    title="Visualizador de PDF"
                    loading="lazy"
                    referrerpolicy="no-referrer"
                    allow="fullscreen"
                    data-file-preview-frame
                ></iframe>
            </div>
        </div>
    </div>
</div>
