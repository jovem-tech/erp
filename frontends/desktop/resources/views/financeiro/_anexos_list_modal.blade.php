{{--
    Modal único de "Ver anexos" para a listagem de lançamentos — permite
    conferir/abrir os arquivos de uma linha sem sair para a tela de detalhe.
    Cada botão de linha só passa data-financeiro-id/data-financeiro-descricao;
    o JS (financeiro-anexos.js) busca a lista via fetch() em
    financeiro.anexos.index e monta as linhas. Somente leitura de propósito
    (ver + abrir em nova aba) — anexar/excluir continuam exigindo a tela de
    detalhe ou a ação "Anexar arquivo" da própria listagem.
--}}
<div class="modal fade" id="anexosListModal" tabindex="-1" aria-hidden="true"
     data-url-template="{{ route('financeiro.anexos.index', ['financeiro' => '__FINANCEIRO_ID__']) }}">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" data-anexos-list-title>Anexos</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div data-anexos-list-body>
                    <p class="text-secondary mb-0">Carregando...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>
