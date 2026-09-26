<?php

namespace App\Http\Requests\Api\V1;

use App\Models\OrderPhoto;
use App\Rules\OperationalPhotoUpload;
use Illuminate\Validation\Rule;

/**
 * Anexo de fotos direto na visualizacao da OS (specs/048), sem passar pela
 * edicao completa. Mesmos limites de `fotos` em UpsertOrderRequest.
 */
class StoreOrderPhotosRequest extends BaseApiFormRequest
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
            'fotos' => ['required', 'array', 'min:1', 'max:4'],
            'fotos.*' => ['file', 'max:20480', new OperationalPhotoUpload],
            'tipo' => ['nullable', 'string', Rule::in(OrderPhoto::TIPOS)],
        ];
    }
}
