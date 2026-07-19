<?php

namespace App\Http\Requests\Api\V1;

class ConfirmFinanceiroCardCreditRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        return [
            'data_credito_efetivo' => ['required', 'date', 'before_or_equal:today'],
        ];
    }
}
