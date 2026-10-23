<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ProviderCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'max:150'],
            'provider_reference' => ['required', 'string', 'max:100'],
            'status' => ['required', 'string', 'in:ISSUED,REJECTED,FAILED'],
            'event_type' => ['nullable', 'string', 'max:100'],
            'policy_number' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'error' => 'Validation Failed',
            'message' => 'The given callback data was invalid.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
