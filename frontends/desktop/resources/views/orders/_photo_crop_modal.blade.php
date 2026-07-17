<div
    class="modal fade"
    id="orderPhotoCropModal"
    tabindex="-1"
    aria-labelledby="orderPhotoCropModalLabel"
    aria-describedby="orderPhotoCropModalDescription"
    aria-hidden="true"
    data-bs-backdrop="static"
    data-bs-keyboard="false"
>
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content modal-shell">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5" id="orderPhotoCropModalLabel">Cortar foto de entrada</h2>
                    <p class="surface-subtitle mb-0" id="orderPhotoCropModalDescription">Arraste a imagem e ajuste a área que deve permanecer na OS.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cancelar corte"></button>
            </div>

            <div class="modal-body">
                <div class="d-flex flex-wrap gap-2 mb-3" role="toolbar" aria-label="Ferramentas de edição da foto">
                    <button type="button" class="btn btn-outline-light btn-sm" data-order-photo-crop-action="rotate-left" title="Girar para a esquerda">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Girar à esquerda
                    </button>
                    <button type="button" class="btn btn-outline-light btn-sm" data-order-photo-crop-action="rotate-right" title="Girar para a direita">
                        <i class="bi bi-arrow-clockwise me-1"></i>Girar à direita
                    </button>
                    <button type="button" class="btn btn-outline-light btn-sm" data-order-photo-crop-action="zoom-in" title="Aumentar zoom">
                        <i class="bi bi-zoom-in me-1"></i>Ampliar
                    </button>
                    <button type="button" class="btn btn-outline-light btn-sm" data-order-photo-crop-action="zoom-out" title="Diminuir zoom">
                        <i class="bi bi-zoom-out me-1"></i>Reduzir
                    </button>
                    <button type="button" class="btn btn-outline-light btn-sm" data-order-photo-crop-action="reset" title="Restaurar enquadramento">
                        <i class="bi bi-arrow-repeat me-1"></i>Restaurar
                    </button>
                </div>

                <img src="" alt="Imagem selecionada para corte" id="orderPhotoCropImage" class="equipment-crop-image">
            </div>

            <div class="modal-footer">
                <span class="text-secondary small me-auto">O recorte será convertido para JPEG e limitado a 2 MB.</span>
                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" data-order-photo-crop-confirm disabled>
                    <i class="bi bi-check2-circle me-2"></i>Usar recorte
                </button>
            </div>
        </div>
    </div>
</div>
