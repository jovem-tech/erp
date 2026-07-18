@php
    $accountDataset = is_array($accountDataset ?? null) ? $accountDataset : [];
    $financialAccounts = array_values(array_filter(
        is_array($accountDataset['contas'] ?? null) ? $accountDataset['contas'] : [],
        static fn (array $account): bool => (bool) ($account['ativo'] ?? false)
    ));
@endphp

@if ($financialAccounts !== [])
    <div class="mb-3">
        <label class="form-label">Conta financeira</label>
        <select name="conta_financeira_id" class="form-select" data-field="conta_financeira_id" required>
            <option value="">Selecione onde o valor entra ou sai</option>
            @foreach ($financialAccounts as $account)
                <option value="{{ (int) $account['id'] }}">
                    {{ $account['nome'] }}{{ !(bool) ($account['considera_disponivel'] ?? true) ? ' (reserva)' : '' }}
                </option>
            @endforeach
        </select>
        <small class="text-secondary d-block mt-1">Define onde o dinheiro ficará disponível. Não altera a forma de pagamento nem o faturamento.</small>
    </div>
@endif
