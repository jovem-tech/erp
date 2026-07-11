<div class="modal fade" id="desktopPhotoViewerModal" tabindex="-1" aria-labelledby="desktopPhotoViewerTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl photo-viewer-modal-dialog">
        <div class="modal-content modal-shell">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="desktopPhotoViewerTitle">Visualizar foto</h5>
                    <p class="photo-viewer-counter" id="desktopPhotoViewerCounter">1 de 1</p>
                </div>

                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <a href="#"
                        id="desktopPhotoViewerOpenFull"
                        class="btn btn-outline-light btn-sm"
                        target="_blank"
                        rel="noreferrer">
                        <i class="bi bi-box-arrow-up-right me-2"></i>
                        Abrir original
                    </a>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
            </div>

            <div class="modal-body photo-viewer-modal-body">
                <button type="button"
                    class="btn btn-outline-light photo-viewer-nav"
                    id="desktopPhotoViewerPrev"
                    data-photo-viewer-direction="prev"
                    aria-label="Foto anterior">
                    <i class="bi bi-chevron-left"></i>
                </button>

                <div class="photo-viewer-stage">
                    <img src="" alt="Foto ampliada" id="desktopPhotoViewerImage" class="photo-viewer-image">
                </div>

                <button type="button"
                    class="btn btn-outline-light photo-viewer-nav"
                    id="desktopPhotoViewerNext"
                    data-photo-viewer-direction="next"
                    aria-label="Próxima foto">
                    <i class="bi bi-chevron-right"></i>
                </button>
            </div>
        </div>
    </div>
</div>
