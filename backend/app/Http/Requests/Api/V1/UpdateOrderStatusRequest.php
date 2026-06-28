<?php

namespace App\Http\Requests\Api\V1;

use App\Models\OrderStatus;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends BaseApiFormRequest
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
            'status' => [
                'required',
                'string',
                'max:80',
                Rule::in(OrderStatus::activeCodes()),
            ],
            'observacao' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }
}
