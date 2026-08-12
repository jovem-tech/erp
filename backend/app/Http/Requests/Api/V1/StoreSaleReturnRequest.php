<?php

namespace App\Http\Requests\Api\V1;

/**
 * Devolução de venda — specs/029-devolucao-troca/spec.md.
 */
class StoreSaleReturnRequest extends BaseApiFormRequest
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
            'creation_request_id' => ['nullable', 'uuid'],
            // Sem motivo a devolução vira dinheiro saindo sem explicação.
            'motivo' => ['required', 'string', 'min:3', 'max:2000'],

            'itens' => ['required', 'array', 'min:1'],
            'itens.*.venda_item_id' => ['required', 'integer', 'min:1'],
            'itens.*.quantidade' => ['required', 'numeric', 'min:0.001'],
            'itens.*.observacoes' => ['nullable', 'string', 'max:1000'],

            // Exigidas quando a venda está fora do prazo livre de devolução.
            'admin_email' => ['nullable', 'string', 'email', 'max:255'],
            'admin_password' => ['nullable', 'string', 'max:255'],

            // Troca: a venda nova que o cliente levou no lugar.
            'venda_troca_id' => ['nullable', 'integer', 'min:1'],

            // Derivados pelo servidor: aceitar do cliente permitiria forjar o
            // valor do reembolso.
            'numero' => ['prohibited'],
            'valor_devolvido' => ['prohibited'],
            'valor_reembolsado' => ['prohibited'],
            'valor_abatido' => ['prohibited'],
            'valor_taxa_nao_estornada' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'motivo' => 'motivo da devolução',
            'itens' => 'itens devolvidos',
            'itens.*.venda_item_id' => 'item da venda',
            'itens.*.quantidade' => 'quantidade devolvida',
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
            'motivo.required' => 'Descreva o motivo da devolução.',
            'itens.required' => 'Selecione ao menos um item devolvido.',
            'itens.min' => 'Selecione ao menos um item devolvido.',
        ];
    }
}
