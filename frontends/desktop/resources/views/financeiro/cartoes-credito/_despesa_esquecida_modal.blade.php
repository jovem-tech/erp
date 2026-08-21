{{--
    Lança numa fatura JÁ PAGA uma compra que o banco cobrou mas que ninguém
    registrou aqui. Entra quitada junto com a fatura (ver
    FinanceiroCartaoCreditoService::registerForgottenExpense() na API), por isso
    o formulário não pergunta status nem forma de pagamento — só o que a
    despesa é.

    É a saída para o bloqueio "compra não entra em fatura paga": sem isso o
    caminho seria cancelar a baixa, lançar e pagar de novo.

    O <form> vive dentro do modal e submete nativamente (ação única da tela).
    Espera $cartaoId e $faturasPagas no escopo.
--}}
<div class="modal fade" id="despesaEsquecidaModal" tabindex="-1" aria-hidden="true"
     aria-labelledby="despesaEsquecidaModalLabel" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content modal-shell">
            <form method="post"
                  action="{{ route('financeiro.cartoes-credito.faturas.despesa-esquecida', ['cartaoCredito' => $cartaoId]) }}">
                @csrf

                <div class="modal-header">
                    <h5 class="modal-title" id="despesaEsquecidaModalLabel">Lançar despesa em fatura paga</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>

                <div class="modal-body">
                    <div class="alert alert-info">
                        Para a compra que o banco cobrou nesta fatura mas que não chegou a ser lançada aqui.
                        Ela entra <strong>já quitada</strong>, junto com o pagamento da fatura — o total da fatura
                        se corrige e ela continua paga.
                    </div>

                    <div class="desktop-grid desktop-grid-two">
                        <div>
                            <label class="form-label" for="despesaEsquecidaFatura">Fatura</label>
                            {{-- data-abertura/data-fechamento definem o intervalo
                                 aceito no calendário da data da compra: fora
                                 dele a compra cairia em OUTRA fatura, e o save
                                 recusaria. Ver _despesa_esquecida_modal.js. --}}
                            <select id="despesaEsquecidaFatura"
                                    name="data_vencimento"
                                    class="form-select"
                                    data-despesa-esquecida-fatura
                                    required>
                                @foreach ($faturasPagas as $faturaPaga)
                                    <option value="{{ $faturaPaga['data_vencimento'] }}"
                                            data-abertura="{{ $faturaPaga['data_abertura'] ?? '' }}"
                                            data-fechamento="{{ $faturaPaga['data_fechamento'] ?? '' }}"
                                            @selected(old('data_vencimento') === $faturaPaga['data_vencimento'])>
                                        Vence em {{ date('d/m/Y', strtotime((string) $faturaPaga['data_vencimento'])) }}
                                        · fechou em {{ date('d/m/Y', strtotime((string) ($faturaPaga['data_fechamento'] ?? $faturaPaga['data_vencimento']))) }}
                                    </option>
                                @endforeach
                            </select>
                            <small class="text-muted d-block mt-1">Só faturas já pagas aparecem aqui.</small>
                        </div>

                        <div>
                            <label class="form-label" for="despesaEsquecidaDataCompra">Data da compra</label>
                            <input type="date"
                                   id="despesaEsquecidaDataCompra"
                                   name="data_compra"
                                   class="form-control"
                                   value="{{ old('data_compra') }}"
                                   data-despesa-esquecida-data-compra
                                   required>
                            <small class="text-muted d-block mt-1" data-despesa-esquecida-janela>
                                Escolha a fatura para ver o período aceito.
                            </small>
                        </div>

                        <div>
                            <label class="form-label" for="despesaEsquecidaCategoria">Categoria</label>
                            <input type="text"
                                   id="despesaEsquecidaCategoria"
                                   name="categoria"
                                   class="form-control"
                                   maxlength="50"
                                   value="{{ old('categoria') }}"
                                   placeholder="Ex.: Compra de peças, Energia..."
                                   required>
                        </div>

                        <div>
                            <label class="form-label" for="despesaEsquecidaValor">Valor</label>
                            <input type="number"
                                   id="despesaEsquecidaValor"
                                   name="valor"
                                   class="form-control"
                                   step="0.01"
                                   min="0.01"
                                   value="{{ old('valor') }}"
                                   required>
                        </div>

                        <div class="desktop-grid-span-2">
                            <label class="form-label" for="despesaEsquecidaDescricao">Descrição</label>
                            <input type="text"
                                   id="despesaEsquecidaDescricao"
                                   name="descricao"
                                   class="form-control"
                                   maxlength="255"
                                   value="{{ old('descricao') }}"
                                   required>
                        </div>

                        <div class="desktop-grid-span-2">
                            <div class="form-check">
                                <input type="hidden" name="dre_fixo_mensal" value="0">
                                <input class="form-check-input"
                                       type="checkbox"
                                       id="despesaEsquecidaFixa"
                                       name="dre_fixo_mensal"
                                       value="1"
                                       @checked(old('dre_fixo_mensal'))>
                                <label class="form-check-label" for="despesaEsquecidaFixa">
                                    É uma despesa fixa (assinatura, plano, mensalidade)
                                </label>
                            </div>
                        </div>

                        <div class="desktop-grid-span-2">
                            <label class="form-label" for="despesaEsquecidaObservacoes">Observações</label>
                            <textarea id="despesaEsquecidaObservacoes"
                                      name="observacoes"
                                      class="form-control"
                                      rows="2">{{ old('observacoes') }}</textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Voltar</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-1"></i>Lançar despesa
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
