<?php

namespace App\Http\Requests\Api\V1;

use App\Models\FinanceiroCategoria;
use Illuminate\Validation\Rule;

class UpsertFinanceiroCategoriaRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        $requiredOrSometimes = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'nome' => [
                $requiredOrSometimes,
                'string',
                'max:100',
                Rule::unique('financeiro_categorias', 'nome')
                    ->where('tipo', $this->input('tipo'))
                    ->ignore($this->route('categoria')),
            ],
            'tipo' => [
                $requiredOrSometimes,
                'string',
                Rule::in([FinanceiroCategoria::TIPO_RECEBER, FinanceiroCategoria::TIPO_PAGAR, FinanceiroCategoria::TIPO_AMBOS]),
            ],
            'dre_grupo_id' => ['nullable', 'integer', 'min:1', Rule::exists('financeiro_dre_grupos', 'id')],
            'dre_subgrupo_id' => ['nullable', 'integer', 'min:1', Rule::exists('financeiro_dre_subgrupos', 'id')],
            'impacta_dre_padrao' => ['nullable', 'boolean'],
            'impacta_fluxo_caixa_padrao' => ['nullable', 'boolean'],
            'dre_fixo_mensal_padrao' => ['nullable', 'boolean'],
            'ordem_exibicao' => ['nullable', 'integer'],
            'ativo' => ['nullable', 'boolean'],
        ];
    }
}
