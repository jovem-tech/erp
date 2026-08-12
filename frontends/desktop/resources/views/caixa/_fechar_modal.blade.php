{{--
    Fechamento cego — specs/028-caixa-sessoes.

    Nenhum valor esperado aparece nesta tela. O operador conta a gaveta e digita
    o que encontrou; o comparativo só é revelado depois de confirmar. Mostrar o
    esperado antes transformaria a conferência em "digitar o número que o
    sistema quer" e o controle perderia o sentido.
--}}
@php $sessaoId = (int) ($sessao['id'] ?? 0); @endphp

<div class="modal fade" id="caixaFecharModal" tabindex="-1" aria-labelledby="caixaFecharModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" action="{{ route('caixa.close', $sessaoId) }}" class="modal-content">
            @csrf

            <div class="modal-header">
                <h5 class="modal-title" id="caixaFecharModalLabel">Fechar caixa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    Conte todo o dinheiro da gaveta e informe o total. A comparação com
                    o esperado aparece só depois de confirmar.
                </div>

                <div class="mb-3">
                    <label for="caixaFecharValor" class="form-label">Valor contado na gaveta</label>
                    <input
                        type="text"
                        id="caixaFecharValor"
                        name="valor_informado"
                        class="form-control form-control-lg text-end @error('valor_informado') is-invalid @enderror"
                        value="{{ old('valor_informado', '0,00') }}"
                        inputmode="decimal"
                        required
                        autofocus
                    >
                    @error('valor_informado')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div>
                    <label for="caixaFecharObs" class="form-label">Observações</label>
                    <textarea
                        id="caixaFecharObs"
                        name="observacoes"
                        class="form-control"
                        rows="2"
                        maxlength="2000"
                        placeholder="Ex.: faltou troco, cliente pagou a mais"
                    >{{ old('observacoes') }}</textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-lock me-2"></i>Confirmar contagem
                </button>
            </div>
        </form>
    </div>
</div>
