<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class UpsertFinanceiroFormaPagamentoRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        $requiredOrSometimes = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'nome' => [
                $requiredOrSometimes,
                'string',
                'max:60',
                Rule::unique('financeiro_formas_pagamento', 'nome')
                    ->ignore($this->route('formaPagamento')),
            ],
            // O código é derivado do nome na criação e é imutável depois; formas
            // de sistema nunca aceitam alteração de código nem do tipo cartão.
            'is_cartao' => ['nullable', 'boolean'],
            'ordem_exibicao' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'ativo' => ['nullable', 'boolean'],
        ];
    }
}
