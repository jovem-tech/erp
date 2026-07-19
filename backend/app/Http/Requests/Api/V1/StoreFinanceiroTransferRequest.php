<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class StoreFinanceiroTransferRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        return [
            'conta_origem_id' => ['required', 'integer', Rule::exists('financeiro_contas', 'id')],
            'conta_destino_id' => ['required', 'integer', 'different:conta_origem_id', Rule::exists('financeiro_contas', 'id')],
            'valor' => ['required', 'numeric', 'min:0.01', 'max:999999999999.99'],
            'data_transferencia' => ['required', 'date', 'before_or_equal:today'],
            'descricao' => ['required', 'string', 'min:3', 'max:255'],
            'documento_ref' => ['nullable', 'string', 'max:100'],
        ];
    }
}
