<?php

namespace App\Http\Requests\Api\V1;

use App\Models\FinanceiroChavePix;
use Illuminate\Validation\Rule;

class UpsertFinanceiroChavePixRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        $requiredOrSometimes = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'tipo' => [$requiredOrSometimes, 'string', Rule::in(array_keys(FinanceiroChavePix::TIPOS))],
            'chave' => [
                $requiredOrSometimes,
                'string',
                'max:200',
                Rule::unique('financeiro_chaves_pix', 'chave')->ignore($this->route('chavePix')),
            ],
            'titular' => ['nullable', 'string', 'max:160'],
            'instituicao' => ['nullable', 'string', 'max:80'],
            'principal' => ['nullable', 'boolean'],
            'ativo' => ['nullable', 'boolean'],
            'ordem_exibicao' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    public function attributes(): array
    {
        return [
            'tipo' => 'tipo da chave',
            'chave' => 'chave Pix',
            'titular' => 'titular da chave',
            'instituicao' => 'instituição',
        ];
    }
}
