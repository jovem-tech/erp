<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Financeiro;
use App\Models\FinanceiroFormaPagamento;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpsertFinanceiroRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        $requiredOrSometimes = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'tipo' => [$requiredOrSometimes, 'string', Rule::in([Financeiro::TIPO_RECEBER, Financeiro::TIPO_PAGAR])],
            'categoria' => [$requiredOrSometimes, 'string', 'max:50'],
            'descricao' => [$requiredOrSometimes, 'string', 'max:255'],
            'valor' => [$requiredOrSometimes, 'numeric', 'min:0.01', 'max:99999999.99'],
            'status' => ['nullable', 'string', Rule::in(array_column(Financeiro::statusOptions(), 'value'))],
            // Grava na coluna-resumo `financeiro.forma_pagamento`, que é um ENUM
            // restrito no banco legado — por isso aceita só as formas marcadas
            // como compatíveis com o resumo, e não o catálogo inteiro.
            'forma_pagamento' => ['nullable', 'string', Rule::in(FinanceiroFormaPagamento::summaryCodes())],
            'conta_financeira_id' => ['nullable', 'integer', Rule::exists('financeiro_contas', 'id')],
            // Compra feita no cartão da assistência. Com cartão vinculado,
            // data_vencimento é ignorada e recalculada pelo ciclo da fatura
            // (ver FinanceiroService::resolveClassification()).
            'cartao_credito_id' => ['nullable', 'integer', Rule::exists('financeiro_cartoes_credito', 'id')],
            'data_compra' => ['nullable', 'date', 'required_with:cartao_credito_id'],
            // Parcelamento da compra no cartão (só crédito). 1 = à vista.
            'parcelas' => ['nullable', 'integer', 'min:1', 'max:36'],
            'data_vencimento' => [$requiredOrSometimes, 'date'],
            'data_pagamento' => ['nullable', 'date'],
            'data_competencia' => ['nullable', 'date'],
            'observacoes' => ['nullable', 'string'],
            'os_id' => ['nullable', 'integer', 'min:1', Rule::exists('os', 'id')],
            'cliente_id' => ['nullable', 'integer', 'min:1', Rule::exists('clientes', 'id')],
            'fornecedor_id' => ['nullable', 'integer', 'min:1'],
            'avulso' => ['nullable', 'boolean'],
            'grupo_dre' => ['nullable', 'string', 'max:60'],
            'subgrupo_dre' => ['nullable', 'string', 'max:80'],
            'impacta_dre' => ['nullable', 'boolean'],
            'impacta_fluxo_caixa' => ['nullable', 'boolean'],
            'dre_fixo_mensal' => ['nullable', 'boolean'],
            'repetir_proximos_meses' => ['nullable', 'boolean'],
            // Peças que esta compra dá entrada no estoque (specs/039). O
            // lançamento e as movimentações nascem na mesma transação — ver
            // FinanceiroService::create().
            'itens_estoque' => ['nullable', 'array', 'max:50'],
            'itens_estoque.*.peca_id' => ['required', 'integer', 'min:1', Rule::exists('pecas', 'id')],
            // min 0.0001 e não 1: quantidade é DECIMAL(14,4) desde a 036 —
            // existe meio metro de cabo flat e 1,5 g de pasta térmica.
            'itens_estoque.*.quantidade' => ['required', 'numeric', 'min:0.0001', 'max:999999'],
            'itens_estoque.*.custo_unitario' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'itens_estoque.*.preco_venda' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }

    /**
     * Regras que dependem de mais de um campo — validação por campo não as pega.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $itens = $this->input('itens_estoque');

            if (! is_array($itens) || $itens === []) {
                return;
            }

            // 1. Só despesa dá entrada no estoque. Receber peça de volta é
            //    devolução/estorno, caminho de outro serviço.
            if ($this->input('tipo') !== Financeiro::TIPO_PAGAR) {
                $validator->errors()->add(
                    'itens_estoque',
                    'Entrada no estoque só vale para lançamento do tipo "a pagar".'
                );
            }

            // 2. Só na criação. Editar itens exigiria diffar entradas já
            //    gravadas e gerar estornos — é o `estorno_de_id` da 036. Recusar
            //    explicitamente, nunca ignorar em silêncio: ignorar faria o
            //    operador acreditar que salvou.
            if (! $this->isMethod('post')) {
                $validator->errors()->add(
                    'itens_estoque',
                    'Não é possível alterar as peças de um lançamento já criado. Cancele o lançamento e refaça.'
                );
            }

            // 3. Repetição mensal gera 12 cópias do título. Doze entradas da
            //    mesma peça, uma por mês, seria estoque fantasma.
            if ($this->boolean('repetir_proximos_meses')) {
                $validator->errors()->add(
                    'itens_estoque',
                    'Uma compra de peça não pode repetir nos próximos meses.'
                );
            }

            // 4. Soma dos itens não pode passar do valor pago.
            //
            //    Tem de ser AQUI e não no serviço: resolveClassification()
            //    substitui `valor` pela 1ª parcela quando parcelas > 1, e depois
            //    dele a comparação estaria contra um número menor.
            //
            //    Soma MENOR é legítima (frete, imposto, item que não é peça) e
            //    passa — a tela avisa. Soma MAIOR significa que se comprou mais
            //    do que se pagou: é digitação errada.
            $soma = 0.0;

            foreach ($itens as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $soma += round((float) ($item['quantidade'] ?? 0), 4)
                    * round((float) ($item['custo_unitario'] ?? 0), 4);
            }

            $valor = round((float) $this->input('valor', 0), 2);

            // Tolerância de 1 centavo: 3 × 33,333... não fecha em ponto flutuante.
            if ($valor > 0 && round($soma, 2) > $valor + 0.01) {
                $validator->errors()->add(
                    'itens_estoque',
                    sprintf(
                        'A soma das peças (R$ %s) é maior que o valor do lançamento (R$ %s).',
                        number_format($soma, 2, ',', '.'),
                        number_format($valor, 2, ',', '.')
                    )
                );
            }
        });
    }
}
