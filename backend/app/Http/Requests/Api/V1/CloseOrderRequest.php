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
        return [
            'encerrar_como' => [
                'required',
                'string',
                'max:80',
                Rule::in($this->closureStatusCodes()),
            ],
            'data_entrega' => [
                'required',
                'date',
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
            'recebimentos' => [
                'nullable',
                'array',
            ],
            'recebimentos.*.valor' => [
                'required',
                'numeric',
                'min:0.01',
            ],
            'recebimentos.*.classificacao_recebimento' => [
                'nullable',
                'string',
                Rule::in(['baixa', 'adiantamento', 'sinal']),
            ],
            'recebimentos.*.forma_pagamento' => [
                'nullable',
                'string',
                Rule::in(Financeiro::FORMAS_PAGAMENTO),
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

    /**
     * @return array<int, string>
     */
    private function closureStatusCodes(): array
    {
        return OrderStatus::query()
            ->active()
            ->where('status_final', true)
            ->pluck('codigo')
            ->map(static fn ($code): string => trim((string) $code))
            ->filter(static fn (string $code): bool => $code !== '')
            ->values()
            ->all();
    }
}
