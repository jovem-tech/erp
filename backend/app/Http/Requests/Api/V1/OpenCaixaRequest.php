<?php

namespace App\Http\Requests\Api\V1;

/**
 * Abertura de caixa — specs/028-caixa-sessoes/spec.md.
 */
class OpenCaixaRequest extends BaseApiFormRequest
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
            'valor_abertura' => ['required', 'numeric', 'min:0'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'valor_abertura' => 'valor de abertura',
            'observacoes' => 'observações',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'valor_abertura.required' => 'Informe o troco que está na gaveta ao abrir o caixa.',
        ];
    }
}
