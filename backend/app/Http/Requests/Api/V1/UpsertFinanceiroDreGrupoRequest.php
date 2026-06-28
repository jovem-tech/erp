<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class UpsertFinanceiroDreGrupoRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        return [
            'nome' => [
                $this->isMethod('post') ? 'required' : 'sometimes',
                'string',
                'max:80',
                Rule::unique('financeiro_dre_grupos', 'nome')->ignore($this->route('grupo')),
            ],
            'descricao' => ['nullable', 'string', 'max:255'],
            'ordem_exibicao' => ['nullable', 'integer'],
            'ativo' => ['nullable', 'boolean'],
        ];
    }
}
