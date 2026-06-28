<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class StoreEquipmentModelRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        return [
            'tipo_id' => ['required', 'integer', 'min:1', Rule::exists('equipamentos_tipos', 'id')],
            'marca_id' => ['required', 'integer', 'min:1', Rule::exists('equipamentos_marcas', 'id')],
            'nome' => ['required', 'string', 'max:120'],
        ];
    }
}
