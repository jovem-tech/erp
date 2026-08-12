<?php

namespace App\Http\Requests\Api\V1;

use App\Models\FinanceiroCartaoTaxa;
use App\Models\FinanceiroFormaPagamento;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Validation\Rule;

/**
 * Criação de venda de balcão — specs/027-vendas-balcao-pdv/spec.md.
 *
 * Só POST: venda concluída é imutável, não há update.
 */
class StoreSaleRequest extends BaseApiFormRequest
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
            // Duplo clique em "Finalizar" é o defeito nº 1 de um PDV: a chave
            // permite reconhecer o replay e devolver a mesma venda.
            'creation_request_id' => ['nullable', 'uuid'],

            'cliente_id' => ['nullable', 'integer', Rule::exists('clientes', 'id')],
            // Consumidor final: sem cliente cadastrado, o nome é livre e
            // opcional (o serviço aplica "Consumidor final" como padrão).
            'cliente_nome_avulso' => ['nullable', 'string', 'max:160'],
            'cliente_documento_avulso' => ['nullable', 'string', 'max:20'],
            'telefone_contato' => ['nullable', 'string', 'max:30'],
            'email_contato' => ['nullable', 'email', 'max:120'],

            'vendedor_id' => ['nullable', 'integer', Rule::exists('usuarios', 'id')],
            'os_id' => ['nullable', 'integer', Rule::exists('os', 'id')],
            'data_venda' => ['nullable', 'date'],
            'observacoes' => ['nullable', 'string', 'max:2000'],

            // Confirmação explícita de venda com saldo insuficiente. Sem ela o
            // serviço devolve 422 com a lista de itens em falta, para o PDV
            // destacar as linhas e perguntar ao operador.
            'confirmar_estoque_insuficiente' => ['nullable', 'boolean'],

            // Ajuste geral, em R$ ou %.
            'desconto' => ['nullable', 'numeric', 'min:0'],
            'desconto_tipo' => ['nullable', 'string', Rule::in(Sale::adjustmentModes())],
            'desconto_percentual' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'acrescimo' => ['nullable', 'numeric', 'min:0'],
            'acrescimo_tipo' => ['nullable', 'string', Rule::in(Sale::adjustmentModes())],
            'acrescimo_percentual' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'itens' => ['required', 'array', 'min:1'],
            'itens.*.tipo_item' => ['required', 'string', Rule::in(SaleItem::types())],
            'itens.*.referencia_id' => ['nullable', 'integer', 'min:1'],
            'itens.*.descricao' => ['nullable', 'string', 'max:255'],
            'itens.*.quantidade' => ['required', 'numeric', 'min:0.001'],
            'itens.*.valor_unitario' => ['nullable', 'numeric', 'min:0'],
            'itens.*.custo_unitario' => ['nullable', 'numeric', 'min:0'],
            // Flag explícita: permite vender peça cadastrada sem mexer no saldo
            // (brinde, consignado, saldo sabidamente divergente).
            'itens.*.baixa_estoque' => ['nullable', 'boolean'],
            'itens.*.desconto' => ['nullable', 'numeric', 'min:0'],
            'itens.*.desconto_tipo' => ['nullable', 'string', Rule::in(Sale::adjustmentModes())],
            'itens.*.desconto_percentual' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'itens.*.acrescimo' => ['nullable', 'numeric', 'min:0'],
            'itens.*.acrescimo_tipo' => ['nullable', 'string', Rule::in(Sale::adjustmentModes())],
            'itens.*.acrescimo_percentual' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'itens.*.observacoes' => ['nullable', 'string', 'max:1000'],

            // Nullable de propósito: venda fiada é permitida, o título fica
            // pendente/parcial e é cobrado como qualquer outro.
            'pagamentos' => ['nullable', 'array'],
            'pagamentos.*.valor' => ['required', 'numeric', 'min:0.01'],
            'pagamentos.*.forma_pagamento' => [
                'required',
                'string',
                // Vai para colunas varchar (venda_pagamentos e
                // financeiro_movimentos), então aceita o catálogo inteiro,
                // inclusive formas personalizadas. O ENUM travado de
                // `financeiro.forma_pagamento` não é tocado: o título da venda
                // nasce sem forma, porque em pagamento misto não existe "a" forma.
                Rule::in(FinanceiroFormaPagamento::validCodes()),
            ],
            'pagamentos.*.valor_recebido' => ['nullable', 'numeric', 'min:0'],
            'pagamentos.*.conta_financeira_id' => [
                'nullable',
                'integer',
                Rule::exists('financeiro_contas', 'id'),
            ],
            'pagamentos.*.data_pagamento' => ['nullable', 'date'],
            'pagamentos.*.observacoes' => ['nullable', 'string', 'max:2000'],
            // Campos de cartão: aqui só o tipo. A obrigatoriedade condicional
            // ("operadora_id é obrigatório quando a forma é cartão") fica a
            // cargo de FinanceiroCartaoService::simulate(), fonte única da regra.
            'pagamentos.*.operadora_id' => ['nullable', 'integer', 'min:1'],
            'pagamentos.*.bandeira_id' => ['nullable', 'integer', 'min:1'],
            'pagamentos.*.modalidade' => ['nullable', 'string', Rule::in([
                FinanceiroCartaoTaxa::MODALIDADE_CREDITO,
                FinanceiroCartaoTaxa::MODALIDADE_DEBITO,
            ])],
            'pagamentos.*.parcelas' => ['nullable', 'integer', 'min:1', 'max:99'],

            // Derivados pelo servidor: aceitar do cliente permitiria forjar
            // total, margem ou numeração.
            'numero' => ['prohibited'],
            'status' => ['prohibited'],
            'subtotal' => ['prohibited'],
            'total' => ['prohibited'],
            'custo_total' => ['prohibited'],
            'margem_valor' => ['prohibited'],
            'valor_pago' => ['prohibited'],
            'status_pagamento' => ['prohibited'],
            'criado_por' => ['prohibited'],
            'financeiro_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cliente_id' => 'cliente',
            'cliente_nome_avulso' => 'nome do cliente',
            'cliente_documento_avulso' => 'CPF/CNPJ',
            'vendedor_id' => 'vendedor',
            'data_venda' => 'data da venda',
            'itens' => 'itens',
            'itens.*.tipo_item' => 'tipo do item',
            'itens.*.descricao' => 'descrição do item',
            'itens.*.quantidade' => 'quantidade',
            'itens.*.valor_unitario' => 'valor unitário',
            'pagamentos' => 'pagamentos',
            'pagamentos.*.valor' => 'valor do pagamento',
            'pagamentos.*.forma_pagamento' => 'forma de pagamento',
            'pagamentos.*.parcelas' => 'parcelas',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'itens.required' => 'Inclua ao menos um item na venda.',
            'itens.min' => 'Inclua ao menos um item na venda.',
            'pagamentos.*.forma_pagamento.in' => 'Selecione uma forma de pagamento válida.',
        ];
    }
}
