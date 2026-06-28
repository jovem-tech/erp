<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class UpsertComissaoTecnicoRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        $requiredOrSometimes = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'tecnico_id' => [
                $requiredOrSometimes,
                'integer',
                'min:1',
                Rule::exists('usuarios', 'id'),
                Rule::unique('comissoes_tecnicos', 'tecnico_id')->ignore($this->route('comissao')),
            ],
            'percentual_padrao' => [$requiredOrSometimes, 'numeric', 'min:0', 'max:100'],
            'ativo' => ['nullable', 'boolean'],
        ];
    }
}
