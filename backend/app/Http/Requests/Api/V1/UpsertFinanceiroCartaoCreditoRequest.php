<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class UpsertFinanceiroCartaoCreditoRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        $isCreate = $this->isMethod('post');
        $requiredOrSometimes = $isCreate ? 'required' : 'sometimes';
        $cartaoId = $this->route('cartaoCredito')?->id;

        return [
            'nome' => [
                $requiredOrSometimes,
                'string',
                'max:100',
                Rule::unique('financeiro_cartoes_credito', 'nome')->ignore($cartaoId),
            ],
            'instituicao' => ['nullable', 'string', 'max:100'],
            // Conta de Contas e Saldos de onde o dinheiro sai (obrigatória na
            // prática só para débito, mas útil no crédito como sugestão de
            // conta na hora de pagar a fatura).
            'conta_financeira_id' => ['nullable', 'integer', Rule::exists('financeiro_contas', 'id')],
            'final_cartao' => ['nullable', 'string', 'max:4'],
            'dia_fechamento' => [$requiredOrSometimes, 'integer', 'between:1,31'],
            'dia_vencimento' => [$requiredOrSometimes, 'integer', 'between:1,31'],
            'cor' => ['nullable', 'string', 'max:7'],
            'ativo' => ['nullable', 'boolean'],
            'observacoes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'nome.unique' => 'Já existe um cartão cadastrado com este nome.',
            'dia_fechamento.between' => 'O dia de fechamento deve estar entre 1 e 31.',
            'dia_vencimento.between' => 'O dia de vencimento deve estar entre 1 e 31.',
        ];
    }
}
