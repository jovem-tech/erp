<?php

namespace App\Http\Requests\Api\V1;

use App\Models\EquipmentType;
use App\Services\EquipmentWorkflowService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEquipmentRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        return [
            'cliente_id' => ['required', 'integer', 'min:1', Rule::exists('clientes', 'id')],
            'tipo_id' => ['required', 'integer', 'min:1', Rule::exists('equipamentos_tipos', 'id')],
            'marca_id' => ['nullable', 'integer', 'min:1', Rule::exists('equipamentos_marcas', 'id')],
            'modelo_id' => ['nullable', 'integer', 'min:1', Rule::exists('equipamentos_modelos', 'id')],
            'cor' => ['nullable', 'string', 'max:50'],
            'cor_hex' => ['nullable', 'string', 'max:7'],
            'cor_rgb' => ['nullable', 'string', 'max:30'],
            'numero_serie' => ['nullable', 'string', 'max:100'],
            'imei' => ['nullable', 'string', 'max:20'],
            'senha_tipo' => ['nullable', 'string', Rule::in(['desenho', 'texto'])],
            'senha_acesso' => ['nullable', 'string', 'max:255'],
            'senha_desenho' => ['nullable', 'string', 'max:255'],
            'estado_fisico' => ['nullable', 'string'],
            'acessorios' => ['nullable', 'string'],
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
            'fotos' => ['required', 'array', 'min:1', 'max:4'],
            'fotos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $typeId = (int) $this->input('tipo_id', 0);
            if ($typeId <= 0) {
                return;
            }

            $typeName = trim((string) (EquipmentType::query()->find($typeId)?->nome ?? ''));
            $family = EquipmentWorkflowService::resolveTypeFamily($typeName);

            // Os defaults de catálogo do "Desktop montado" só existem para o tipo Desktop.
            // Notebook é sempre OEM/fabricante e exige marca e modelo reais do catálogo.
            $desktopMode = trim((string) $this->input('desktop_modalidade', ''));
            $allowCatalogDefaults = $family === 'desktop' && ($desktopMode === '' || $desktopMode === 'montado');

            if (! $allowCatalogDefaults && ! $this->filled('marca_id')) {
                $validator->errors()->add('marca_id', 'Selecione uma marca para o equipamento.');
            }

            if (! $allowCatalogDefaults && ! $this->filled('modelo_id')) {
                $validator->errors()->add('modelo_id', 'Selecione um modelo para o equipamento.');
            }
        });
    }
}
