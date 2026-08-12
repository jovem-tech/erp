{{--
    Correção do valor de abertura — specs/028-caixa-sessoes.

    Existe por causa da abertura automática: ela herda o fechamento anterior, que
    pode não bater com o que está fisicamente na gaveta.
--}}
@php $sessaoId = (int) ($sessao['id'] ?? 0); @endphp

<div class="modal fade" id="caixaAberturaModal" tabindex="-1" aria-labelledby="caixaAberturaModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" action="{{ route('caixa.opening.update', $sessaoId) }}" class="modal-content">
            @csrf
            @method('PATCH')

            <div class="modal-header">
                <h5 class="modal-title" id="caixaAberturaModalLabel">Corrigir valor de abertura</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <p class="text-secondary small">
                    Informe o troco que realmente estava na gaveta quando o turno começou.
                    O saldo da conta de caixa é ajustado na mesma medida.
                </p>

                <label for="caixaAberturaValor" class="form-label">Valor de abertura</label>
                <input
                    type="text"
                    id="caixaAberturaValor"
                    name="valor_abertura"
                    class="form-control form-control-lg text-end"
                    value="{{ number_format((float) ($sessao['valor_abertura'] ?? 0), 2, ',', '.') }}"
                    inputmode="decimal"
                    required
                >
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>
