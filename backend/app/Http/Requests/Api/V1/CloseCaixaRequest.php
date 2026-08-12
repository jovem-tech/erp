<?php

namespace App\Http\Requests\Api\V1;

/**
 * Fechamento de caixa — specs/028-caixa-sessoes/spec.md.
 *
 * A contagem é cega: o operador informa o que encontrou na gaveta e só então o
 * esperado e a diferença são calculados e devolvidos.
 */
class CloseCaixaRequest extends BaseApiFormRequest
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
            'valor_informado' => ['required', 'numeric', 'min:0'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
            // Derivados no servidor: aceitar do cliente permitiria "fechar sem
            // diferença" mandando o número que interessa.
            'valor_esperado' => ['prohibited'],
            'diferenca' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'valor_informado' => 'valor contado',
            'observacoes' => 'observações',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'valor_informado.required' => 'Informe o valor contado na gaveta para fechar o caixa.',
        ];
    }
}
