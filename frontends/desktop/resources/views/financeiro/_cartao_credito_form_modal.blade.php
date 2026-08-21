{{-- Modais de cadastro/edição dos cartões de crédito da assistência.
     Incluído dentro de @push('modals') pela tela de Contas e Saldos. --}}
@php
    $cartoesCredito = is_array($cartoesCredito ?? null) ? $cartoesCredito : [];
    $diasDoMes = range(1, 31);
    // Contas cadastradas em Contas e Saldos. É de onde o dinheiro sai quando a
    // compra é no débito (e a sugestão de conta ao pagar a fatura do crédito).
    $contasVinculaveis = array_values(array_filter(
        $accounts ?? [],
        static fn (array $conta): bool => (bool) ($conta['ativo'] ?? false)
    ));
@endphp

@if ($canCreate)
    <div class="modal fade" id="cartaoCreditoCreateModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form method="POST" action="{{ route('financeiro.cartoes-credito.store') }}" class="modal-content">
                @csrf
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title">Novo cartão de crédito</h5>
                        <small class="text-body-secondary">Cartão que a assistência usa para comprar — não é a maquininha de receber do cliente.</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <strong>Fechamento e vencimento definem a fatura.</strong>
                        Uma compra feita depois do dia de fechamento entra automaticamente na fatura do mês seguinte.
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nome</label>
                            <input name="nome" class="form-control" placeholder="Ex.: Nubank PJ" maxlength="100" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Instituição</label>
                            <input name="instituicao" class="form-control" placeholder="Ex.: Nubank" maxlength="100">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Final</label>
                            <input name="final_cartao" class="form-control" placeholder="1234" maxlength="4" inputmode="numeric">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Conta financeira vinculada</label>
                            <select name="conta_financeira_id" class="form-select">
                                <option value="">Não vincular a uma conta</option>
                                @foreach ($contasVinculaveis as $contaOpcao)
                                    <option value="{{ (int) $contaOpcao['id'] }}">{{ $contaOpcao['nome'] }}</option>
                                @endforeach
                            </select>
                            <small class="text-body-secondary d-block mt-1">
                                Conta de onde o dinheiro sai. No débito a compra é debitada dela na hora; no crédito ela é a sugestão de conta ao pagar a fatura.
                            </small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Dia de fechamento</label>
                            <select name="dia_fechamento" class="form-select" required>
                                @foreach ($diasDoMes as $dia)
                                    <option value="{{ $dia }}">{{ $dia }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Dia de vencimento</label>
                            <select name="dia_vencimento" class="form-select" required>
                                @foreach ($diasDoMes as $dia)
                                    <option value="{{ $dia }}" @selected($dia === 10)>{{ $dia }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Cor</label>
                            <input type="color" name="cor" value="#3868B0" class="form-control form-control-color">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Observações</label>
                            <textarea name="observacoes" class="form-control" rows="2" maxlength="2000"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-primary">Cadastrar cartão</button>
                </div>
            </form>
        </div>
    </div>
@endif

@if ($canEdit)
    @foreach ($cartoesCredito as $cartaoModal)
        @php $cartaoModalId = (int) ($cartaoModal['id'] ?? 0); @endphp
        <div class="modal fade" id="cartaoCreditoEditModal{{ $cartaoModalId }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <form method="POST" action="{{ route('financeiro.cartoes-credito.update', ['cartaoCredito' => $cartaoModalId]) }}" class="modal-content">
                    @csrf
                    @method('PATCH')
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title">Editar {{ $cartaoModal['nome'] ?? 'cartão' }}</h5>
                            <small class="text-body-secondary">Mudar fechamento/vencimento afeta apenas as próximas despesas — as já lançadas mantêm a fatura em que caíram.</small>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Nome</label>
                                <input name="nome" class="form-control" value="{{ $cartaoModal['nome'] ?? '' }}" maxlength="100" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Instituição</label>
                                <input name="instituicao" class="form-control" value="{{ $cartaoModal['instituicao'] ?? '' }}" maxlength="100">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Final</label>
                                <input name="final_cartao" class="form-control" value="{{ $cartaoModal['final_cartao'] ?? '' }}" maxlength="4" inputmode="numeric">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Conta financeira vinculada</label>
                                <select name="conta_financeira_id" class="form-select">
                                    <option value="">Não vincular a uma conta</option>
                                    @foreach ($contasVinculaveis as $contaOpcao)
                                        <option value="{{ (int) $contaOpcao['id'] }}"
                                                @selected((int) ($cartaoModal['conta_financeira_id'] ?? 0) === (int) $contaOpcao['id'])>
                                            {{ $contaOpcao['nome'] }}
                                        </option>
                                    @endforeach
                                </select>
                                <small class="text-body-secondary d-block mt-1">Conta de onde o dinheiro sai.</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Dia de fechamento</label>
                                <select name="dia_fechamento" class="form-select" required>
                                    @foreach ($diasDoMes as $dia)
                                        <option value="{{ $dia }}" @selected((int) ($cartaoModal['dia_fechamento'] ?? 0) === $dia)>{{ $dia }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Dia de vencimento</label>
                                <select name="dia_vencimento" class="form-select" required>
                                    @foreach ($diasDoMes as $dia)
                                        <option value="{{ $dia }}" @selected((int) ($cartaoModal['dia_vencimento'] ?? 0) === $dia)>{{ $dia }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Cor</label>
                                <input type="color" name="cor" value="{{ $cartaoModal['cor'] ?? '#3868B0' }}" class="form-control form-control-color">
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="ativo" value="1" id="cartaoAtivo{{ $cartaoModalId }}" @checked((bool) ($cartaoModal['ativo'] ?? false))>
                                    <label class="form-check-label" for="cartaoAtivo{{ $cartaoModalId }}">Cartão ativo (aparece no lançamento de despesas)</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Observações</label>
                                <textarea name="observacoes" class="form-control" rows="2" maxlength="2000">{{ $cartaoModal['observacoes'] ?? '' }}</textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                        <button class="btn btn-primary">Salvar alterações</button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach
@endif
