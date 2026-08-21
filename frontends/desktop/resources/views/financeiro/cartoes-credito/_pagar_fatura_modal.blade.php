{{--
    Modal "Marcar fatura como paga", usado tanto na listagem de faturas quanto
    no detalhe de uma fatura. Fica num partial para as duas telas nunca
    divergirem nos campos que alimentam a baixa em lote.

    Espera: $cartaoId, $cartao, $dataVencimento, $valorEmAberto e
    $accountDataset. O id do modal é derivado do vencimento porque a listagem
    renderiza um por fatura elegível.
--}}
@php
    $modalId = 'pagarFaturaModal' . preg_replace('/\D/', '', (string) $dataVencimento);
    $money = static fn ($value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
    $dataFmt = $dataVencimento ? date('d/m/Y', strtotime((string) $dataVencimento)) : '—';
@endphp

<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST"
              action="{{ route('financeiro.cartoes-credito.faturas.pagar', ['cartaoCredito' => $cartaoId, 'dataVencimento' => $dataVencimento]) }}"
              class="modal-content">
            @csrf
            <div class="modal-header">
                <div>
                    <h5 class="modal-title">Marcar fatura como paga</h5>
                    <small class="text-body-secondary">Fatura de {{ $dataFmt }} — {{ $cartao['nome'] ?? 'cartão' }}</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <strong>{{ $money($valorEmAberto) }}</strong> em aberto serão baixados de uma vez —
                    todas as despesas (fixas e variáveis) desta fatura serão marcadas como pagas.
                </div>
                <div class="mb-3">
                    <label class="form-label">Data do pagamento</label>
                    <input type="date" name="data_pagamento" value="{{ now()->toDateString() }}" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label">Como a fatura foi paga</label>
                    <select name="forma_pagamento" class="form-select">
                        <option value="">Não informar</option>
                        <option value="pix">Pix</option>
                        <option value="transferencia">Transferência</option>
                        <option value="boleto">Boleto</option>
                        <option value="dinheiro">Dinheiro</option>
                        <option value="cartao_debito">Cartão de débito</option>
                    </select>
                </div>
                {{-- Pagar fatura é sempre saída: o dinheiro deixa a conta para quitar o
                     banco. Fixo, não depende de nenhum título. --}}
                @include('financeiro._account_select', ['accountDataset' => $accountDataset ?? [], 'tipo' => 'pagar'])
                <div class="mb-0">
                    <label class="form-label">Observações</label>
                    <textarea name="observacoes" class="form-control" rows="2" placeholder="Opcional — fica registrado em cada baixa"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-primary">Confirmar pagamento da fatura</button>
            </div>
        </form>
    </div>
</div>
