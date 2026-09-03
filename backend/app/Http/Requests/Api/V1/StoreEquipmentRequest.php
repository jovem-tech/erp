<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class StoreEquipmentRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        // `boolean()` (e nao `required_unless`) porque o cadastro chega como
        // multipart do desktop e como JSON de outros clientes: "1", "true", 1 e
        // true precisam valer igual.
        $pendingRegistration = $this->boolean('cadastro_pendente');

        return [
            'cliente_id' => ['required', 'integer', 'min:1', Rule::exists('clientes', 'id')],
            'tipo_id' => ['required', 'integer', 'min:1', Rule::exists('equipamentos_tipos', 'id')],
            'marca_id' => ['required', 'integer', 'min:1', Rule::exists('equipamentos_marcas', 'id')],
            'modelo_id' => ['required', 'integer', 'min:1', Rule::exists('equipamentos_modelos', 'id')],
            'cor' => ['nullable', 'string', 'max:50'],
            'cor_hex' => ['nullable', 'string', 'max:7'],
            'cor_rgb' => ['nullable', 'string', 'max:30'],
            'numero_serie' => ['nullable', 'string', 'max:100'],
            'imei' => ['nullable', 'string', 'max:20'],
            'senha_tipo' => ['nullable', 'string', Rule::in(['desenho', 'texto'])],
            'senha_acesso' => ['nullable', 'string', 'max:255'],
            'senha_desenho' => ['nullable', 'string', 'max:255'],
            'estado_fisico' => ['nullable', 'string'],
            'acessorios' => ['prohibited'],
            'observacoes' => ['nullable', 'string'],
            'desktop_modalidade' => ['nullable', 'string', Rule::in(['montado', 'oem'])],
            'gabinete_tipo' => ['nullable', 'string', 'max:120'],
            'gabinete_identificacao_status' => ['nullable', 'string', Rule::in(['a_confirmar', 'manual', 'detectado'])],
            'gabinete_observacao' => ['nullable', 'string'],
            'placa_mae' => ['nullable', 'string', 'max:255'],
            'chipset' => ['nullable', 'string', 'max:255'],
            'processador' => ['nullable', 'string', 'max:255'],
            'memoria_ram' => ['nullable', 'string', 'max:255'],
            'armazenamento' => ['nullable', 'string', 'max:255'],
            'placa_video' => ['nullable', 'string', 'max:255'],
            'fonte_alimentacao' => ['nullable', 'string', 'max:255'],
            'status_operacional' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', 'string', 'max:20'],
            'foto_principal_index' => ['nullable', 'integer', 'min:0', 'max:3'],
            'collector_pairing_code' => ['nullable', 'string', 'max:32'],
            // Equipamento orcado com o aparelho ainda em casa: a foto de perfil
            // e' a unica exigencia do balcao que nao da para cumprir. O cadastro
            // entra marcado como pendente e a OS desse equipamento fica travada
            // ate a foto chegar (EquipmentWorkflowService reavalia a marca a
            // cada atualizacao).
            'cadastro_pendente' => ['nullable', 'boolean'],
            'fotos' => $pendingRegistration
                ? ['nullable', 'array', 'max:4']
                : ['required', 'array', 'min:1', 'max:4'],
            'fotos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'acessorios.prohibited' => 'Registre os acessórios recebidos na ordem de serviço, não no equipamento.',
            'fotos.required' => 'Envie ao menos uma foto do equipamento.',
            'marca_id.required' => 'Selecione uma marca para o equipamento.',
            'modelo_id.required' => 'Selecione um modelo para o equipamento.',
        ];
    }
}
