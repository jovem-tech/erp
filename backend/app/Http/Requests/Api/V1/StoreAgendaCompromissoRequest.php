<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AgendaCompromisso;
use Illuminate\Validation\Rule;

class StoreAgendaCompromissoRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'titulo' => [$required, 'string', 'max:180'],
            'descricao' => ['nullable', 'string', 'max:5000'],
            'inicio_em' => [$required, 'date'],
            'fim_em' => ['nullable', 'date'],
            'dia_inteiro' => ['nullable', 'boolean'],
            'prioridade' => ['nullable', 'string', Rule::in(AgendaCompromisso::PRIORIDADES)],
            'responsavel_id' => ['nullable', 'integer', 'exists:usuarios,id'],
            'cliente_id' => ['nullable', 'integer'],
            'os_id' => ['nullable', 'integer'],
            // Teto de 4 semanas: o Google recusa lembretes acima de 40320
            // minutos e devolveria 400 no push.
            'lembrete_minutos' => ['nullable', 'integer', 'min:0', 'max:40320'],
        ];
    }
}
