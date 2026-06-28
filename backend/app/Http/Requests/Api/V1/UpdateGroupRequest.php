<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class UpdateGroupRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        $groupId = (int) $this->route('group');

        return [
            'nome' => ['sometimes', 'string', 'min:3', 'max:80', Rule::unique('grupos', 'nome')->ignore($groupId)],
            'descricao' => ['nullable', 'string', 'max:200'],
        ];
    }
}
