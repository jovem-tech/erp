<?php

namespace App\Http\Requests\Api\V1;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ResetPasswordRequest extends FormRequest
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
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => 'O token de redefinição é obrigatório.',
            'token.string' => 'O token de redefinição deve ser um texto válido.',
            'token.max' => 'O token de redefinição é muito longo.',
            'email.required' => 'O e-mail é obrigatório.',
            'email.email' => 'Informe um e-mail válido.',
            'email.max' => 'O e-mail informado é muito longo.',
            'password.required' => 'A nova senha é obrigatória.',
            'password.string' => 'A nova senha deve ser um texto válido.',
            'password.min' => 'A nova senha deve ter pelo menos 8 caracteres.',
            'password.max' => 'A nova senha é muito longa.',
            'password.confirmed' => 'A confirmação da nova senha não confere.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error(
                'Não foi possível validar a redefinição de senha.',
                422,
                'AUTH_PASSWORD_RESET_VALIDATION',
                $validator->errors()->toArray(),
                [],
                $this
            )
        );
    }
}
