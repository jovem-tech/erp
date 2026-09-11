{{--
    Modal único de "Anexar arquivo" para a listagem de lançamentos — evita
    renderizar um modal por linha (a tabela pode ter dezenas). Cada botão de
    linha só passa data-financeiro-id/data-financeiro-descricao; o JS
    (financeiro-anexos.js) reescreve a action do form e o título no
    show.bs.modal, e reseta tudo no hidden.bs.modal (senão a segunda linha
    usada herda o arquivo/estado da anterior). O template de URL usa um
    placeholder porque route() só existe no servidor — o id real da linha só
    é conhecido no clique, no navegador.
--}}
<div class="modal fade" id="anexoQuickModal" tabindex="-1" aria-hidden="true"
     data-url-template="{{ route('financeiro.anexos.store', ['financeiro' => '__FINANCEIRO_ID__']) }}">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="" enctype="multipart/form-data" data-no-page-loader="true" data-form-anexo-financeiro>
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" data-anexo-quick-title>Anexar arquivo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Arquivo (PDF ou foto)</label>
                        <input type="file" name="arquivo" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp" required data-input-anexo-financeiro>
                    </div>
                    <div>
                        <label class="form-label">Descrição (opcional)</label>
                        <input type="text" name="descricao" class="form-control" maxlength="190" placeholder="Ex.: Boleto setembro/2026">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" disabled data-botao-anexo-financeiro>
                        <i class="bi bi-upload me-1"></i>Anexar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
