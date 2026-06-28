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
            'documento_ref' => ['nullable', 'string', 'max:100'],
            'observacoes' => ['nullable', 'string'],
        ];
    }
}
