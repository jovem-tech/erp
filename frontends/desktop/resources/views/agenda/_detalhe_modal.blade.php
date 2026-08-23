{{-- Detalhe do compromisso: o que é, de onde veio e para onde leva. --}}
<div class="modal fade" id="agendaDetalheModal" tabindex="-1" aria-labelledby="agendaDetalheLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="agendaDetalheLabel">Compromisso</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <span class="badge rounded-pill text-bg-secondary mb-2" id="agendaDetalheTipo"></span>
                <h4 class="surface-title fs-5 mb-2" id="agendaDetalheTitulo"></h4>
                <p class="surface-subtitle mb-3" id="agendaDetalheQuando"></p>
                <p class="mb-3" id="agendaDetalheDescricao"></p>

                <div class="d-flex flex-wrap gap-2" id="agendaDetalheLinks"></div>
            </div>

            <div class="modal-footer justify-content-between">
                <div id="agendaDetalheStatus" class="text-muted small"></div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-light" id="agendaDetalheEditar">
                        <i class="bi bi-pencil me-1"></i>Editar
                    </button>
                    <form method="POST" id="agendaDetalheToggleForm" class="d-inline"
                          data-complete-template="{{ url('/agenda') }}/__ID__/concluir"
                          data-reopen-template="{{ url('/agenda') }}/__ID__/reabrir">
                        @csrf
                        <input type="hidden" name="data" value="{{ $cursor }}">
                        <input type="hidden" name="view" value="{{ $viewMode }}">
                        <button type="submit" class="btn btn-primary" id="agendaDetalheToggleButton"></button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
