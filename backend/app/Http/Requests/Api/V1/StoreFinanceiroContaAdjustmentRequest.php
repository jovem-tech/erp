<?php

namespace App\Http\Requests\Api\V1;

use App\Models\FinanceiroContaMovimento;
use Illuminate\Validation\Rule;

class StoreFinanceiroContaAdjustmentRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        return [
            'natureza' => ['required', 'string', Rule::in([
                FinanceiroContaMovimento::NATUREZA_ENTRADA,
                FinanceiroContaMovimento::NATUREZA_SAIDA,
            ])],
            'valor' => ['required', 'numeric', 'min:0.01', 'max:999999999999.99'],
            'data_movimento' => ['required', 'date', 'before_or_equal:today'],
            'descricao' => ['required', 'string', 'min:5', 'max:255'],
            'documento_ref' => ['nullable', 'string', 'max:100'],
        ];
    }
}
