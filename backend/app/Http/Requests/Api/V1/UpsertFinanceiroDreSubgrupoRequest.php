<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class UpsertFinanceiroDreSubgrupoRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        $requiredOrSometimes = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'grupo_id' => [$requiredOrSometimes, 'integer', 'min:1', Rule::exists('financeiro_dre_grupos', 'id')],
            'nome' => [
                $requiredOrSometimes,
                'string',
                'max:100',
                Rule::unique('financeiro_dre_subgrupos', 'nome')
                    ->where('grupo_id', $this->input('grupo_id'))
                    ->ignore($this->route('subgrupo')),
            ],
            'descricao' => ['nullable', 'string', 'max:255'],
            'ordem_exibicao' => ['nullable', 'integer'],
            'ativo' => ['nullable', 'boolean'],
        ];
    }
}
