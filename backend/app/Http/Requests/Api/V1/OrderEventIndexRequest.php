<?php

namespace App\Http\Requests\Api\V1;

use App\Models\OrderEvent;
use Illuminate\Validation\Rule;

class OrderEventIndexRequest extends BaseApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['nullable', 'string', Rule::in(OrderEvent::categorias())],
            'origin' => ['nullable', 'string', Rule::in([
                OrderEvent::ORIGEM_SISTEMA,
                OrderEvent::ORIGEM_USUARIO,
                OrderEvent::ORIGEM_CLIENTE,
                OrderEvent::ORIGEM_AUTOMACAO,
            ])],
            'type' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'search' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([25, 50, 100])],
        ];
    }
}
