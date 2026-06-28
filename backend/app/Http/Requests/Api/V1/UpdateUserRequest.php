<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class UpdateUserRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        $userId = (int) $this->route('user');

        return [
            'nome' => ['sometimes', 'string', 'min:3', 'max:100'],
            'email' => ['sometimes', 'email', 'max:100', Rule::unique('usuarios', 'email')->ignore($userId)],
            'password' => ['nullable', 'string', 'min:6', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'perfil' => ['sometimes', 'string', Rule::in(['admin', 'tecnico', 'atendente'])],
            'grupo_id' => ['nullable', 'integer', 'min:1', Rule::exists('grupos', 'id')],
            'foto' => ['nullable', 'string', 'max:255'],
            'ativo' => ['nullable', 'boolean'],
        ];
    }
}
