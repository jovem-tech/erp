<?php

namespace App\Http\Requests\Api\V1;

class UpdateUserActiveRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        return [
            'active' => ['required', 'boolean'],
        ];
    }
}
