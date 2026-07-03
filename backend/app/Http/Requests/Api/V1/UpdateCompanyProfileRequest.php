<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyProfileRequest extends FormRequest
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
            'empresa_razao_social' => ['nullable', 'string', 'max:255'],
            'empresa_nome_fantasia' => ['nullable', 'string', 'max:255'],
            'empresa_cnpj' => ['nullable', 'string', 'max:32'],
            'empresa_inscricao_estadual' => ['nullable', 'string', 'max:32'],
            'empresa_telefone' => ['nullable', 'string', 'max:30'],
            'empresa_email' => ['nullable', 'string', 'email', 'max:255'],
            'empresa_endereco' => ['nullable', 'string', 'max:255'],
            'empresa_logo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif,svg', 'max:4096'],
        ];
    }
}
