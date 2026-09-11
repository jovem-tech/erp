<?php

namespace App\Rules;

use App\Services\Photos\OperationalPhotoInspector;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

final class OperationalPhotoUpload implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('A foto enviada é inválida.');

            return;
        }

        $path = $value->getRealPath();
        if ($path === false) {
            $fail('A foto enviada não pôde ser lida.');

            return;
        }

        $mimeType = OperationalPhotoInspector::detectMimeType($path);
        $extension = strtolower($value->getClientOriginalExtension());

        if (! OperationalPhotoInspector::extensionMatchesMime($extension, $mimeType)) {
            $fail('Envie uma foto JPEG, PNG, WebP, AVIF, HEIC ou HEIF com extensão compatível com o conteúdo.');
        }
    }
}
