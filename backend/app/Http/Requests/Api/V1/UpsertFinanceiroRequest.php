<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Financeiro;
use Illuminate\Validation\Rule;

class UpsertFinanceiroRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        $requiredOrSometimes = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'tipo' => [$requiredOrSometimes, 'string', Rule::in([Financeiro::TIPO_RECEBER, Financeiro::TIPO_PAGAR])],
            'categoria' => [$requiredOrSometimes, 'string', 'max:50'],
            'descricao' => [$requiredOrSometimes, 'string', 'max:255'],
            'valor' => [$requiredOrSometimes, 'numeric', 'min:0.01', 'max:99999999.99'],
            'status' => ['nullable', 'string', Rule::in(array_column(Financeiro::statusOptions(), 'value'))],
            'forma_pagamento' => ['nullable', 'string', Rule::in(Financeiro::FORMAS_PAGAMENTO)],
            'conta_financeira_id' => ['nullable', 'integer', Rule::exists('financeiro_contas', 'id')],
            'data_vencimento' => [$requiredOrSometimes, 'date'],
            'data_pagamento' => ['nullable', 'date'],
            'data_competencia' => ['nullable', 'date'],
            'observacoes' => ['nullable', 'string'],
            'os_id' => ['nullable', 'integer', 'min:1', Rule::exists('os', 'id')],
            'cliente_id' => ['nullable', 'integer', 'min:1', Rule::exists('clientes', 'id')],
            'fornecedor_id' => ['nullable', 'integer', 'min:1'],
            'avulso' => ['nullable', 'boolean'],
            'grupo_dre' => ['nullable', 'string', 'max:60'],
            'subgrupo_dre' => ['nullable', 'string', 'max:80'],
            'impacta_dre' => ['nullable', 'boolean'],
            'impacta_fluxo_caixa' => ['nullable', 'boolean'],
            'dre_fixo_mensal' => ['nullable', 'boolean'],
        ];
    }
}
