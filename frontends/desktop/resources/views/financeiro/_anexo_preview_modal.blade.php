{{--
    Modal de pré-visualização, compartilhado entre a tela de detalhe
    (financeiro/show.blade.php) e a listagem (financeiro/_lancamentos_table.blade.php,
    via a lista de anexos aberta pelo "Ver anexos"). Cada botão-olho só passa
    data-anexo-url/mime/nome — o JS (financeiro-anexos.js) monta um <iframe>
    para PDF ou <img> para imagem dentro do modal, sem sair da página.
--}}
<div class="modal fade" id="anexoPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" data-anexo-preview-title>Visualizar anexo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" data-anexo-preview-body></div>
            <div class="modal-footer">
                <a href="" target="_blank" rel="noreferrer" class="btn btn-outline-light btn-sm me-auto" data-anexo-preview-open-link>
                    <i class="bi bi-box-arrow-up-right me-1"></i>Abrir em nova aba
                </a>
                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>
