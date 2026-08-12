<?php

namespace App\Http\Requests\Api\V1;

/**
 * Cancelamento de venda de balcão — specs/027-vendas-balcao-pdv/spec.md.
 *
 * O cancelamento estorna estoque e dinheiro, então o motivo é obrigatório.
 * As credenciais de admin são exigidas pelo controller quando a venda não é do
 * dia corrente (mesmo padrão de FinanceiroController::cancel()).
 */
class CancelSaleRequest extends BaseApiFormRequest
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
            'motivo' => ['required', 'string', 'min:5', 'max:2000'],
            'admin_email' => ['nullable', 'string', 'email', 'max:255'],
            'admin_password' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'motivo' => 'motivo do cancelamento',
            'admin_email' => 'e-mail do administrador',
            'admin_password' => 'senha do administrador',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Informe o motivo do cancelamento desta venda.',
            'motivo.min' => 'Descreva o motivo do cancelamento com pelo menos 5 caracteres.',
        ];
    }
}
