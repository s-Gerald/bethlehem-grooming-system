<?php

namespace App\Http\Requests;

use App\Models\ClinicVisitGroup;
use App\Support\ClinicConcerns;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClinicPreRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'customer';
    }

    public function rules(): array
    {
        if ($this->exists('patients')) {
            $rules = [
                'service_day' => ['nullable', 'date_format:Y-m-d'],
                'patients' => ['required', 'array', 'list', 'min:1', 'max:'.ClinicVisitGroup::MAX_PATIENTS],
            ];
            $patients = $this->input('patients');
            if (is_array($patients) && array_is_list($patients) && count($patients) <= ClinicVisitGroup::MAX_PATIENTS) {
                $rules['patients.*.pet_id'] = ['required', 'integer', 'distinct'];
                foreach ($patients as $index => $patient) {
                    $rules["patients.{$index}"] = ['required', 'array'];
                    foreach (ClinicConcerns::rules() as $field => $fieldRules) {
                        $rules["patients.{$index}.{$field}"] = $fieldRules;
                    }
                }
            }

            return $rules;
        }

        return [
            'service_day' => ['nullable', 'date_format:Y-m-d'],
            'pet_id' => [
                'required',
                'integer',
                Rule::exists('pets', 'pet_id')->where(
                    fn ($query) => $query
                        ->where('user_id', $this->user()?->user_id)
                        ->where('is_archived', false),
                ),
            ],
            ...ClinicConcerns::rules(),
        ];
    }

    public function messages(): array
    {
        return [
            'pet_id.exists' => 'Select one of your active pets before continuing.',
            'common_concerns.required' => 'Select at least one common concern.',
            'common_concerns.min' => 'Select at least one common concern.',
            'common_concerns.*.in' => 'Select a valid common concern.',
        ];
    }
}
