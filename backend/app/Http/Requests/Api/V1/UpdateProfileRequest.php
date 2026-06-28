<?php

namespace App\Http\Requests\Api\V1;

class UpdateProfileRequest extends BaseApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'min:3', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nome.required' => 'O nome de perfil é obrigatório.',
            'nome.string' => 'O nome de perfil deve ser um texto válido.',
            'nome.min' => 'O nome de perfil deve ter pelo menos 3 caracteres.',
            'nome.max' => 'O nome de perfil deve ter no máximo 100 caracteres.',
        ];
    }
}
