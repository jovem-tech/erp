<?php

namespace App\Http\Requests\Api\V1;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class BaseApiFormRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error(
                'Falha na validação dos dados enviados.',
                422,
                'VALIDATION_ERROR',
                $validator->errors()->toArray(),
                [],
                $this
            )
        );
    }
}
