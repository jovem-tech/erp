<?php

namespace App\Http\Requests\Api\V1;

use App\Models\OrderStatus;
use Illuminate\Validation\Rule;

class UpsertOrderRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        $requiredOrSometimes = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'cliente_id' => [$requiredOrSometimes, 'integer', 'min:1', Rule::exists('clientes', 'id')],
            'equipamento_id' => [$requiredOrSometimes, 'integer', 'min:1', Rule::exists('equipamentos', 'id')],
            'tecnico_id' => ['nullable', 'integer', 'min:1', Rule::exists('usuarios', 'id')],
            'status' => [$this->isMethod('post') ? 'nullable' : 'sometimes', 'string', 'max:80', Rule::in(OrderStatus::activeCodes())],
            'estado_fluxo' => ['nullable', 'string', 'max:40'],
            'prioridade' => ['nullable', 'string', Rule::in(['baixa', 'normal', 'alta', 'urgente'])],
            'relato_cliente' => [$requiredOrSometimes, 'string'],
            'diagnostico_tecnico' => ['nullable', 'string'],
            'solucao_aplicada' => ['nullable', 'string'],
            'procedimentos_executados' => ['nullable', 'string'],
            'acessorios' => ['nullable', 'string'],
            'forma_pagamento' => ['nullable', 'string', 'max:30'],
            'data_abertura' => ['nullable', 'date'],
            'data_entrada' => ['nullable', 'date'],
            'data_previsao' => ['nullable', 'date'],
            'data_conclusao' => ['nullable', 'date'],
            'data_entrega' => ['nullable', 'date'],
            'baixa_tecnica_em' => ['nullable', 'date'],
            'baixa_tecnica_por' => ['nullable', 'integer', 'min:1', Rule::exists('usuarios', 'id')],
            'valor_mao_obra' => ['nullable', 'numeric', 'min:0'],
            'valor_pecas' => ['nullable', 'numeric', 'min:0'],
            'valor_total' => ['nullable', 'numeric', 'min:0'],
            'desconto' => ['nullable', 'numeric', 'min:0'],
            'valor_final' => ['nullable', 'numeric', 'min:0'],
            'orcamento_aprovado' => ['nullable', 'boolean'],
            'data_aprovacao' => ['nullable', 'date'],
            'orcamento_pdf' => ['nullable', 'string', 'max:255'],
            'garantia_dias' => ['nullable', 'integer', 'min:0'],
            'garantia_validade' => ['nullable', 'date'],
            'observacoes_internas' => ['nullable', 'string'],
            'observacoes_cliente' => ['nullable', 'string'],
        ];
    }
}
