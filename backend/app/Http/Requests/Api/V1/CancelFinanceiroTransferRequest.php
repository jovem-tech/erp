<?php

namespace App\Http\Requests\Api\V1;

class CancelFinanceiroTransferRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
