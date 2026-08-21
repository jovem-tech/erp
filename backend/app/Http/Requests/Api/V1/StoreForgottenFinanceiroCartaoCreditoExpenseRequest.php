<?php

namespace App\Http\Requests\Api\V1;

/**
 * Despesa que o banco cobrou numa fatura já paga mas que ninguém registrou no
 * sistema. Não aceita status nem forma de pagamento: ela nasce sempre quitada,
 * copiando data/forma/conta da baixa da própria fatura (ver
 * FinanceiroCartaoCreditoService::registerForgottenExpense()).
 */
class StoreForgottenFinanceiroCartaoCreditoExpenseRequest extends BaseApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'categoria' => ['required', 'string', 'max:50'],
            'descricao' => ['required', 'string', 'max:255'],
            'valor' => ['required', 'numeric', 'min:0.01'],
            'data_compra' => ['required', 'date'],
            'fornecedor_id' => ['nullable', 'integer', 'min:1'],
            'dre_fixo_mensal' => ['nullable', 'boolean'],
            'observacoes' => ['nullable', 'string'],
        ];
    }
}
