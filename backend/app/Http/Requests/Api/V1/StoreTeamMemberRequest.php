<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class StoreTeamMemberRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'min:3', 'max:100'],
            'email' => ['nullable', 'email', 'max:100'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'cargo' => ['nullable', 'string', 'max:100'],
            'usuario_id' => ['nullable', 'integer', 'min:1', Rule::exists('usuarios', 'id'), Rule::unique('equipe_membros', 'usuario_id')],
            'atua_tecnico' => ['nullable', 'boolean'],
            'atua_vendas' => ['nullable', 'boolean'],
            'atua_administrativo' => ['nullable', 'boolean'],
            'ativo' => ['nullable', 'boolean'],
            'observacoes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
