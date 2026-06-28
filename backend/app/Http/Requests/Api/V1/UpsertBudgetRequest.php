<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Budget;
use Illuminate\Validation\Rule;

class UpsertBudgetRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        $requiredOrSometimes = $this->isMethod('post') ? 'nullable' : 'sometimes';

        return [
            'numero' => ['sometimes', 'string', 'max:40'],
            'versao' => ['sometimes', 'integer', 'min:1'],
            'tipo_orcamento' => ['nullable', 'string', 'max:30', Rule::in(array_column(Budget::typeOptions(), 'value'))],
            'status' => ['nullable', 'string', 'max:40', Rule::in(array_column(Budget::statusOptions(), 'value'))],
            'origem' => ['nullable', 'string', 'max:40', Rule::in(array_column(Budget::originOptions(), 'value'))],
            'cliente_id' => [$requiredOrSometimes, 'nullable', 'integer', 'min:1', Rule::exists('clientes', 'id')],
            'contato_id' => ['nullable', 'integer', 'min:1'],
            'cliente_nome_avulso' => ['nullable', 'string', 'max:160'],
            'telefone_contato' => ['nullable', 'string', 'max:30'],
            'email_contato' => ['nullable', 'email', 'max:120'],
            'os_id' => ['nullable', 'integer', 'min:1', Rule::exists('os', 'id')],
            'equipamento_id' => ['nullable', 'integer', 'min:1', Rule::exists('equipamentos', 'id')],
            'equipamento_tipo_id' => ['nullable', 'integer', 'min:1'],
            'equipamento_marca_id' => ['nullable', 'integer', 'min:1'],
            'equipamento_modelo_id' => ['nullable', 'integer', 'min:1'],
            'equipamento_cor' => ['nullable', 'string', 'max:100'],
            'equipamento_cor_hex' => ['nullable', 'string', 'max:7'],
            'equipamento_cor_rgb' => ['nullable', 'string', 'max:32'],
            'conversa_id' => ['nullable', 'integer', 'min:1'],
            'responsavel_id' => ['nullable', 'integer', 'min:1', Rule::exists('usuarios', 'id')],
            'criado_por' => ['nullable', 'integer', 'min:1', Rule::exists('usuarios', 'id')],
            'atualizado_por' => ['nullable', 'integer', 'min:1', Rule::exists('usuarios', 'id')],
            'titulo' => ['nullable', 'string', 'max:180'],
            'validade_dias' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'validade_data' => ['nullable', 'date'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
            'desconto' => ['nullable', 'numeric', 'min:0'],
            'acrescimo' => ['nullable', 'numeric', 'min:0'],
            'total' => ['nullable', 'numeric', 'min:0'],
            'prazo_execucao' => ['nullable', 'string', 'max:120'],
            'observacoes' => ['nullable', 'string'],
            'condicoes' => ['nullable', 'string'],
            'token_publico' => ['nullable', 'string', 'max:80'],
            'token_expira_em' => ['nullable', 'date'],
            'enviado_em' => ['nullable', 'date'],
            'aprovado_em' => ['nullable', 'date'],
            'rejeitado_em' => ['nullable', 'date'],
            'cancelado_em' => ['nullable', 'date'],
            'motivo_rejeicao' => ['nullable', 'string'],
            'convertido_tipo' => ['nullable', 'string', 'max:30'],
            'convertido_id' => ['nullable', 'integer', 'min:1'],
            'itens' => ['nullable', 'array'],
            'itens.*.tipo_item' => ['nullable', 'string', 'max:30'],
            'itens.*.referencia_id' => ['nullable', 'integer', 'min:1'],
            'itens.*.descricao' => ['required_with:itens', 'string', 'max:255'],
            'itens.*.quantidade' => ['nullable', 'numeric', 'min:0'],
            'itens.*.valor_unitario' => ['nullable', 'numeric', 'min:0'],
            'itens.*.desconto' => ['nullable', 'numeric', 'min:0'],
            'itens.*.acrescimo' => ['nullable', 'numeric', 'min:0'],
            'itens.*.total' => ['nullable', 'numeric', 'min:0'],
            'itens.*.ordem' => ['nullable', 'integer', 'min:0'],
            'itens.*.observacoes' => ['nullable', 'string'],
        ];
    }
}
