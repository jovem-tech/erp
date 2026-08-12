{{-- Abertura de caixa — specs/028-caixa-sessoes. --}}
<div class="modal fade" id="caixaAbrirModal" tabindex="-1" aria-labelledby="caixaAbrirModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" action="{{ route('caixa.open') }}" class="modal-content">
            @csrf

            <div class="modal-header">
                <h5 class="modal-title" id="caixaAbrirModalLabel">Abrir caixa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <div class="mb-3">
                    <label for="caixaAbrirValor" class="form-label">Troco na gaveta</label>
                    <input
                        type="text"
                        id="caixaAbrirValor"
                        name="valor_abertura"
                        class="form-control form-control-lg text-end @error('valor_abertura') is-invalid @enderror"
                        value="{{ old('valor_abertura', '0,00') }}"
                        inputmode="decimal"
                        required
                        autofocus
                    >
                    <small class="text-secondary">Conte o dinheiro que está na gaveta agora, antes da primeira venda.</small>
                    @error('valor_abertura')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div>
                    <label for="caixaAbrirObs" class="form-label">Observações</label>
                    <textarea id="caixaAbrirObs" name="observacoes" class="form-control" rows="2" maxlength="2000">{{ old('observacoes') }}</textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-unlock me-2"></i>Abrir caixa
                </button>
            </div>
        </form>
    </div>
</div>
