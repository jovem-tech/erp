<?php

namespace App\Http\Requests\Api\V1;

class UpdateGroupPermissionsRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        return [
            'permissions' => ['required', 'array'],
            'permissions.*' => ['array'],
            'permissions.*.*' => ['string', 'max:80'],
        ];
    }
}
