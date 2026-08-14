<?php

namespace App\Http\Requests\Api\V1;

use App\Models\OrderStatus;
use Illuminate\Validation\Rule;

class BatchUpdateStatusRequest extends BaseApiFormRequest
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
            // array_diff explícito (não confiar só na trava interna de
            // OrderWorkflowService::updateStatus()) — rejeita de cara com um
            // único 422 em vez de deixar cada uma das N OS do lote falhar
            // individualmente com closure_status_requires_baixa_flow.
            // Encerramento continua exclusivo da baixa (individual ou em lote).
            'status' => ['required', 'string', Rule::in(array_diff(OrderStatus::activeCodes(), OrderStatus::closureCodes()))],
            'observacao' => ['nullable', 'string', 'max:2000'],
            'comunicar_cliente' => ['nullable', 'boolean'],
        ];
    }
}
