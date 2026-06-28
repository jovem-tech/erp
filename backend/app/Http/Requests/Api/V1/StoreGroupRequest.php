<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class StoreGroupRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'min:3', 'max:80', Rule::unique('grupos', 'nome')],
            'descricao' => ['nullable', 'string', 'max:200'],
        ];
    }
}
