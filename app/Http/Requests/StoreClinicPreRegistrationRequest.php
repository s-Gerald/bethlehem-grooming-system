<?php

namespace App\Http\Requests;

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
            'common_concerns' => ['required', 'array', 'min:1', 'max:11'],
            'common_concerns.*' => ['required', 'string', 'distinct', Rule::in(ClinicConcerns::OPTIONS)],
            'chief_complaint' => ['nullable', 'string', 'max:1000'],
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
