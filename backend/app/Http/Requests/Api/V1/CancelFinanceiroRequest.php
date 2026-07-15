<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

/**
 * Reaproveitada por FinanceiroController::cancel() e ::destroy() — os dois
 * únicos endpoints que exigem confirmação de administrador. admin_email/
 * admin_password são sempre obrigatórios em destroy(); em cancel() só quando
 * o lançamento está vinculado a uma OS encerrada (ver
 * OrderStatus::FINANCIAL_IMPACT_CLOSURE_CODES) — 'motivo' só existe para
 * cancel(). A obrigatoriedade condicional é resolvida nos controllers, não
 * aqui, porque depende de consulta ao banco (status da OS vinculada), não só
 * do payload.
 */
class CancelFinanceiroRequest extends BaseApiFormRequest
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
            'admin_email' => ['nullable', 'string', 'email'],
            'admin_password' => ['nullable', 'string'],
            'motivo' => ['nullable', 'string', Rule::in(['sem_reparo', 'erro_cobranca', 'fechamento_indevido'])],
        ];
    }
}
