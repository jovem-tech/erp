<?php

namespace App\Http\Requests\Api\V1;

class PayFinanceiroCartaoCreditoInvoiceRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        return [
            'data_pagamento' => ['nullable', 'date'],
            // Como se pagou a FATURA (pix, débito em conta, transferência) —
            // nada a ver com o fato de as despesas terem sido compradas no
            // cartão. Por isso não aceita operadora/parcelas: isso é do fluxo
            // de receber na maquininha.
            'forma_pagamento' => ['nullable', 'string', 'max:40'],
            'conta_financeira_id' => ['nullable', 'integer', 'exists:financeiro_contas,id'],
            'observacoes' => ['nullable', 'string'],
        ];
    }
}
