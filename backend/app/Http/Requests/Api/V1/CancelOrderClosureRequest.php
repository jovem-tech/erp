<?php

namespace App\Http\Requests\Api\V1;

class CancelOrderClosureRequest extends BaseApiFormRequest
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
            'admin_email' => ['required', 'string', 'email'],
            'admin_password' => ['required', 'string'],
        ];
    }
}
