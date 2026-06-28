<?php

namespace App\Http\Requests\Api\V1;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'frontend' => ['nullable', 'string', 'in:desktop'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'O e-mail é obrigatório.',
            'email.email' => 'Informe um e-mail válido.',
            'email.max' => 'O e-mail informado é muito longo.',
            'frontend.in' => 'O canal de recuperação de senha informado não é válido.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error(
                'Não foi possível validar o pedido de redefinição de senha.',
                422,
                'AUTH_PASSWORD_RESET_VALIDATION',
                $validator->errors()->toArray(),
                [],
                $this
            )
        );
    }
}
