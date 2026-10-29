<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreInscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'npi' => ['required', 'string', 'regex:/\A[0-9]{10}\z/'],
            'date_naissance' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'concours' => ['required', 'string', 'exists:concours,code'],
            'centre' => ['required', 'string', 'exists:centres,code'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Les données de l’inscription sont invalides.',
            'errors' => $validator->errors(),
        ], 400));
    }
}
