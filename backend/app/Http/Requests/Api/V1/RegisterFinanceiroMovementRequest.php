<?php

namespace App\Http\Requests\Api\V1;

class RegisterFinanceiroMovementRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        return [
            'valor_movimento' => ['required', 'numeric', 'min:0.01'],
            'data_movimento' => ['nullable', 'date'],
            'forma_pagamento' => ['nullable', 'string', 'max:40'],
            'conta_financeira_id' => ['nullable', 'integer', 'exists:financeiro_contas,id'],
            'documento_ref' => ['nullable', 'string', 'max:100'],
            'observacoes' => ['nullable', 'string'],
            'operadora_id' => ['nullable', 'integer', 'min:1', 'required_if:forma_pagamento,cartao_credito,cartao_debito'],
            'bandeira_id' => ['nullable', 'integer', 'min:1'],
            'modalidade' => ['nullable', 'string', 'in:credito,debito'],
            'parcelas' => ['nullable', 'integer', 'min:1', 'max:99'],
        ];
    }
}
