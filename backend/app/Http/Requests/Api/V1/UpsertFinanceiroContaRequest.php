<?php

namespace App\Http\Requests\Api\V1;

use App\Models\FinanceiroConta;
use App\Models\FinanceiroFormaPagamento;
use Illuminate\Validation\Rule;

class UpsertFinanceiroContaRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $contaId = $this->route('conta')?->id;

        return [
            'nome' => [$required, 'string', 'max:100', Rule::unique('financeiro_contas', 'nome')->ignore($contaId)],
            'tipo' => [$required, 'string', Rule::in(FinanceiroConta::typeValues())],
            'instituicao' => ['nullable', 'string', 'max:100'],
            'data_inicio_controle' => [$required, 'date', 'before_or_equal:today'],
            'saldo_inicial' => ['nullable', 'numeric', 'min:-999999999999.99', 'max:999999999999.99'],
            'considera_disponivel' => ['nullable', 'boolean'],
            'ativo' => ['nullable', 'boolean'],
            'cor' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
            'formas_padrao' => ['nullable', 'array'],
            // Coluna varchar: aceita o catálogo inteiro, inclusive formas novas.
            'formas_padrao.*' => ['string', Rule::in(FinanceiroFormaPagamento::validCodes())],
        ];
    }
}
