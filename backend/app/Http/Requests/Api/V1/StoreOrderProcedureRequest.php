<?php

namespace App\Http\Requests\Api\V1;

class StoreOrderProcedureRequest extends BaseApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'descricao' => [
                'required',
                'string',
            ],
        ];
    }
}
