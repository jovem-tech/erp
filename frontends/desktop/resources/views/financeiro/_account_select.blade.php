@php
    $accountDataset = is_array($accountDataset ?? null) ? $accountDataset : [];
    $financialAccounts = array_values(array_filter(
        is_array($accountDataset['contas'] ?? null) ? $accountDataset['contas'] : [],
        static fn (array $account): bool => (bool) ($account['ativo'] ?? false)
    ));

    // O partial serve tanto a recebimento quanto a pagamento, e o texto muda
    // de sentido nos dois casos: recebendo o dinheiro ENTRA na conta, pagando
    // ele SAI. Sem $tipo cai no texto neutro — nenhum include existente
    // quebra por omissão.
    $tipoConta = (string) ($tipo ?? '');
    $contaEhSaida = $tipoConta === 'pagar';
    $contaEhEntrada = $tipoConta === 'receber';

    $contaPlaceholder = match (true) {
        $contaEhSaida => 'Selecione de onde o valor sai',
        $contaEhEntrada => 'Selecione onde o valor entra',
        default => 'Selecione onde o valor entra ou sai',
    };

    $contaHint = match (true) {
        $contaEhSaida => 'Define de qual conta o dinheiro sai.',
        $contaEhEntrada => 'Define em qual conta o dinheiro entra.',
        default => 'Define em qual conta o valor entra ou sai.',
    };
@endphp

@if ($financialAccounts !== [])
    <div class="mb-3">
        <label class="form-label">Conta financeira</label>
        <select name="conta_financeira_id" class="form-select" data-field="conta_financeira_id" required>
            <option value="">{{ $contaPlaceholder }}</option>
            @foreach ($financialAccounts as $account)
                <option value="{{ (int) $account['id'] }}">
                    {{ $account['nome'] }}{{ !(bool) ($account['considera_disponivel'] ?? true) ? ' (reserva)' : '' }}
                </option>
            @endforeach
        </select>
        <small class="text-secondary d-block mt-1">{{ $contaHint }} Não altera a forma de pagamento nem o faturamento.</small>
    </div>
@endif
