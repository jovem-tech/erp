<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class StoreUserRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'min:3', 'max:100'],
            'email' => ['required', 'email', 'max:100', Rule::unique('usuarios', 'email')],
            'password' => ['required', 'string', 'min:6', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'perfil' => ['required', 'string', Rule::in(['admin', 'tecnico', 'atendente'])],
            'grupo_id' => ['nullable', 'integer', 'min:1', Rule::exists('grupos', 'id')],
            'foto' => ['nullable', 'string', 'max:255'],
            'ativo' => ['nullable', 'boolean'],
        ];
    }
}
