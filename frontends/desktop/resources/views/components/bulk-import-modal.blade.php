@props([
    'id' => 'bulkImportModal',
    'title' => 'Importação em lote',
    'action' => '',
    'description' => 'Envie um CSV com a mesma estrutura do modelo para cadastrar vários registros de uma vez.',
    'fieldName' => 'arquivo',
])

{{--
    Modal padrão de importação em lote — substitui o card "Importação em lote"
    que antes ficava sempre visível abaixo dos filtros. Aberto pelo item
    "Importar em lote" do dropdown "Mais ações" do cabeçalho da listagem.
--}}
<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-shell">
                <div class="modal-header">
                    <h2 class="modal-title">{{ $title }}</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <form method="post" action="{{ $action }}" enctype="multipart/form-data">
                    @csrf
                    <div class="modal-body">
                        <p class="surface-subtitle">{{ $description }}</p>
                        <label for="{{ $id }}-arquivo">Arquivo CSV</label>
                        <input type="file" id="{{ $id }}-arquivo" name="{{ $fieldName }}" class="form-control" accept=".csv,.txt">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-upload me-2"></i>
                            Importar lote
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
