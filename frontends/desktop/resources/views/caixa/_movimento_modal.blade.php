{{--
    Sangria ou suprimento — specs/028-caixa-sessoes.

    Parâmetros: $id, $tipo, $titulo, $descricao, $sessao, $contasDestino.
    A conta de destino só aparece na sangria: é para onde o dinheiro foi.
--}}
@php
    $sessaoId = (int) ($sessao['id'] ?? 0);
    $contasDestino = is_array($contasDestino ?? null) ? $contasDestino : [];
@endphp

<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-labelledby="{{ $id }}Label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" action="{{ route('caixa.movements.store', $sessaoId) }}" class="modal-content">
            @csrf
            <input type="hidden" name="tipo" value="{{ $tipo }}">

            <div class="modal-header">
                <h5 class="modal-title" id="{{ $id }}Label">{{ $titulo }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <p class="text-secondary small">{{ $descricao }}</p>

                <div class="mb-3">
                    <label for="{{ $id }}Valor" class="form-label">Valor</label>
                    <input
                        type="text"
                        id="{{ $id }}Valor"
                        name="valor"
                        class="form-control form-control-lg text-end"
                        value="0,00"
                        inputmode="decimal"
                        required
                    >
                </div>

                <div class="mb-3">
                    <label for="{{ $id }}Motivo" class="form-label">Motivo</label>
                    <input
                        type="text"
                        id="{{ $id }}Motivo"
                        name="motivo"
                        class="form-control"
                        minlength="3"
                        maxlength="255"
                        required
                        placeholder="{{ $tipo === 'sangria' ? 'Ex.: depósito bancário' : 'Ex.: reforço de troco' }}"
                    >
                </div>

                @if ($tipo === 'sangria' && $contasDestino !== [])
                    <div>
                        <label for="{{ $id }}Destino" class="form-label">Conta de destino</label>
                        <select id="{{ $id }}Destino" name="conta_destino_id" class="form-select">
                            <option value="">Só retirar da gaveta</option>
                            @foreach ($contasDestino as $conta)
                                <option value="{{ $conta['id'] ?? '' }}">{{ $conta['nome'] ?? '' }}</option>
                            @endforeach
                        </select>
                        <small class="text-secondary">Com destino, o sistema registra a transferência entre as contas.</small>
                    </div>
                @endif
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Registrar</button>
            </div>
        </form>
    </div>
</div>
