<?php

namespace App\Http\Requests\Api\V1;

use App\Models\EquipmentPhoto;
use Illuminate\Validation\Validator;

class UpdateEquipmentRequest extends StoreEquipmentRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['fotos'] = ['nullable', 'array', 'max:4'];
        $rules['existing_photo_sync'] = ['nullable', 'boolean'];
        $rules['existing_photo_ids'] = ['nullable', 'array'];
        $rules['existing_photo_ids.*'] = ['integer', 'min:1'];
        $rules['foto_principal_existente_id'] = ['nullable', 'integer', 'min:1'];

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            $equipmentId = (int) $this->route('equipment', 0);
            if ($equipmentId <= 0) {
                return;
            }

            $currentPhotoIds = EquipmentPhoto::query()
                ->where('equipamento_id', $equipmentId)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $syncExistingPhotos = $this->boolean('existing_photo_sync');
            $retainedPhotoIds = $syncExistingPhotos
                ? array_values(array_unique(array_map('intval', (array) $this->input('existing_photo_ids', []))))
                : $currentPhotoIds;

            foreach ($retainedPhotoIds as $photoId) {
                if (! in_array($photoId, $currentPhotoIds, true)) {
                    $validator->errors()->add('existing_photo_ids', 'A lista de fotos existentes contem um arquivo invalido para este equipamento.');
                    break;
                }
            }

            $primaryExistingPhotoId = (int) $this->input('foto_principal_existente_id', 0);
            if ($primaryExistingPhotoId > 0 && ! in_array($primaryExistingPhotoId, $retainedPhotoIds, true)) {
                $validator->errors()->add('foto_principal_existente_id', 'A foto principal selecionada precisa permanecer vinculada ao equipamento.');
            }

            $uploadedCount = count(array_filter(
                (array) $this->file('fotos', []),
                static fn ($file): bool => $file !== null
            ));

            $totalPhotos = count($retainedPhotoIds) + $uploadedCount;

            if ($totalPhotos < 1) {
                $validator->errors()->add('fotos', 'Pelo menos uma foto precisa permanecer vinculada ao equipamento.');
            }

            if ($totalPhotos > 4) {
                $validator->errors()->add('fotos', 'O equipamento pode manter no maximo 4 fotos vinculadas.');
            }
        });
    }
}
