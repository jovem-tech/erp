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
            'sistema_nome' => ['nullable', 'string', 'max:120'],
            'empresa_razao_social' => ['nullable', 'string', 'max:255'],
            'empresa_nome_fantasia' => ['nullable', 'string', 'max:255'],
            'empresa_cnpj' => ['nullable', 'string', 'max:32'],
            'empresa_inscricao_estadual' => ['nullable', 'string', 'max:32'],
            'empresa_telefone' => ['nullable', 'string', 'max:30'],
            'empresa_email' => ['nullable', 'string', 'email', 'max:255'],
            'empresa_endereco' => ['nullable', 'string', 'max:255'],
            'empresa_logradouro' => ['nullable', 'string', 'max:255'],
            'empresa_numero' => ['nullable', 'string', 'max:20'],
            'empresa_complemento' => ['nullable', 'string', 'max:60'],
            'empresa_bairro' => ['nullable', 'string', 'max:60'],
            'empresa_cidade' => ['nullable', 'string', 'max:60'],
            'empresa_uf' => ['nullable', 'string', 'size:2'],
            'empresa_cep' => ['nullable', 'string', 'max:10'],
            'empresa_codigo_ibge' => ['nullable', 'string', 'size:7'],
            'empresa_inscricao_municipal' => ['nullable', 'string', 'max:32'],
            'empresa_cnae' => ['nullable', 'string', 'max:16'],
            'empresa_codigo_tributacao_nacional' => ['nullable', 'string', 'max:20'],
            'empresa_logo' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:4096',
            ],
            'login_background_image' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:4096',
            ],
        ];
    }
}
