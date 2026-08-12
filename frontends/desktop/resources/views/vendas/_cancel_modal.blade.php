{{--
    Cancelamento de venda — specs/027-vendas-balcao-pdv.

    Cancelar estorna estoque e dinheiro, então o motivo é sempre obrigatório.
    Venda de outra data mexe em caixa já conferido: aí exige credencial de
    administrador (mesmo step-up do cancelamento de título financeiro).
--}}
@php
    $vendaId = (int) ($venda['id'] ?? 0);
    $exigeAdmin = (bool) ($exigeAdmin ?? false);
@endphp

<div class="modal fade" id="vendaCancelModal" tabindex="-1" aria-labelledby="vendaCancelModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" action="{{ route('vendas.cancel', $vendaId) }}" class="modal-content">
            @csrf

            <div class="modal-header">
                <h5 class="modal-title" id="vendaCancelModalLabel">
                    Cancelar venda {{ $venda['numero'] ?? '' }}
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-warning">
                    Os produtos voltam ao estoque e o título financeiro é cancelado,
                    com estorno dos recebimentos já lançados.
                </div>

                <div class="mb-3">
                    <label for="vendaCancelMotivo" class="form-label">Motivo do cancelamento</label>
                    <textarea
                        id="vendaCancelMotivo"
                        name="motivo"
                        class="form-control @error('motivo') is-invalid @enderror"
                        rows="3"
                        minlength="5"
                        maxlength="2000"
                        required
                        placeholder="Ex.: cliente desistiu da compra"
                    >{{ old('motivo') }}</textarea>
                    @error('motivo')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                @if ($exigeAdmin)
                    <div class="border rounded p-3">
                        <p class="small text-secondary mb-3">
                            Esta venda não é do dia de hoje. Confirme com as credenciais de um administrador.
                        </p>

                        <div class="mb-2">
                            <label for="vendaCancelAdminEmail" class="form-label">E-mail do administrador</label>
                            <input type="email" id="vendaCancelAdminEmail" name="admin_email" class="form-control" autocomplete="off" required>
                        </div>

                        <div>
                            <label for="vendaCancelAdminPassword" class="form-label">Senha</label>
                            <input type="password" id="vendaCancelAdminPassword" name="admin_password" class="form-control" autocomplete="new-password" required>
                        </div>
                    </div>
                @endif
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Voltar</button>
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-x-circle me-2"></i>Confirmar cancelamento
                </button>
            </div>
        </form>
    </div>
</div>
