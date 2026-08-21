<?php

namespace App\Http\Requests\Api\V1;

/**
 * Cancelar a baixa de uma fatura de cartão é um estorno: apaga os movimentos
 * das despesas e devolve todas para pendente. Por ser destrutivo e mexer em
 * dinheiro já conciliado, exige confirmação de administrador sempre — por isso
 * admin_email/admin_password são obrigatórios aqui, sem o "nullable" condicional
 * de CancelFinanceiroRequest.
 */
class CancelFinanceiroCartaoCreditoInvoiceRequest extends BaseApiFormRequest
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
            'admin_email' => ['required', 'string', 'email'],
            'admin_password' => ['required', 'string'],
        ];
    }
}
