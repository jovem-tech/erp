<?php

namespace App\Http\Requests\Api\V1;

use App\Models\CaixaMovimento;
use Illuminate\Validation\Rule;

/**
 * Sangria ou suprimento — specs/028-caixa-sessoes/spec.md.
 */
class StoreCaixaMovimentoRequest extends BaseApiFormRequest
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
            'tipo' => ['required', 'string', Rule::in(CaixaMovimento::types())],
            'valor' => ['required', 'numeric', 'min:0.01'],
            // Sangria sem motivo é dinheiro sumindo sem explicação — o campo é
            // a única coisa que torna a conferência auditável depois.
            'motivo' => ['required', 'string', 'min:3', 'max:255'],
            // Só faz sentido em sangria: para onde o dinheiro foi.
            'conta_destino_id' => ['nullable', 'integer', Rule::exists('financeiro_contas', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tipo' => 'tipo do movimento',
            'valor' => 'valor',
            'motivo' => 'motivo',
            'conta_destino_id' => 'conta de destino',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Descreva o motivo do movimento.',
            'tipo.in' => 'O movimento precisa ser uma sangria ou um suprimento.',
        ];
    }
}
