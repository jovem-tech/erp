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
            'numero' => ['sometimes', 'nullable', 'string', 'max:40'],
            'versao' => ['sometimes', 'integer', 'min:1'],
            'tipo_orcamento' => ['nullable', 'string', 'max:30', Rule::in(array_column(Budget::typeOptions(), 'value'))],
            // "pendente_abertura_os" só nasce da aprovação formal e
            // "convertido" só nasce da criação atômica da OS. Os demais
            // estados continuam compatíveis com o fluxo operacional legado.
            'status' => [
                'nullable',
                'string',
                'max:40',
                Rule::in(array_values(array_diff(
                    array_column(Budget::statusOptions(), 'value'),
                    [Budget::STATUS_PENDING_OS, Budget::STATUS_CONVERTED]
                ))),
            ],
            'origem' => ['nullable', 'string', 'max:40', Rule::in(array_column(Budget::originOptions(), 'value'))],
            'cliente_id' => [$requiredOrSometimes, 'nullable', 'integer', 'min:1', Rule::exists('clientes', 'id')],
            'contato_id' => ['nullable', 'integer', 'min:1'],
            'cliente_nome_avulso' => ['nullable', 'string', 'max:160'],
            'telefone_contato' => ['nullable', 'string', 'max:30'],
            'email_contato' => ['nullable', 'email', 'max:120'],
            'os_id' => ['nullable', 'integer', 'min:1', Rule::exists('os', 'id')],
            'equipamento_id' => ['nullable', 'integer', 'min:1', Rule::exists('equipamentos', 'id')],
            'envolve_equipamento' => ['nullable', 'boolean'],
            'equipamento_tipo_id' => ['nullable', 'integer', 'min:1'],
            'equipamento_marca_id' => ['nullable', 'integer', 'min:1'],
            'equipamento_modelo_id' => ['nullable', 'integer', 'min:1'],
            // Equipamento eventual (aparelho sem cadastro) — texto livre, espelha
            // cliente_nome_avulso. A obrigatoriedade condicional fica na camada
            // desktop (OrcamentoController::validatedBudgetPayload).
            'equipamento_tipo_avulso' => ['nullable', 'string', 'max:120'],
            'equipamento_marca_avulso' => ['nullable', 'string', 'max:120'],
            'equipamento_modelo_avulso' => ['nullable', 'string', 'max:120'],
            'equipamento_cor' => ['nullable', 'string', 'max:100'],
            'equipamento_cor_hex' => ['nullable', 'string', 'max:7'],
            'equipamento_cor_rgb' => ['nullable', 'string', 'max:32'],
            'conversa_id' => ['nullable', 'integer', 'min:1'],
            'responsavel_id' => ['nullable', 'integer', 'min:1', Rule::exists('usuarios', 'id')],
            'criado_por' => ['prohibited'],
            'atualizado_por' => ['prohibited'],
            'titulo' => ['nullable', 'string', 'max:180'],
            'relato_cliente' => ['nullable', 'string'],
            'validade_dias' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'validade_data' => ['nullable', 'date'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
            'desconto' => ['nullable', 'numeric', 'min:0'],
            'desconto_tipo' => ['nullable', 'string', Rule::in([Budget::ADJUSTMENT_MODE_VALUE, Budget::ADJUSTMENT_MODE_PERCENT])],
            'desconto_percentual' => ['nullable', 'numeric', 'min:0'],
            'acrescimo' => ['nullable', 'numeric', 'min:0'],
            'acrescimo_tipo' => ['nullable', 'string', Rule::in([Budget::ADJUSTMENT_MODE_VALUE, Budget::ADJUSTMENT_MODE_PERCENT])],
            'acrescimo_percentual' => ['nullable', 'numeric', 'min:0'],
            'total' => ['nullable', 'numeric', 'min:0'],
            'prazo_execucao' => ['nullable', 'string', 'max:120'],
            'observacoes' => ['nullable', 'string'],
            'condicoes' => ['nullable', 'string'],
            // Condições comerciais estruturadas. Códigos fora do catálogo ativo
            // e prazos fora da lista são descartados em
            // BudgetCommercialTermsService, não rejeitam a requisição inteira.
            'formas_pagamento' => ['nullable', 'array'],
            'formas_pagamento.*' => ['string', 'max:40'],
            'garantia_dias' => ['nullable', 'integer', Rule::in(array_keys(Budget::WARRANTY_TERMS))],
            'parcelas_sem_juros' => ['nullable', 'integer', 'min:2', 'max:'.Budget::MAX_INTEREST_FREE_INSTALLMENTS],
            'token_publico' => ['prohibited'],
            'token_expira_em' => ['prohibited'],
            'enviado_em' => ['prohibited'],
            'aprovado_em' => ['prohibited'],
            'rejeitado_em' => ['prohibited'],
            'cancelado_em' => ['prohibited'],
            'motivo_rejeicao' => ['prohibited'],
            'convertido_tipo' => ['prohibited'],
            'convertido_id' => ['prohibited'],
            'itens' => ['nullable', 'array'],
            'itens.*.tipo_item' => ['nullable', 'string', 'max:30'],
            'itens.*.referencia_id' => ['nullable', 'integer', 'min:1'],
            'itens.*.descricao' => ['required_with:itens', 'string', 'max:255'],
            'itens.*.quantidade' => ['nullable', 'numeric', 'min:0'],
            'itens.*.valor_unitario' => ['nullable', 'numeric', 'min:0'],
            'itens.*.desconto' => ['nullable', 'numeric', 'min:0'],
            'itens.*.desconto_tipo' => ['nullable', 'string', Rule::in([Budget::ADJUSTMENT_MODE_VALUE, Budget::ADJUSTMENT_MODE_PERCENT])],
            'itens.*.desconto_percentual' => ['nullable', 'numeric', 'min:0'],
            'itens.*.acrescimo' => ['nullable', 'numeric', 'min:0'],
            'itens.*.acrescimo_tipo' => ['nullable', 'string', Rule::in([Budget::ADJUSTMENT_MODE_VALUE, Budget::ADJUSTMENT_MODE_PERCENT])],
            'itens.*.acrescimo_percentual' => ['nullable', 'numeric', 'min:0'],
            'itens.*.total' => ['nullable', 'numeric', 'min:0'],
            'itens.*.ordem' => ['nullable', 'integer', 'min:0'],
            'itens.*.observacoes' => ['nullable', 'string'],
            // Só usados quando a OS vinculada já está encerrada — ver
            // BudgetWorkflowService::isOrderClosed()/AdminCredentialVerifier.
            'admin_email' => ['nullable', 'string', 'email'],
            'admin_password' => ['nullable', 'string'],
            // Confirma que uma mudança de valor/cliente num orçamento
            // convertido deve virar uma revisão pendente de aprovação do
            // cliente — ver BudgetWorkflowService::updateConvertedBudget()/
            // BudgetRevisionService.
            'propor_revisao' => ['nullable', 'boolean'],
        ];
    }
}
