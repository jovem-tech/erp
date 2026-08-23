<?php

namespace App\Http\Requests\Api\V1;

use App\Models\FinanceiroConta;
use App\Models\FinanceiroFormaPagamento;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpsertFinanceiroContaRequest extends BaseApiFormRequest
{
    protected function prepareForValidation(): void
    {
        // <select> vazio chega como '' e reprovaria no Rule::in(['inter']).
        // Vazio significa "sem vinculo", que e' null.
        foreach (['integracao_provider', 'integracao_conta_ref'] as $campo) {
            if ($this->has($campo) && trim((string) $this->input($campo)) === '') {
                $this->merge([$campo => null]);
            }
        }
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $contaId = $this->route('conta')?->id;

        return [
            'nome' => [$required, 'string', 'max:100', Rule::unique('financeiro_contas', 'nome')->ignore($contaId)],
            'tipo' => [$required, 'string', Rule::in(FinanceiroConta::typeValues())],
            'instituicao' => ['nullable', 'string', 'max:100'],
            'data_inicio_controle' => [$required, 'date', 'before_or_equal:today'],
            'saldo_inicial' => ['nullable', 'numeric', 'min:-999999999999.99', 'max:999999999999.99'],
            'considera_disponivel' => ['nullable', 'boolean'],
            'ativo' => ['nullable', 'boolean'],
            'cor' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
            // Vinculo com a integracao bancaria. So' faz sentido em conta de
            // tipo `banco`; a validacao cruzada fica no withValidator abaixo.
            'integracao_provider' => ['nullable', 'string', Rule::in(['inter'])],
            'integracao_conta_ref' => ['nullable', 'string', 'max:30'],
            'formas_padrao' => ['nullable', 'array'],
            // Coluna varchar: aceita o catálogo inteiro, inclusive formas novas.
            'formas_padrao.*' => ['string', Rule::in(FinanceiroFormaPagamento::validCodes())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $provider = trim((string) $this->input('integracao_provider', ''));

            if ($provider === '') {
                return;
            }

            // Vincular caixa fisico ou maquininha a uma conta bancaria daria
            // uma conciliacao que nunca fecha, por construcao.
            $tipo = trim((string) ($this->input('tipo') ?? $this->route('conta')?->tipo ?? ''));

            if ($tipo !== FinanceiroConta::TIPO_BANCO) {
                $validator->errors()->add(
                    'integracao_provider',
                    'Só é possível vincular integração bancária a conta do tipo banco.'
                );
            }
        });
    }
}
