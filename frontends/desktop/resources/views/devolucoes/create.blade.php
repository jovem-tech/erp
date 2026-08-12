@extends('layouts.app')

@section('content')
    @php
        $vendaId = (int) ($venda['id'] ?? 0);
        $money = static fn ($value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
        $disponiveis = collect($itens)->filter(static fn (array $i): bool => (float) $i['quantidade_disponivel'] > 0);
    @endphp

    <section class="surface-card">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h1 class="surface-title mb-1">Devolver venda {{ $venda['numero'] ?? '' }}</h1>
                <p class="surface-subtitle mb-0">
                    Total da venda: {{ $money($venda['total'] ?? 0) }}
                    · Pago: {{ $money($venda['valor_pago'] ?? 0) }}
                </p>
            </div>

            <a href="{{ route('vendas.show', $vendaId) }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-2"></i>Voltar à venda
            </a>
        </div>

        @if ($exigeAutorizacao)
            <div class="alert alert-warning mt-3 mb-0">
                <i class="bi bi-shield-lock me-2"></i>
                Esta venda tem mais de {{ $prazoLivreDias }} dias. A devolução exige
                confirmação de um administrador no fim do formulário.
            </div>
        @endif
    </section>

    @if ($disponiveis->isEmpty())
        <section class="surface-card mt-3">
            @include('layouts.partials.empty-state', [
                'icon' => 'bi-arrow-return-left',
                'title' => 'Nada a devolver',
                'message' => 'Todos os itens desta venda já foram devolvidos.',
            ])
        </section>
    @else
        <form method="post" action="{{ route('devolucoes.store', $vendaId) }}" id="devolucaoForm">
            @csrf
            {{-- Chave de idempotência: duplo clique não pode virar duas devoluções. --}}
            <input type="hidden" name="creation_request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">

            <section class="surface-table mt-3">
                <div class="surface-table-header">
                    <div>
                        <h2 class="surface-title">O que está voltando</h2>
                        <p class="surface-subtitle">
                            Informe a quantidade devolvida de cada item. O reembolso já considera
                            o desconto que a venda teve.
                        </p>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-stack align-middle">
                        <thead>
                        <tr>
                            <th>Item</th>
                            <th class="text-center">Vendido</th>
                            <th class="text-center">Já devolvido</th>
                            <th class="text-end">Reembolso unit.</th>
                            <th style="width: 140px;">Devolver</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($itens as $indice => $item)
                            @php $disponivel = (float) $item['quantidade_disponivel']; @endphp
                            <tr class="{{ $disponivel <= 0 ? 'opacity-50' : '' }}">
                                <td data-label="Item">
                                    <input type="hidden" name="itens[{{ $indice }}][venda_item_id]" value="{{ $item['venda_item_id'] }}">
                                    <div class="fw-semibold">{{ $item['descricao'] }}</div>
                                    <small class="text-secondary d-block">
                                        {{ $item['tipo_item_label'] }}
                                        @if (! empty($item['codigo'])) · {{ $item['codigo'] }} @endif
                                        @if ($item['retorna_estoque'])
                                            · <span class="text-success">volta ao estoque</span>
                                        @else
                                            · <span class="text-secondary">não movimenta estoque</span>
                                        @endif
                                    </small>
                                </td>
                                <td data-label="Vendido" class="text-center">
                                    {{ rtrim(rtrim(number_format((float) $item['quantidade_vendida'], 3, ',', '.'), '0'), ',') }}
                                </td>
                                <td data-label="Já devolvido" class="text-center">
                                    {{ rtrim(rtrim(number_format((float) $item['quantidade_devolvida'], 3, ',', '.'), '0'), ',') }}
                                </td>
                                <td data-label="Reembolso unit." class="text-end">{{ $money($item['reembolso_unitario']) }}</td>
                                <td data-label="Devolver">
                                    <input
                                        type="text"
                                        name="itens[{{ $indice }}][quantidade]"
                                        class="form-control form-control-sm text-center"
                                        value="0"
                                        inputmode="decimal"
                                        data-max="{{ $disponivel }}"
                                        @disabled($disponivel <= 0)
                                    >
                                    <small class="text-secondary d-block text-center mt-1">
                                        máx. {{ rtrim(rtrim(number_format($disponivel, 3, ',', '.'), '0'), ',') }}
                                    </small>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="surface-card mt-3">
                <label for="devolucaoMotivo" class="form-label">Motivo da devolução</label>
                <textarea
                    id="devolucaoMotivo"
                    name="motivo"
                    class="form-control @error('motivo') is-invalid @enderror"
                    rows="2"
                    minlength="3"
                    maxlength="2000"
                    required
                    placeholder="Ex.: produto com defeito, cliente comprou o tamanho errado"
                >{{ old('motivo') }}</textarea>
                @error('motivo')<div class="invalid-feedback">{{ $message }}</div>@enderror

                @if ($exigeAutorizacao)
                    <div class="border rounded p-3 mt-3">
                        <p class="small text-secondary mb-3">
                            Venda com mais de {{ $prazoLivreDias }} dias: confirme com as credenciais
                            de um administrador.
                        </p>

                        <div class="row g-2">
                            <div class="col-12 col-md-6">
                                <label for="devolucaoAdminEmail" class="form-label">E-mail do administrador</label>
                                <input type="email" id="devolucaoAdminEmail" name="admin_email" class="form-control" autocomplete="off" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="devolucaoAdminSenha" class="form-label">Senha</label>
                                <input type="password" id="devolucaoAdminSenha" name="admin_password" class="form-control" autocomplete="new-password" required>
                            </div>
                        </div>
                    </div>
                @endif

                <div class="alert alert-info mt-3 mb-0">
                    <i class="bi bi-info-circle me-2"></i>
                    O dinheiro volta pela mesma forma em que entrou. Devolução em espécie
                    sai da gaveta do caixa aberto agora.
                </div>

                <button type="submit" class="btn btn-primary btn-lg w-100 mt-3">
                    <i class="bi bi-arrow-return-left me-2"></i>
                    Registrar devolução
                </button>
            </section>
        </form>
    @endif
@endsection
