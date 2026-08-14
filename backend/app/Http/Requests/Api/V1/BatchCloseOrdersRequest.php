<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Orders\OrderClosureService;
use Illuminate\Validation\Rule;

class BatchCloseOrdersRequest extends BaseApiFormRequest
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
            'order_ids' => ['required', 'array', 'min:1', 'max:20'],
            'order_ids.*' => ['required', 'integer', 'distinct', Rule::exists('os', 'id')],
            // Só os 4 códigos sem cobrança (OrderClosureService::batchClosureCodes()).
            // Rule::in explícito — nunca OrderStatus::closureCodes() direto — para
            // nunca aceitar "Entregue - Reparado e Pago" por acidente se o catálogo
            // de status mudar: esse encerramento exige valor/forma de pagamento por
            // OS, incompatível com um único status aplicado a várias OS de uma vez.
            'encerrar_como' => ['required', 'string', Rule::in(OrderClosureService::batchClosureCodes())],
            'data_entrega' => ['required', 'date'],
            'observacao' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
