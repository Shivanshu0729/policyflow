<?php

namespace App\Http\Requests;

use App\Enums\Destination;
use App\Enums\PlanCode;
use App\Enums\SumInsured;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StorePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_request_id' => ['required', 'string', 'max:100'],
            'agent_id' => ['required', 'string', 'max:100'],
            'customer_name' => ['required', 'string', 'max:150', 'min:2'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before:today'],
            'destination' => ['required', 'string', Rule::in(Destination::values())],
            'trip_start' => ['required', 'date_format:Y-m-d'],
            'trip_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:trip_start'],
            'plan_code' => ['required', 'string', Rule::in(PlanCode::values())],
            'sum_insured' => ['required', 'integer', Rule::in(SumInsured::values())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $dob = $this->input('date_of_birth');
            $tripStart = $this->input('trip_start');

            if ($dob && $tripStart) {
                try {
                    $dobCarbon = Carbon::parse($dob);
                    $tripStartCarbon = Carbon::parse($tripStart);

                    if ($dobCarbon->gt($tripStartCarbon)) {
                        $validator->errors()->add('date_of_birth', 'Date of birth must be prior to the trip start date.');
                    }

                    $age = $dobCarbon->diffInYears($tripStartCarbon);
                    if ($age > 120) {
                        $validator->errors()->add('date_of_birth', 'Customer age exceeds maximum allowable limit (120 years).');
                    }
                } catch (\Exception $e) {
                    $validator->errors()->add('date_of_birth', 'Invalid date format.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'destination.in' => 'Unsupported destination. Allowed values are DOMESTIC or INTERNATIONAL.',
            'plan_code.in' => 'Unsupported plan code. Allowed values are BASIC, PLUS, or PREMIUM.',
            'sum_insured.in' => 'Unsupported sum insured value. Allowed coverage values are 100000, 300000, or 500000.',
            'trip_end.after_or_equal' => 'trip_end must be on or after trip_start.',
            'date_of_birth.before' => 'date_of_birth must be a date in the past.',
            'date_of_birth.date_format' => 'date_of_birth must follow YYYY-MM-DD format.',
            'trip_start.date_format' => 'trip_start must follow YYYY-MM-DD format.',
            'trip_end.date_format' => 'trip_end must follow YYYY-MM-DD format.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'error' => 'Validation Failed',
            'message' => 'The given data was invalid.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
