{{-- Criação e edição de compromisso manual. Compromissos gerados por outros
     módulos abrem em modo restrito (ver JS): data e título ficam bloqueados
     porque quem manda neles é o registro de origem. --}}
<div class="modal fade" id="agendaFormModal" tabindex="-1" aria-labelledby="agendaFormModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('agenda.store') }}" class="modal-content" id="agendaForm"
              data-update-template="{{ url('/agenda') }}/__ID__">
            @csrf
            <input type="hidden" name="_method" value="POST" id="agendaFormMethod">
            <input type="hidden" name="data" value="{{ $cursor }}">
            <input type="hidden" name="view" value="{{ $viewMode }}">

            <div class="modal-header">
                <h5 class="modal-title" id="agendaFormModalLabel">Novo compromisso</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-info d-none" id="agendaManagedNotice">
                    <i class="bi bi-info-circle me-1"></i>
                    Este compromisso é mantido automaticamente pelo módulo de origem.
                    Você pode ajustar a observação, a prioridade e o lembrete — a data e o
                    título acompanham o registro que o gerou.
                </div>

                <div class="mb-3">
                    <label for="agendaTitulo" class="form-label">Título</label>
                    <input type="text" class="form-control" id="agendaTitulo" name="titulo" maxlength="180" required
                           placeholder="Ex.: Ligar para o fornecedor sobre a peça">
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label for="agendaData" class="form-label">Data</label>
                        <input type="date" class="form-control" id="agendaData" name="data" required
                               value="{{ now()->format('Y-m-d') }}">
                    </div>
                    <div class="col-sm-6">
                        <label for="agendaHora" class="form-label">Hora <span class="text-muted">(opcional)</span></label>
                        <input type="time" class="form-control" id="agendaHora" name="hora">
                        <div class="form-text">Em branco = compromisso de dia inteiro.</div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label for="agendaPrioridade" class="form-label">Prioridade</label>
                        <select class="form-select" id="agendaPrioridade" name="prioridade">
                            <option value="baixa">Baixa</option>
                            <option value="normal" selected>Normal</option>
                            <option value="alta">Alta</option>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label for="agendaLembrete" class="form-label">Lembrete</label>
                        <select class="form-select" id="agendaLembrete" name="lembrete_minutos">
                            <option value="">Sem lembrete</option>
                            <option value="10">10 minutos antes</option>
                            <option value="30" selected>30 minutos antes</option>
                            <option value="60">1 hora antes</option>
                            <option value="120">2 horas antes</option>
                            <option value="1440">1 dia antes</option>
                            <option value="2880">2 dias antes</option>
                        </select>
                        <div class="form-text">É o que faz o celular avisar, via Google Agenda.</div>
                    </div>
                </div>

                <div class="mb-0">
                    <label for="agendaDescricao" class="form-label">Observação</label>
                    <textarea class="form-control" id="agendaDescricao" name="descricao" rows="3" maxlength="5000"
                              placeholder="Detalhes, contexto, o que precisa ser feito..."></textarea>
                </div>
            </div>

            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-outline-danger d-none" id="agendaDeleteButton">
                    <i class="bi bi-trash me-1"></i>Excluir
                </button>
                <div class="ms-auto d-flex gap-2">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- Formulário separado só para a exclusão: um <form> não pode conter outro. --}}
<form method="POST" id="agendaDeleteForm" class="d-none"
      data-destroy-template="{{ url('/agenda') }}/__ID__">
    @csrf
    @method('DELETE')
    <input type="hidden" name="data" value="{{ $cursor }}">
    <input type="hidden" name="view" value="{{ $viewMode }}">
</form>
