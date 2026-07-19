<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Financeiro;
use App\Models\FinanceiroCartaoTaxa;
use App\Models\OrderStatus;
use Illuminate\Validation\Rule;

class CloseOrderRequest extends BaseApiFormRequest
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
        $isBaixa = $this->classificacaoBaixa() === 'baixa';

        return [
            // Decide o caminho: 'baixa' (padrao, fecha a OS de verdade via
            // OrderClosureService::close()) ou 'adiantamento'/'sinal' (so
            // registra o valor no financeiro da OS, via ::registerAdvance() —
            // nunca aplica um dos 3 OrderStatus::closureCodes()). Ver skill
            // sistema-erp-os-fluxo-fechamento.
            'classificacao_baixa' => [
                'nullable',
                'string',
                Rule::in(['baixa', 'adiantamento', 'sinal']),
            ],
            'encerrar_como' => [
                Rule::requiredIf($isBaixa),
                'nullable',
                'string',
                'max:80',
                Rule::in($this->closureStatusCodes()),
            ],
            // Obrigatorio quando e' uma Baixa de verdade, e tambem quando for
            // Adiantamento/Sinal mas o equipamento foi marcado como entregue
            // (nesse caso o status vira entregue_pagamento_pendente).
            'data_entrega' => [
                Rule::requiredIf($isBaixa || $this->boolean('equipamento_entregue')),
                'nullable',
                'date',
            ],
            'equipamento_entregue' => [
                'nullable',
                'boolean',
            ],
            'observacao' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'notificar_cliente' => [
                'nullable',
                'boolean',
            ],
            'agendar_retorno' => [
                'nullable',
                'boolean',
            ],
            'retorno_data' => [
                'nullable',
                'date',
            ],
            // Fora de uma Baixa de verdade, precisa lancar pelo menos um valor
            // (adiantamento/sinal sem nenhum recebimento nao faz sentido).
            'recebimentos' => $isBaixa
                ? ['nullable', 'array']
                : ['required', 'array', 'min:1'],
            'recebimentos.*.valor' => [
                'required',
                'numeric',
                'min:0.01',
            ],
            'recebimentos.*.forma_pagamento' => [
                'required',
                'string',
                Rule::in(Financeiro::FORMAS_PAGAMENTO),
            ],
            'recebimentos.*.conta_financeira_id' => [
                'nullable',
                'integer',
                Rule::exists('financeiro_contas', 'id'),
            ],
            'recebimentos.*.data_pagamento' => [
                'nullable',
                'date',
            ],
            'recebimentos.*.observacoes' => [
                'nullable',
                'string',
                'max:2000',
            ],
            // Campos de cartao: validados quanto ao tipo aqui; a obrigatoriedade
            // condicional ("operadora_id e obrigatorio quando forma_pagamento e
            // cartao") fica a cargo de FinanceiroCartaoService::simulate(), que
            // ja existe e e a fonte unica dessa regra (evita duplicar validacao
            // de negocio no FormRequest e no service).
            'recebimentos.*.operadora_id' => ['nullable', 'integer', 'min:1'],
            'recebimentos.*.bandeira_id' => ['nullable', 'integer', 'min:1'],
            'recebimentos.*.modalidade' => ['nullable', 'string', Rule::in([
                FinanceiroCartaoTaxa::MODALIDADE_CREDITO,
                FinanceiroCartaoTaxa::MODALIDADE_DEBITO,
            ])],
            'recebimentos.*.parcelas' => ['nullable', 'integer', 'min:1', 'max:99'],
        ];
    }

    private function classificacaoBaixa(): string
    {
        $value = trim((string) $this->input('classificacao_baixa', 'baixa'));

        return in_array($value, ['baixa', 'adiantamento', 'sinal'], true) ? $value : 'baixa';
    }

    /**
     * @return array<int, string>
     */
    private function closureStatusCodes(): array
    {
        return OrderStatus::closureCodes();
    }
}
